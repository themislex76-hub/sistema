<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// "Qué hacer hoy": el frontend ya calculó (buildAgendaEntries() en app.js)
// qué pendientes/vencimientos/alertas tiene el usuario y cuántos días
// faltan o ya se vencieron -- este endpoint solo le pide a la IA que los
// organice en un texto corto y priorizado. Se cachea por usuario según el
// hash del listado que se mandó: si no cambió nada desde la última vez,
// no se vuelve a llamar a la IA (el costo real es una llamada por cambio
// real de pendientes, no una por cada vez que alguien abre el Tablero).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$items = is_array($in['items'] ?? null) ? $in['items'] : [];
$forzar = !empty($in['forzar']);

// Tope de items para no mandar un prompt gigante si un despacho tiene
// muchísimos pendientes -- el frontend ya los manda ordenados por
// urgencia, así que truncar aquí solo descarta los menos urgentes.
$items = array_slice($items, 0, 40);

if (!$items) {
    respond(['resumen' => null, 'vacio' => true]);
}

$hash = hash('sha256', json_encode($items, JSON_UNESCAPED_UNICODE));

$pdo = db();
$stmt = $pdo->prepare('SELECT resumen_hoy_texto, resumen_hoy_hash, resumen_hoy_generado_en FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

if (!$forzar && $row && $row['resumen_hoy_hash'] === $hash && !empty($row['resumen_hoy_texto'])) {
    respond([
        'resumen' => $row['resumen_hoy_texto'],
        'generado_en' => $row['resumen_hoy_generado_en'],
        'regenerado' => false,
    ]);
}

$texto = ia_generar_resumen_hoy($items);

if ($texto === null) {
    // La IA falló (red, sin crédito, etc.) -- si ya había un resumen viejo
    // guardado, mejor mostrar ese que dejar al usuario sin nada.
    if ($row && !empty($row['resumen_hoy_texto'])) {
        respond([
            'resumen' => $row['resumen_hoy_texto'],
            'generado_en' => $row['resumen_hoy_generado_en'],
            'regenerado' => false,
            'aviso' => 'No se pudo actualizar "Qué hacer hoy" ahora mismo, se muestra el último generado.',
        ]);
    }
    fail('No se pudo generar "Qué hacer hoy". Revisa ia_debug.log.', 502);
}

$ahora = date('Y-m-d H:i:s');
$pdo->prepare('UPDATE usuarios SET resumen_hoy_texto = :t, resumen_hoy_hash = :h, resumen_hoy_generado_en = :f WHERE id = :id')
    ->execute([':t' => $texto, ':h' => $hash, ':f' => $ahora, ':id' => $user['id']]);

respond(['resumen' => $texto, 'generado_en' => $ahora, 'regenerado' => true]);
