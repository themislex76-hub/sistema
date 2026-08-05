<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llave pública VAPID — el navegador la necesita para suscribirse a
// notificaciones push. No es secreta (a diferencia de la privada, que
// nunca sale de push_credentials.php).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$credentialsFile = __DIR__ . '/push_credentials.php';
if (!file_exists($credentialsFile)) {
    fail('Las notificaciones push todavía no están configuradas en el servidor.', 503);
}
require_once $credentialsFile;

respond(['public_key' => PUSH_VAPID_PUBLIC_KEY]);
