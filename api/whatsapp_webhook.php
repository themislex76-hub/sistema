<?php
declare(strict_types=1);

// Endpoint público (sin sesión) que Meta llama para:
//  - GET:  verificar el webhook una sola vez, al configurarlo.
//  - POST: entregar cada mensaje entrante de WhatsApp.
// No usa config.php a propósito: ese archivo fuerza sesión + Content-Type
// JSON, y la verificación GET debe responder texto plano.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// DEBUG TEMPORAL — registra CUALQUIER petición (GET o POST) desde el
// primer instante, antes de cualquier otra cosa. Quitar cuando ya funcione.
file_put_contents(__DIR__ . '/webhook_debug.log', date('c')
    . " | INICIO | metodo=" . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . " | qs=" . ($_SERVER['QUERY_STRING'] ?? '')
    . "\n", FILE_APPEND);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';

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

// DEBUG TEMPORAL — quitar este bloque cuando ya funcione.
file_put_contents(__DIR__ . '/webhook_debug.log', date('c')
    . " | sig_recibida=" . ($firmaRecibida !== '' ? $firmaRecibida : 'NINGUNA')
    . " | sig_esperada=" . $firmaEsperada
    . " | raw=" . $raw . "\n", FILE_APPEND);

if ($firmaRecibida === '' || !hash_equals($firmaEsperada, $firmaRecibida)) {
    http_response_code(403);
    exit;
}

function procesar_mensaje_entrante(PDO $pdo, array $msg, ?string $nombrePerfil): void
{
    $telefono = (string)($msg['from'] ?? '');
    if ($telefono === '') return;

    if (($msg['type'] ?? '') !== 'text') {
        whatsapp_enviar($telefono, 'Por ahora solo puedo leer mensajes de texto. Cuéntame tu duda escribiéndola, por favor.');
        return;
    }

    $texto = trim((string)($msg['text']['body'] ?? ''));
    if ($texto === '') return;

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'entrante', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $texto]);

    // Si ya hay un prospecto y el bot está pausado, un humano lleva el
    // caso: no autorespondemos, solo quedó guardado el mensaje para que
    // el abogado lo vea y conteste desde la vista Prospectos.
    $stmt = $pdo->prepare('SELECT pausado_bot FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();
    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        return;
    }

    $stmt = $pdo->prepare('SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
    $stmt->execute([':t' => $telefono]);
    $historial = array_reverse($stmt->fetchAll());

    $mensajesIA = [];
    foreach ($historial as $h) {
        $mensajesIA[] = [
            'role' => $h['direccion'] === 'entrante' ? 'user' : 'assistant',
            'content' => $h['texto'],
        ];
    }
    if (!$mensajesIA || end($mensajesIA)['role'] !== 'user') {
        $mensajesIA[] = ['role' => 'user', 'content' => $texto];
    }

    $resultado = ia_responder_whatsapp($mensajesIA);
    $respuesta = $resultado['texto'];
    $lead = $resultado['lead'];

    if ($lead) {
        $respuesta .= $lead['tipo'] === 'despido'
            ? "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo."
            : "\n\n¡Perfecto! En breve te comparten los datos para agendar y pagar tu asesoría personalizada.";
        guardar_prospecto($pdo, $telefono, $nombrePerfil, $lead);
    }

    whatsapp_enviar($telefono, $respuesta);

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $respuesta]);
}

function guardar_prospecto(PDO $pdo, string $telefono, ?string $nombrePerfil, array $lead): void
{
    $nombre = $lead['nombre'] !== '' ? $lead['nombre'] : $nombrePerfil;
    $estado = $lead['estado'] !== '' ? $lead['estado'] : null;
    // Un lead de despido nunca se degrada a asesoría paga si llega uno
    // nuevo después — es el más valioso de los dos (posible cliente de
    // litigio, no solo de una consulta de una hora).
    $stmt = $pdo->prepare(
        "INSERT INTO prospectos (telefono, tipo, nombre, estado_ubicacion, resumen_caso, pausado_bot)
         VALUES (:t, :tipo, :nombre, :estado, :resumen, 1)
         ON DUPLICATE KEY UPDATE
           tipo = IF(tipo = 'despido', 'despido', VALUES(tipo)),
           nombre = COALESCE(VALUES(nombre), nombre),
           estado_ubicacion = COALESCE(VALUES(estado_ubicacion), estado_ubicacion),
           resumen_caso = VALUES(resumen_caso),
           pausado_bot = 1"
    );
    $stmt->execute([
        ':t' => $telefono,
        ':tipo' => $lead['tipo'],
        ':nombre' => $nombre,
        ':estado' => $estado,
        ':resumen' => $lead['resumen'],
    ]);
}

$data = json_decode($raw, true) ?: [];
$pdo = db();

foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];
        $mensajes = $value['messages'] ?? [];
        if (!$mensajes) continue; // ignora confirmaciones de entrega/lectura ("statuses")

        $nombrePerfil = $value['contacts'][0]['profile']['name'] ?? null;
        foreach ($mensajes as $msg) {
            procesar_mensaje_entrante($pdo, $msg, $nombrePerfil);
        }
    }
}

http_response_code(200);
echo 'EVENT_RECEIVED';
