<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_credentials.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$stmt = $pdo->prepare('SELECT google_refresh_token FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();
if (!$row || empty($row['google_refresh_token'])) {
    fail('No has conectado tu cuenta de Google Calendar.', 409);
}

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'refresh_token' => $row['google_refresh_token'],
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
    ]),
]);
$respuesta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = $respuesta ? json_decode($respuesta, true) : null;
if ($httpCode !== 200 || !$data || empty($data['access_token'])) {
    // El refresh_token pudo haber sido revocado desde la cuenta de Google del
    // usuario, o (en modo Prueba) expiró a los 7 días — hay que reconectar.
    $upd = $pdo->prepare('UPDATE usuarios SET google_refresh_token = NULL, google_calendar_email = NULL, google_conectado_en = NULL WHERE id = :id');
    $upd->execute([':id' => $user['id']]);
    fail('Tu conexión con Google Calendar ya no es válida. Vuelve a conectarla.', 409);
}

respond(['access_token' => $data['access_token']]);
