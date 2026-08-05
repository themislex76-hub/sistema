<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Guarda (o actualiza) la suscripción push de este dispositivo para el
// usuario actual — el navegador manda esto justo después de que la
// persona acepta el permiso de notificaciones.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$endpoint = trim((string)($in['endpoint'] ?? ''));
$keys = $in['keys'] ?? [];
$p256dh = trim((string)($keys['p256dh'] ?? ''));
$auth = trim((string)($keys['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    fail('Faltan datos de la suscripción.', 400);
}

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth)
     VALUES (:uid, :endpoint, :p256dh, :auth)
     ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
);
$stmt->execute([
    ':uid' => $user['id'],
    ':endpoint' => $endpoint,
    ':p256dh' => $p256dh,
    ':auth' => $auth,
]);

respond(['ok' => true]);
