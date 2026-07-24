<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$admin = require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del usuario.', 400);

$tempPassword = random_temp_password();
$hash = password_hash($tempPassword, PASSWORD_BCRYPT);

$stmt = db()->prepare(
    'UPDATE usuarios SET password_hash = :h, debe_cambiar_password = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id'
);
$stmt->execute([':h' => $hash, ':id' => $id]);
if ($stmt->rowCount() === 0) fail('Usuario no encontrado.', 404);

respond(['temp_password' => $tempPassword]);
