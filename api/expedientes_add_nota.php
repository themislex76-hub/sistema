<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$texto = trim((string)($in['texto'] ?? ''));
if ($id <= 0) fail('Falta el id del expediente.', 400);
if ($texto === '') fail('La nota no puede estar vacía.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

$stmt = $pdo->prepare(
    'INSERT INTO expediente_notas (expediente_id, usuario_id, usuario_nombre, texto) VALUES (:eid, :uid, :uname, :texto)'
);
$stmt->execute([':eid' => $id, ':uid' => $user['id'], ':uname' => $user['nombre'], ':texto' => $texto]);

respond(['ok' => true, 'fecha' => date('Y-m-d H:i:s')]);
