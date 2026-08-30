<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

$activo = !empty($in['amparo_activo']);
$presentado = array_key_exists('amparo_presentado', $in) ? !empty($in['amparo_presentado']) : null;
$fechaNotif = ($in['amparo_fecha_notif'] ?? '') !== '' ? $in['amparo_fecha_notif'] : null;
$notas = (string)($in['amparo_notas'] ?? '');
// El Tribunal y el número de expediente del amparo (distintos al expediente
// del juicio laboral original) son lo que el robot Federal necesita para
// rastrear también los acuerdos del amparo -- ver federal.js.
$tribunal = trim((string)($in['amparo_tribunal'] ?? ''));
$expedienteAmparo = trim((string)($in['amparo_expediente'] ?? ''));

if ($presentado === null) {
    $stmt = $pdo->prepare('UPDATE expedientes SET amparo_activo = :activo, amparo_fecha_notif = :fecha, amparo_tribunal = :tribunal, amparo_expediente = :exp_amparo, amparo_notas = :notas WHERE id = :id');
    $stmt->execute([':activo' => $activo ? 1 : 0, ':fecha' => $fechaNotif, ':tribunal' => $tribunal !== '' ? $tribunal : null, ':exp_amparo' => $expedienteAmparo !== '' ? $expedienteAmparo : null, ':notas' => $notas, ':id' => $id]);
} else {
    $stmt = $pdo->prepare('UPDATE expedientes SET amparo_activo = :activo, amparo_presentado = :presentado, amparo_fecha_notif = :fecha, amparo_tribunal = :tribunal, amparo_expediente = :exp_amparo, amparo_notas = :notas WHERE id = :id');
    $stmt->execute([':activo' => $activo ? 1 : 0, ':presentado' => $presentado ? 1 : 0, ':fecha' => $fechaNotif, ':tribunal' => $tribunal !== '' ? $tribunal : null, ':exp_amparo' => $expedienteAmparo !== '' ? $expedienteAmparo : null, ':notas' => $notas, ':id' => $id]);
}

respond(['ok' => true]);
