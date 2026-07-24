<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$pendientes = is_array($in['pendientes'] ?? null) ? $in['pendientes'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

$pdo->prepare('DELETE FROM expediente_pendientes WHERE expediente_id = :eid')->execute([':eid' => $id]);

$ins = $pdo->prepare(
    'INSERT INTO expediente_pendientes (expediente_id, descripcion, fecha_inicio, dias, tipo, cumplido, orden)
     VALUES (:eid, :descripcion, :fecha_inicio, :dias, :tipo, :cumplido, :orden)'
);
$tiposValidos = ['habiles','naturales','fecha_fija'];
$orden = 0;
foreach ($pendientes as $p) {
    if (!is_array($p)) continue;
    $desc = trim((string)($p['descripcion'] ?? ''));
    if ($desc === '') continue;
    $tipo = in_array($p['tipo'] ?? '', $tiposValidos, true) ? $p['tipo'] : 'habiles';
    $ins->execute([
        ':eid' => $id,
        ':descripcion' => $desc,
        ':fecha_inicio' => ($p['fecha_inicio'] ?? '') !== '' ? $p['fecha_inicio'] : null,
        ':dias' => ($p['dias'] ?? '') !== '' ? (int)$p['dias'] : null,
        ':tipo' => $tipo,
        ':cumplido' => !empty($p['cumplido']) ? 1 : 0,
        ':orden' => $orden++,
    ]);
}

respond(['ok' => true]);
