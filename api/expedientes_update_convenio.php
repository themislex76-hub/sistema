<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$conv = is_array($in['convenio_manual'] ?? null) ? $in['convenio_manual'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
$row = guard_expediente_access($pdo, $user, $id);

$activo = !empty($conv['activo']);
$monto = ($conv['monto'] ?? '') !== '' ? (float)$conv['monto'] : null;
$fechaPago = ($conv['fecha_pago'] ?? '') !== '' ? $conv['fecha_pago'] : null;

$stmt = $pdo->prepare(
    'UPDATE expedientes SET convenio_activo = :activo, convenio_monto = :monto, convenio_fecha_pago = :fecha WHERE id = :id'
);
$stmt->execute([':activo' => $activo ? 1 : 0, ':monto' => $monto, ':fecha' => $fechaPago, ':id' => $id]);

log_historial($pdo, $id, $user, 'convenio_manual',
    ($row['convenio_activo'] ? 'activo' : 'inactivo') . ' / ' . $row['convenio_monto'],
    ($activo ? 'activo' : 'inactivo') . ' / ' . $monto);

// Igual que el comportamiento original: si el convenio queda activo con monto
// y fecha, se refleja también como un pago programado (sin duplicarlo).
if ($activo && $monto !== null && $fechaPago !== null) {
    $chk = $pdo->prepare('SELECT id FROM expediente_pagos WHERE expediente_id = :eid AND fecha = :fecha AND monto = :monto');
    $chk->execute([':eid' => $id, ':fecha' => $fechaPago, ':monto' => $monto]);
    if (!$chk->fetch()) {
        $pdo->prepare('INSERT INTO expediente_pagos (expediente_id, fecha, monto, cobrado, orden) VALUES (:eid, :fecha, :monto, 0, 0)')
            ->execute([':eid' => $id, ':fecha' => $fechaPago, ':monto' => $monto]);
    }
}

respond(['ok' => true]);
