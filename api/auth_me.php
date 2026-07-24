<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);

$u = current_user();
if (!$u) respond(['user' => null]);

$stmt = db()->prepare('SELECT debe_cambiar_password FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $u['id']]);
$row = $stmt->fetch();
if (!$row) { // el usuario fue desactivado/eliminado desde que inició sesión
    $_SESSION = [];
    session_destroy();
    respond(['user' => null]);
}

$csrf = $_SESSION['csrf'] ?? new_csrf_token();

respond([
    'user' => array_merge($u, ['debe_cambiar_password' => (bool)$row['debe_cambiar_password']]),
    'csrf' => $csrf,
]);
