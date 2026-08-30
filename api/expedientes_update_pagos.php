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

// El reemplazo de abajo es completo (DELETE + re-INSERT), así que no hay
// forma de comparar pago por pago -- se registra en el historial un
// resumen general de antes/después (cuántos cobrados, total), suficiente
// para saber quién tocó los cobros de este expediente y cuándo.
$antesStmt = $pdo->prepare('SELECT cobrado, monto FROM expediente_pagos WHERE expediente_id = :eid');
$antesStmt->execute([':eid' => $id]);
$antesFilas = $antesStmt->fetchAll();
$resumenPagos = function (array $filas): ?string {
    if (!$filas) return null;
    $cobrados = 0;
    $total = 0.0;
    foreach ($filas as $f) {
        if (!empty($f['cobrado'])) { $cobrados++; $total += (float)$f['monto']; }
    }
    return count($filas) . ' pago(s), ' . $cobrados . ' cobrado(s), total cobrado: $' . number_format($total, 2);
};
$antesTexto = $resumenPagos($antesFilas);

// Reemplazo completo de la lista (igual que el comportamiento original de
// "guardar" la pestaña de Cobros, que reescribía el arreglo entero).
$pdo->prepare('DELETE FROM expediente_pagos WHERE expediente_id = :eid')->execute([':eid' => $id]);

$ins = $pdo->prepare(
    'INSERT INTO expediente_pagos (expediente_id, fecha, monto, cobrado, fecha_cobro, orden)
     VALUES (:eid, :fecha, :monto, :cobrado, :fecha_cobro, :orden)'
);
$orden = 0;
$despuesFilas = [];
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
    $despuesFilas[] = ['cobrado' => $cobrado, 'monto' => $monto ?? 0];
}

log_historial($pdo, $id, $user, 'cobros', $antesTexto, $resumenPagos($despuesFilas));

respond(['ok' => true]);
