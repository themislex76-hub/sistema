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
$row = guard_expediente_access($pdo, $user, $id);

$notas = (string)($in['notas'] ?? '');
$cobroPendiente = !empty($in['cobro_pendiente']);
$concluido = !empty($in['concluido_manual']);
$abogadoId = array_key_exists('abogado_id', $in) && $in['abogado_id'] !== '' && $in['abogado_id'] !== null
    ? (int)$in['abogado_id'] : null;
$abogadoId2 = array_key_exists('abogado_id_2', $in) && $in['abogado_id_2'] !== '' && $in['abogado_id_2'] !== null
    ? (int)$in['abogado_id_2'] : null;

$stmt = $pdo->prepare(
    'UPDATE expedientes SET notas_internas = :notas, cobro_pendiente = :cp, concluido_manual = :cm, abogado_id = :abogado, abogado_id_2 = :abogado2 WHERE id = :id'
);
$stmt->execute([
    ':notas' => $notas, ':cp' => $cobroPendiente ? 1 : 0, ':cm' => $concluido ? 1 : 0,
    ':abogado' => $abogadoId, ':abogado2' => $abogadoId2, ':id' => $id,
]);

if ((int)($row['abogado_id'] ?? 0) !== (int)($abogadoId ?? 0)) {
    $before = $row['abogado_id'] ? nombre_usuario($pdo, (int)$row['abogado_id']) : 'Sin asignar';
    $after = $abogadoId ? nombre_usuario($pdo, $abogadoId) : 'Sin asignar';
    log_historial($pdo, $id, $user, 'abogado_asignado', $before, $after);
}
if ((int)($row['abogado_id_2'] ?? 0) !== (int)($abogadoId2 ?? 0)) {
    $before = $row['abogado_id_2'] ? nombre_usuario($pdo, (int)$row['abogado_id_2']) : 'Sin asignar';
    $after = $abogadoId2 ? nombre_usuario($pdo, $abogadoId2) : 'Sin asignar';
    log_historial($pdo, $id, $user, 'segundo_abogado_asignado', $before, $after);
}

respond(['ok' => true]);

function nombre_usuario(PDO $pdo, int $id): string
{
    $s = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = :id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ? $r['nombre'] : ('#' . $id);
}
