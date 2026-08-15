<?php
declare(strict_types=1);

// Endpoint público (sin sesión) que Meta llama para:
//  - GET:  verificar el webhook una sola vez, al configurarlo.
//  - POST: entregar cada mensaje entrante de WhatsApp.
// No usa config.php a propósito: ese archivo fuerza sesión + Content-Type
// JSON, y la verificación GET debe responder texto plano.
//
// En hostings compartidos con WAF automático, Meta puede no lograr llegar
// hasta aquí con el POST real (aunque la verificación GET sí funcione) —
// en ese caso, usa api/whatsapp_relay.php como intermediario. Ver
// docs/DEPLOY_CPANEL.md, sección "Puente con Cloudflare Workers".

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// El límite de tiempo de ejecución por default del hosting (normalmente
// 30s) no alcanza: el retraso natural antes de contestar (hasta 28s) más
// el PDF del cálculo (retraso adicional + generarlo + subirlo a Meta) más
// el tiempo real de la IA pueden sumar más que eso — y si PHP mata el
// proceso a medias, el cliente se queda sin ninguna respuesta y no queda
// ni rastro en los logs (es un error fatal, no capturable).
set_time_limit(120);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/whatsapp_procesar.php';

$credentialsFile = __DIR__ . '/whatsapp_credentials.php';
if (!file_exists($credentialsFile)) {
    http_response_code(500);
    exit;
}
require_once $credentialsFile;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $modo = $_GET['hub_mode'] ?? '';
    $tokenRecibido = $_GET['hub_verify_token'] ?? '';
    if ($modo === 'subscribe' && hash_equals(WHATSAPP_VERIFY_TOKEN, $tokenRecibido)) {
        header('Content-Type: text/plain');
        echo $_GET['hub_challenge'] ?? '';
        exit;
    }
    http_response_code(403);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string)file_get_contents('php://input');

$firmaEsperada = 'sha256=' . hash_hmac('sha256', $raw, WHATSAPP_APP_SECRET);
$firmaRecibida = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($firmaRecibida === '' || !hash_equals($firmaEsperada, $firmaRecibida)) {
    http_response_code(403);
    exit;
}

$data = json_decode($raw, true) ?: [];
$pdo = db();

foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];

        // Confirmaciones de entrega/lectura ("statuses") — normalmente se
        // ignoran, pero si una falló se registra el motivo exacto para
        // poder diagnosticar (ej. mensaje fuera de la ventana de 24h que
        // permite Meta para mensajes que inicia la empresa).
        foreach (($value['statuses'] ?? []) as $st) {
            if (($st['status'] ?? '') === 'failed') {
                file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
                    . ' | ENTREGA FALLIDA | para=' . ($st['recipient_id'] ?? '?')
                    . ' | ' . json_encode($st['errors'] ?? [], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            }
        }

        $mensajes = $value['messages'] ?? [];
        if (!$mensajes) continue;

        $nombrePerfil = $value['contacts'][0]['profile']['name'] ?? null;
        foreach ($mensajes as $msg) {
            procesar_mensaje_entrante($pdo, $msg, $nombrePerfil);
        }
    }
}

http_response_code(200);
echo 'EVENT_RECEIVED';
