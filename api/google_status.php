<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$stmt = $pdo->prepare('SELECT google_refresh_token, google_calendar_email, google_conectado_en FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

respond([
    'conectado' => !empty($row['google_refresh_token']),
    'email' => $row['google_calendar_email'],
    'conectado_en' => $row['google_conectado_en'],
]);
