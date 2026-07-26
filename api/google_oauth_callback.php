<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_credentials.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

function google_callback_redirect(string $status): void
{
    header('Location: /sistema/?google=' . $status);
    exit;
}

$state = (string)($_GET['state'] ?? '');
$expected = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);
if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    google_callback_redirect('error_state');
}

if (!empty($_GET['error'])) {
    google_callback_redirect('error_denegado');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') google_callback_redirect('error_sin_code');

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]),
]);
$respuesta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = $respuesta ? json_decode($respuesta, true) : null;
if ($httpCode !== 200 || !$data || empty($data['refresh_token'])) {
    // Sin refresh_token: probablemente ya había una autorización previa sin
    // "prompt=consent" efectivo. Pídele al usuario que lo intente de nuevo.
    google_callback_redirect('error_token');
}

$accessToken = (string)$data['access_token'];
$refreshToken = (string)$data['refresh_token'];

$email = null;
$chInfo = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($chInfo, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
]);
$infoRespuesta = curl_exec($chInfo);
curl_close($chInfo);
$info = $infoRespuesta ? json_decode($infoRespuesta, true) : null;
if ($info && !empty($info['email'])) $email = (string)$info['email'];

$pdo = db();
$stmt = $pdo->prepare(
    'UPDATE usuarios SET google_refresh_token = :token, google_calendar_email = :email, google_conectado_en = NOW() WHERE id = :id'
);
$stmt->execute([':token' => $refreshToken, ':email' => $email, ':id' => $user['id']]);

google_callback_redirect('ok');
