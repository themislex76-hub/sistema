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

// El puente de Cloudflare (scripts/cloudflare_worker_whatsapp.js) espera
// (await) esta respuesta antes de contestarle a Meta — si el
// procesamiento real (llamada a la IA + el retraso natural de 20-28s)
// tarda de más, Meta puede darse por vencido y REINTENTAR mandar el mismo
// mensaje, lo que antes causaba que la IA contestara el mismo mensaje
// varias veces (se detectó en producción: la misma pregunta contestada
// 2-3 veces, con respuestas distintas entre sí). Por eso se responde AQUÍ
// MISMO, de inmediato, y el procesamiento real sigue corriendo después en
// segundo plano (fastcgi_finish_request corta la conexión con el cliente
// pero el script PHP sigue vivo — ignore_user_abort de arriba evita que
// lo maten a la mitad).
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Sin PHP-FPM no hay una forma tan limpia de cortar la conexión y
    // seguir corriendo — se manda como mejor esfuerzo. No es tan
    // confiable como fastcgi_finish_request, pero es mejor que nada.
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

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
// La respuesta ya se mandó arriba, antes de procesar — no hay nada más
// que responder aquí, el script solo termina de correr en silencio.
