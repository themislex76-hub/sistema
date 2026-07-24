<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$etapas = is_array($in['etapas'] ?? null) ? $in['etapas'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

$upsert = $pdo->prepare(
    'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha, fecha_programada, resultado)
     VALUES (:eid, :key, :fecha, :fecha_programada, :resultado)
     ON DUPLICATE KEY UPDATE fecha = VALUES(fecha), fecha_programada = VALUES(fecha_programada), resultado = VALUES(resultado)'
);
$delete = $pdo->prepare('DELETE FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key');

foreach (ETAPA_KEYS as $key) {
    $val = $etapas[$key] ?? null;
    $fecha = is_array($val) ? ($val['fecha'] ?? '') : '';
    $fechaProg = is_array($val) ? ($val['fecha_programada'] ?? '') : '';
    $resultado = is_array($val) ? ($val['resultado'] ?? '') : '';
    $fecha = $fecha === '' ? null : $fecha;
    $fechaProg = $fechaProg === '' ? null : $fechaProg;
    $resultado = $resultado === '' ? null : $resultado;

    if ($fecha === null && $fechaProg === null && $resultado === null) {
        $delete->execute([':eid' => $id, ':key' => $key]);
        continue;
    }
    $upsert->execute([':eid' => $id, ':key' => $key, ':fecha' => $fecha, ':fecha_programada' => $fechaProg, ':resultado' => $resultado]);
}

// Si se marcó la etapa de amparo directo con fecha, refleja el estado también
// en las banderas de amparo (igual que hacía el frontend original).
if (!empty($etapas['amparo_directo']['fecha'])) {
    $pdo->prepare('UPDATE expedientes SET amparo_activo = 1, amparo_presentado = 1 WHERE id = :id')->execute([':id' => $id]);
}

respond(['ok' => true]);
