<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El propio abogado guarda (o actualiza) su usuario/contraseña del Buzón
// Electrónico Laboral del PJCDMX desde su perfil. La contraseña se cifra
// antes de guardarse -- ver pjf_encrypt() en helpers.php (mismo cifrado que
// ya se usa para el Portal Federal y el correo de Edomex). Mandar
// contraseña vacía borra lo guardado (para cuando alguien ya no quiere que
// el robot use su cuenta).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$usuario = trim((string)($in['cdmx_buzon_usuario'] ?? ''));
$password = (string)($in['cdmx_buzon_password'] ?? '');
$borrar = !empty($in['borrar']);

$pdo = db();

if ($borrar) {
    $stmt = $pdo->prepare('UPDATE usuarios SET cdmx_buzon_usuario = NULL, cdmx_buzon_password_enc = NULL, cdmx_buzon_actualizado_en = NULL WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);
    respond(['ok' => true]);
}

if ($usuario === '') fail('Falta el usuario (correo) del Buzón Electrónico Laboral.', 400);
if ($password === '') fail('Falta la contraseña del Buzón Electrónico Laboral.', 400);

$encrypted = pjf_encrypt($password);
$stmt = $pdo->prepare(
    'UPDATE usuarios SET cdmx_buzon_usuario = :usuario, cdmx_buzon_password_enc = :password, cdmx_buzon_actualizado_en = NOW() WHERE id = :id'
);
$stmt->execute([':usuario' => $usuario, ':password' => $encrypted, ':id' => $user['id']]);

respond(['ok' => true]);
