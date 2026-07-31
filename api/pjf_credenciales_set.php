<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El propio abogado guarda (o actualiza) su usuario/contraseña del Portal
// Federal desde su perfil. La contraseña se cifra antes de guardarse — ver
// pjf_encrypt() en helpers.php. Mandar contraseña vacía borra lo guardado
// (para cuando alguien ya no quiere que el robot use su cuenta).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$pjfUsuario = trim((string)($in['pjf_usuario'] ?? ''));
$pjfPassword = (string)($in['pjf_password'] ?? '');
$borrar = !empty($in['borrar']);

$pdo = db();

if ($borrar) {
    $stmt = $pdo->prepare('UPDATE usuarios SET pjf_usuario = NULL, pjf_password_enc = NULL, pjf_actualizado_en = NULL WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);
    respond(['ok' => true]);
}

if ($pjfUsuario === '') fail('Falta el usuario del Portal Federal.', 400);
if ($pjfPassword === '') fail('Falta la contraseña del Portal Federal.', 400);

$encrypted = pjf_encrypt($pjfPassword);
$stmt = $pdo->prepare(
    'UPDATE usuarios SET pjf_usuario = :usuario, pjf_password_enc = :password, pjf_actualizado_en = NOW() WHERE id = :id'
);
$stmt->execute([':usuario' => $pjfUsuario, ':password' => $encrypted, ':id' => $user['id']]);

respond(['ok' => true]);
