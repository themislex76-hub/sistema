<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal de diagnóstico — muestra las últimas citas de
// asesoría tal como están guardadas en la base de datos, en texto plano
// fácil de leer/capturar. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
require_admin();

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "Hora del servidor ahora mismo (PHP): " . date('Y-m-d H:i:s (T)') . "\n";
echo "Hora del servidor ahora mismo (MySQL NOW()): " . $pdo->query('SELECT NOW() AS n')->fetch()['n'] . "\n";
echo "CURDATE() de MySQL: " . $pdo->query('SELECT CURDATE() AS c')->fetch()['c'] . "\n";
echo str_repeat('-', 70) . "\n\n";

$stmt = $pdo->query(
    'SELECT id, telefono, usuario_id, fecha, hora_inicio, hora_fin, estado, monto,
            nombre_cliente, mp_preference_id, mp_payment_id, creado_en, pagado_en
     FROM citas_asesoria ORDER BY id DESC LIMIT 15'
);
foreach ($stmt->fetchAll() as $r) {
    foreach ($r as $k => $v) {
        echo str_pad($k, 18) . ": " . ($v ?? '(null)') . "\n";
    }
    echo str_repeat('-', 70) . "\n";
}
