<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El propio abogado guarda (o actualiza) su usuario/contraseña del correo
// de notificaciones electrónicas de Edomex (mail.pjedomex.gob.mx) desde su
// perfil. La contraseña se cifra antes de guardarse -- ver pjf_encrypt() en
// helpers.php (mismo cifrado que ya se usa para el Portal Federal, nombre
// genérico a pesar de "pjf"). Mandar contraseña vacía borra lo guardado
// (para cuando alguien ya no quiere que el robot use su cuenta).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$usuario = trim((string)($in['edomex_correo_usuario'] ?? ''));
$password = (string)($in['edomex_correo_password'] ?? '');
$borrar = !empty($in['borrar']);

$pdo = db();

if ($borrar) {
    $stmt = $pdo->prepare('UPDATE usuarios SET edomex_correo_usuario = NULL, edomex_correo_password_enc = NULL, edomex_correo_actualizado_en = NULL WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);
    respond(['ok' => true]);
}

if ($usuario === '') fail('Falta el usuario del correo de notificaciones.', 400);
if ($password === '') fail('Falta la contraseña del correo de notificaciones.', 400);

$encrypted = pjf_encrypt($password);
$stmt = $pdo->prepare(
    'UPDATE usuarios SET edomex_correo_usuario = :usuario, edomex_correo_password_enc = :password, edomex_correo_actualizado_en = NOW() WHERE id = :id'
);
$stmt->execute([':usuario' => $usuario, ':password' => $encrypted, ':id' => $user['id']]);

respond(['ok' => true]);
