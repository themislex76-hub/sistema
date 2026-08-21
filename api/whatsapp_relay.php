<?php
declare(strict_types=1);

// Endpoint público (sin sesión) que recibe los mensajes de WhatsApp desde
// el puente externo (Cloudflare Worker) en vez de directo de Meta — se usa
// cuando el hosting bloquea las peticiones POST que Meta manda directo
// (WAF automático de hostings compartidos). Ver docs/DEPLOY_CPANEL.md.
//
// El puente ya validó la firma de Meta por su cuenta; aquí solo
// verificamos una llave compartida propia (WHATSAPP_RELAY_KEY) para
// confirmar que la petición viene realmente de nuestro puente y no de
// cualquiera que adivine la URL.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Mismo motivo que en whatsapp_webhook.php: el puente de Cloudflare (o el
// hosting) puede desconectarse antes de que termine todo el flujo (IA +
// retraso natural + envío del PDF del cálculo) -- sin esto PHP mata el
// script a la mitad, normalmente justo antes de mandar el PDF.
ignore_user_abort(true);
set_time_limit(120);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/whatsapp_procesar.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$credentialsFile = __DIR__ . '/whatsapp_credentials.php';
if (!file_exists($credentialsFile)) {
    http_response_code(500);
    exit;
}
require_once $credentialsFile;

$sent = $_SERVER['HTTP_X_RELAY_KEY'] ?? '';
if ($sent === '' || !hash_equals(WHATSAPP_RELAY_KEY, $sent)) {
    http_response_code(403);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$mensajes = $in['mensajes'] ?? [];

$pdo = db();

foreach ($mensajes as $m) {
    $nombrePerfil = isset($m['nombre']) && $m['nombre'] !== '' ? (string)$m['nombre'] : null;
    procesar_mensaje_entrante($pdo, [
        'id' => (string)($m['id'] ?? ''),
        'from' => (string)($m['telefono'] ?? ''),
        'type' => (string)($m['tipo'] ?? ''),
        'text' => ['body' => (string)($m['texto'] ?? '')],
    ], $nombrePerfil);
}

http_response_code(200);
echo 'OK';
