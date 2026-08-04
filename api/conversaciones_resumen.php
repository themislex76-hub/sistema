<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// GET: devuelve el resumen ya generado (si existe), sin llamar a la IA.
// POST: (re)genera el resumen llamando a Claude y lo guarda. Solo
// Administrador — herramienta de control de calidad del bot.
$user = require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $telefono = trim((string)($_GET['telefono'] ?? ''));
    if ($telefono === '') fail('Falta el teléfono.', 400);
    $stmt = $pdo->prepare('SELECT resumen, mensajes_contados, generado_en FROM whatsapp_resumenes WHERE telefono = :t');
    $stmt->execute([':t' => $telefono]);
    $row = $stmt->fetch();
    respond(['resumen' => $row ?: null]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in = json_input();
    $telefono = trim((string)($in['telefono'] ?? ''));
    if ($telefono === '') fail('Falta el teléfono.', 400);

    $stmt = $pdo->prepare('SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC LIMIT 500');
    $stmt->execute([':t' => $telefono]);
    $historial = $stmt->fetchAll();
    if (!$historial) fail('No hay mensajes para este número.', 404);

    $resumen = ia_generar_resumen_conversacion($historial);
    if ($resumen === null) fail('No se pudo generar el resumen. Revisa las credenciales de IA e inténtalo de nuevo.', 502);

    $upsert = $pdo->prepare(
        'INSERT INTO whatsapp_resumenes (telefono, resumen, mensajes_contados) VALUES (:t, :r, :n)
         ON DUPLICATE KEY UPDATE resumen = VALUES(resumen), mensajes_contados = VALUES(mensajes_contados), generado_en = NOW()'
    );
    $upsert->execute([':t' => $telefono, ':r' => $resumen, ':n' => count($historial)]);

    respond(['resumen' => ['resumen' => $resumen, 'mensajes_contados' => count($historial), 'generado_en' => date('c')]]);
}

fail('Método no permitido.', 405);
