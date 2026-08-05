<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Borra la suscripción push de este dispositivo (el navegador manda esto
// si la persona desactiva las notificaciones desde el sistema).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$endpoint = trim((string)($in['endpoint'] ?? ''));
if ($endpoint === '') fail('Falta el endpoint.', 400);

$pdo = db();
$stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint AND usuario_id = :uid');
$stmt->execute([':endpoint' => $endpoint, ':uid' => $user['id']]);

respond(['ok' => true]);
