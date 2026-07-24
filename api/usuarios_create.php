<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$nombre = trim((string)($in['nombre'] ?? ''));
$email = trim(strtolower((string)($in['email'] ?? '')));
$rol = (string)($in['rol'] ?? 'abogado');
if ($rol !== 'administrador' && $rol !== 'abogado') $rol = 'abogado';
if ($nombre === '') fail('El nombre es obligatorio.', 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('El correo no es válido.', 400);

$pdo = db();
$check = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email');
$check->execute([':email' => $email]);
if ($check->fetch()) fail('Ya existe un usuario con ese correo.', 409);

$tempPassword = random_temp_password();
$hash = password_hash($tempPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO usuarios (nombre, email, password_hash, rol, debe_cambiar_password, activo)
     VALUES (:nombre, :email, :hash, :rol, 1, 1)'
);
$stmt->execute([':nombre' => $nombre, ':email' => $email, ':hash' => $hash, ':rol' => $rol]);

respond([
    'id' => (int)$pdo->lastInsertId(),
    'nombre' => $nombre,
    'email' => $email,
    'rol' => $rol,
    'temp_password' => $tempPassword,
], 201);
