<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar errores PHP crudos al navegador en producción
ini_set('log_errors', '1');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('ELA_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
