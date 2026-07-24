<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$current = (string)($in['current_password'] ?? '');
$new = (string)($in['new_password'] ?? '');
if (strlen($new) < 8) fail('La nueva contraseña debe tener al menos 8 caracteres.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    fail('La contraseña actual no es correcta.', 401);
}

$hash = password_hash($new, PASSWORD_BCRYPT);
$upd = $pdo->prepare('UPDATE usuarios SET password_hash = :h, debe_cambiar_password = 0 WHERE id = :id');
$upd->execute([':h' => $hash, ':id' => $user['id']]);

respond(['ok' => true]);
