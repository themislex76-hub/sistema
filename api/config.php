<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar errores PHP crudos al navegador en producción
ini_set('log_errors', '1');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Antes la cookie de sesión duraba solo mientras el navegador seguía
// abierto ('lifetime' => 0) — en celular, cuando el sistema operativo
// cierra la app en segundo plano (algo muy común), eso borraba la sesión
// y la persona aparecía como "no ha iniciado sesión" aunque acabara de
// entrar. Se extiende a 30 días tanto en la cookie como en el lado del
// servidor (session.gc_maxlifetime, si no se cambia esto la sesión igual
// se podía borrar del servidor por inactividad aunque la cookie siguiera
// viva).
$sessionLifetimeSegundos = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string)$sessionLifetimeSegundos);

session_name('ELA_SESSION');
session_set_cookie_params([
    'lifetime' => $sessionLifetimeSegundos,
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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
