<?php
declare(strict_types=1);

// Endpoint público (sin sesión) que Mercado Pago llama para avisar que un
// pago cambió de estado. Nunca hay que confiar en el contenido del aviso
// por sí solo — aquí solo se usa para saber QUÉ pago revisar; el estado
// real siempre se vuelve a preguntar directo a la API de Mercado Pago con
// nuestro propio Access Token (ver mercadopago_obtener_pago).

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mercadopago_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/citas_helpers.php';

// Mercado Pago acepta que el endpoint conteste rápido y sin cuerpo — si
// tarda o falla, reintenta la notificación más tarde.
function mp_webhook_responder(int $status = 200): void
{
    http_response_code($status);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

// El aviso trae el id del pago ya sea en el cuerpo JSON (webhooks v2) o en
// la query string (formato IPN clásico) — se revisan ambos.
$tipo = $body['type'] ?? $body['topic'] ?? ($_GET['type'] ?? $_GET['topic'] ?? '');
$paymentId = (string)($body['data']['id'] ?? $_GET['data.id'] ?? $_GET['id'] ?? '');

if ($tipo !== 'payment' || $paymentId === '') {
    // Otros tipos de notificación (merchant_order, etc.) no aplican aquí.
    mp_webhook_responder(200);
}

$pago = mercadopago_obtener_pago($paymentId);
if ($pago === null) {
    // No se pudo confirmar con la API — respondemos error para que
    // Mercado Pago reintente el aviso más tarde.
    mp_webhook_responder(500);
}

if (($pago['status'] ?? '') !== 'approved') {
    // Pago rechazado, pendiente, cancelado, etc. — no hay nada que
    // confirmar todavía. Si llega un aviso posterior con "approved" se
    // procesa entonces.
    mp_webhook_responder(200);
}

$citaId = (int)($pago['external_reference'] ?? 0);
if ($citaId <= 0) {
    mp_webhook_responder(200);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM citas_asesoria WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $citaId]);
$cita = $stmt->fetch();

if (!$cita) {
    mp_webhook_responder(200);
}

if ($cita['estado'] === 'confirmada') {
    // Aviso duplicado (Mercado Pago puede reenviar el mismo evento) — ya
    // se procesó, no hay que volver a mandar el WhatsApp de confirmación.
    mp_webhook_responder(200);
}

$stmt = $pdo->prepare(
    "UPDATE citas_asesoria SET estado = 'confirmada', mp_payment_id = :pago_id, pagado_en = NOW() WHERE id = :id"
);
$stmt->execute([':pago_id' => $paymentId, ':id' => $citaId]);

// A partir de aquí un humano se encarga (preparar y hacer la llamada) —
// se pausa el bot para este teléfono igual que un lead de despido.
$stmt = $pdo->prepare('UPDATE prospectos SET pausado_bot = 1 WHERE telefono = :t');
$stmt->execute([':t' => $cita['telefono']]);

$horarioTexto = citas_formatear_fecha_hora($cita['fecha'], substr($cita['hora_inicio'], 0, 5));
$mensaje = "¡Tu pago quedó confirmado! Tu asesoría telefónica de 1 hora queda agendada para el {$horarioTexto}. Un abogado del despacho te va a llamar a este mismo número de WhatsApp a esa hora — por favor ten tu teléfono a la mano. Si no contestas la llamada en 2 intentos, no habrá devolución del pago. Cualquier cosa antes, aquí mismo nos puedes escribir.";
whatsapp_enviar($cita['telefono'], $mensaje);

$stmt = $pdo->prepare(
    "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
);
$stmt->execute([':t' => $cita['telefono'], ':texto' => $mensaje]);

mp_webhook_responder(200);
