<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$pagos = is_array($in['pagos'] ?? null) ? $in['pagos'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

// Reemplazo completo de la lista (igual que el comportamiento original de
// "guardar" la pestaña de Cobros, que reescribía el arreglo entero).
$pdo->prepare('DELETE FROM expediente_pagos WHERE expediente_id = :eid')->execute([':eid' => $id]);

$ins = $pdo->prepare(
    'INSERT INTO expediente_pagos (expediente_id, fecha, monto, cobrado, fecha_cobro, orden)
     VALUES (:eid, :fecha, :monto, :cobrado, :fecha_cobro, :orden)'
);
$orden = 0;
foreach ($pagos as $p) {
    if (!is_array($p)) continue;
    $fecha = ($p['fecha'] ?? '') !== '' ? $p['fecha'] : null;
    $monto = ($p['monto'] ?? '') !== '' ? (float)$p['monto'] : null;
    $cobrado = !empty($p['cobrado']);
    $fechaCobro = ($p['fecha_cobro'] ?? '') !== '' ? $p['fecha_cobro'] : null;
    if ($fecha === null && $monto === null) continue;
    $ins->execute([
        ':eid' => $id, ':fecha' => $fecha, ':monto' => $monto,
        ':cobrado' => $cobrado ? 1 : 0, ':fecha_cobro' => $fechaCobro, ':orden' => $orden++,
    ]);
}

respond(['ok' => true]);
