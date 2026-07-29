<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$prestaciones = is_array($in['prestaciones'] ?? null) ? $in['prestaciones'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

// Reemplazo completo de la lista (igual que "Cobros"/expedientes_update_pagos.php).
$pdo->prepare('DELETE FROM expediente_prestaciones_extra WHERE expediente_id = :eid')->execute([':eid' => $id]);

$ins = $pdo->prepare(
    'INSERT INTO expediente_prestaciones_extra (expediente_id, concepto, monto, orden)
     VALUES (:eid, :concepto, :monto, :orden)'
);
$orden = 0;
foreach ($prestaciones as $p) {
    if (!is_array($p)) continue;
    $concepto = trim((string)($p['concepto'] ?? ''));
    $monto = ($p['monto'] ?? '') !== '' ? (float)$p['monto'] : null;
    if ($concepto === '') continue;
    $ins->execute([
        ':eid' => $id, ':concepto' => $concepto, ':monto' => $monto, ':orden' => $orden++,
    ]);
}

respond(['ok' => true]);
