<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$pdo = db();
$stmt = $pdo->prepare(
    'UPDATE usuarios SET google_refresh_token = NULL, google_calendar_email = NULL, google_conectado_en = NULL WHERE id = :id'
);
$stmt->execute([':id' => $user['id']]);

$del = $pdo->prepare('DELETE FROM google_calendar_sync WHERE usuario_id = :id');
$del->execute([':id' => $user['id']]);

respond(['ok' => true]);
