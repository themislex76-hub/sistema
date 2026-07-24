<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();
$out = [
    'version' => 2,
    'generado_en' => date('c'),
    'usuarios' => $pdo->query('SELECT id, nombre, email, rol, activo, creado_en FROM usuarios ORDER BY id')->fetchAll(),
    'expedientes' => $pdo->query('SELECT * FROM expedientes ORDER BY id')->fetchAll(),
    'expediente_etapas' => $pdo->query('SELECT * FROM expediente_etapas ORDER BY expediente_id')->fetchAll(),
    'expediente_pagos' => $pdo->query('SELECT * FROM expediente_pagos ORDER BY expediente_id')->fetchAll(),
    'expediente_pendientes' => $pdo->query('SELECT * FROM expediente_pendientes ORDER BY expediente_id')->fetchAll(),
    'expediente_notas' => $pdo->query('SELECT * FROM expediente_notas ORDER BY expediente_id')->fetchAll(),
    'expediente_historial' => $pdo->query('SELECT * FROM expediente_historial ORDER BY expediente_id')->fetchAll(),
];

header('Content-Disposition: attachment; filename="respaldo_expertoslaborales_' . date('Y-m-d') . '.json"');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
