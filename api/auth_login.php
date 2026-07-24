<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);

$in = json_input();
$email = trim((string)($in['email'] ?? ''));
$password = (string)($in['password'] ?? '');
if ($email === '' || $password === '') fail('Correo y contraseña son obligatorios.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :email AND activo = 1 LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

$logAttempt = function (?int $userId, bool $exito) use ($pdo, $email) {
    $stmt = $pdo->prepare(
        'INSERT INTO sesiones_log (usuario_id, email_intentado, exito, ip, user_agent)
         VALUES (:uid, :email, :exito, :ip, :ua)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':email' => $email,
        ':exito' => $exito ? 1 : 0,
        ':ip' => client_ip(),
        ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
};

if (!$user) {
    $logAttempt(null, false);
    fail('Correo o contraseña incorrectos.', 401);
}

if (!empty($user['bloqueado_hasta']) && strtotime($user['bloqueado_hasta']) > time()) {
    $logAttempt((int)$user['id'], false);
    fail('Cuenta bloqueada temporalmente por varios intentos fallidos. Intenta de nuevo en unos minutos o pide a un administrador que la desbloquee.', 423);
}

if (!password_verify($password, $user['password_hash'])) {
    $intentos = (int)$user['intentos_fallidos'] + 1;
    $bloqueado = null;
    if ($intentos >= 5) {
        $bloqueado = date('Y-m-d H:i:s', time() + 15 * 60);
        $intentos = 0;
    }
    $upd = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = :i, bloqueado_hasta = :b WHERE id = :id');
    $upd->execute([':i' => $intentos, ':b' => $bloqueado, ':id' => $user['id']]);
    $logAttempt((int)$user['id'], false);
    fail('Correo o contraseña incorrectos.', 401);
}

$upd = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id');
$upd->execute([':id' => $user['id']]);
$logAttempt((int)$user['id'], true);

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_nombre'] = $user['nombre'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_rol'] = $user['rol'];
$csrf = new_csrf_token();

respond([
    'user' => [
        'id' => (int)$user['id'],
        'nombre' => $user['nombre'],
        'email' => $user['email'],
        'rol' => $user['rol'],
        'debe_cambiar_password' => (bool)$user['debe_cambiar_password'],
    ],
    'csrf' => $csrf,
]);
