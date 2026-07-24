<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$estado = (string)($in['estado'] ?? 'revisado');
if ($id <= 0) fail('Falta el id del aviso.', 400);
if (!in_array($estado, ['revisado', 'descartado', 'nuevo'], true)) fail('Estado no válido.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT expediente_id FROM avisos_boletin WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('Aviso no encontrado.', 404);

guard_expediente_access($pdo, $user, (int)$row['expediente_id']);

if ($estado === 'nuevo') {
    $upd = $pdo->prepare('UPDATE avisos_boletin SET estado = \'nuevo\', revisado_por = NULL, revisado_en = NULL WHERE id = :id');
    $upd->execute([':id' => $id]);
} else {
    $upd = $pdo->prepare('UPDATE avisos_boletin SET estado = :estado, revisado_por = :uid, revisado_en = NOW() WHERE id = :id');
    $upd->execute([':estado' => $estado, ':uid' => $user['id'], ':id' => $id]);
}

respond(['ok' => true]);
