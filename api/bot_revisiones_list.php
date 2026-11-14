<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Panel "Conversaciones del bot a revisar" (Equipo, solo Administrador):
// lo que cron_revisar_conversaciones_bot.php marcó con algún error.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);

$pdo = db();
try {
    $stmt = $pdo->query(
        "SELECT r.id, r.telefono, r.fecha, r.gravedad, r.resumen, r.hallazgos, r.estado, r.creado_en,
                p.nombre AS prospecto_nombre
         FROM bot_revisiones r
         LEFT JOIN prospectos p ON p.telefono = r.telefono
         WHERE r.veredicto = 'revisar' AND r.estado = 'pendiente'
         ORDER BY FIELD(r.gravedad, 'alta', 'media', 'baja'), r.fecha DESC
         LIMIT 100"
    );
    $filas = $stmt->fetchAll();
    $ultima = $pdo->query("SELECT MAX(fecha) AS fecha, COUNT(*) AS total, SUM(veredicto = 'revisar') AS con_problema
                           FROM bot_revisiones WHERE fecha = (SELECT MAX(fecha) FROM bot_revisiones)")->fetch();
} catch (Throwable $e) {
    respond(['revisiones' => [], 'ultima' => null, 'sin_tabla' => true]);
}
foreach ($filas as &$f) {
    $f['hallazgos'] = $f['hallazgos'] ? (json_decode($f['hallazgos'], true) ?: []) : [];
}
unset($f);
respond(['revisiones' => $filas, 'ultima' => $ultima]);
