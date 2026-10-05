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
require_once __DIR__ . '/prospectos_helpers.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/google_calendar_helpers.php';

// Mercado Pago acepta que el endpoint conteste rápido y sin cuerpo — si
// tarda o falla, reintenta la notificación más tarde.
function mp_webhook_responder(int $status = 200): void
{
    http_response_code($status);
    exit;
}

/**
 * Procesa el webhook de un pago de curso (external_reference con prefijo
 * "curso_compra_", ver mercadopago_crear_preferencia_curso). A diferencia
 * de una asesoría, comprar un curso no necesita que un humano haga nada
 * (no hay llamada que agendar) — el acceso se manda solo, por WhatsApp,
 * en cuanto se confirma el pago. Solo se avisa al abogado como aviso de
 * venta, sin pausar el bot ni requerir seguimiento manual.
 */
function mp_webhook_procesar_curso(PDO $pdo, array $pago, string $paymentId, string $externalReference): void
{
    $compraId = (int)substr($externalReference, strlen('curso_compra_'));
    if ($compraId <= 0) return;

    $stmt = $pdo->prepare('SELECT * FROM compras_curso WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $compraId]);
    $compra = $stmt->fetch();
    if (!$compra) return;

    $estadoPago = $pago['status'] ?? '';

    // Mismo criterio que con las asesorías: si se reembolsa después, se
    // refleja aquí para no dejar la compra marcada como "confirmada" para
    // siempre sin que haya un cobro real detrás.
    if (in_array($estadoPago, ['refunded', 'charged_back', 'cancelled'], true)) {
        $pdo->prepare("UPDATE compras_curso SET estado = 'cancelada' WHERE id = :id AND estado = 'confirmada'")
            ->execute([':id' => $compraId]);
        return;
    }

    if ($estadoPago !== 'approved') return;

    $info = CURSOS_CATALOGO[$compra['curso_slug']] ?? null;
    if ($info === null) return;

    // UPDATE atómico igual que en las citas: si Mercado Pago manda el
    // mismo aviso dos veces, solo el primero "gana" y manda el WhatsApp.
    $upd = $pdo->prepare(
        "UPDATE compras_curso SET estado = 'confirmada', mp_payment_id = :pago_id, pagado_en = NOW()
         WHERE id = :id AND estado != 'confirmada'"
    );
    $upd->execute([':pago_id' => $paymentId, ':id' => $compraId]);
    if ($upd->rowCount() === 0) return;

    $accesoLink = $info['sitio'] . '?' . $info['acceso_param'] . '=' . urlencode($paymentId) . '&pago=ok';
    $saludo = $compra['nombre_cliente'] ? "¡Hola {$compra['nombre_cliente']}!" : '¡Hola!';
    $mensaje = "{$saludo} Tu pago del curso *{$info['titulo']}* quedó confirmado. 🎉\n\nAquí está tu acceso (de por vida, entra las veces que quieras):\n{$accesoLink}\n\nCualquier duda sobre el contenido, aquí mismo nos puedes escribir.";

    whatsapp_enviar($compra['telefono'], $mensaje);
    $ins = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $ins->execute([':t' => $compra['telefono'], ':texto' => $mensaje]);

    push_notificar_prospecto(
        $pdo, null,
        '¡Venta de curso confirmada!',
        ($compra['nombre_cliente'] ?: $compra['telefono']) . " compró \"{$info['titulo']}\" (\${$compra['monto']} MXN) — ya se le mandó el acceso por WhatsApp.",
        '/sistema/?abrir=' . urlencode($compra['telefono'])
    );
}

/**
 * Procesa el webhook del documento membretado con el cálculo de
 * liquidación (external_reference con prefijo "doc_calculo_", ver
 * mercadopago_crear_preferencia_documento_calculo). El cálculo completo
 * ya se guardó en calculo_json al generar el link de pago -- así no
 * depende de que la conversación siga activa ni de volver a calcular
 * nada, el PDF se regenera con los mismos datos exactos que se le
 * mostraron a la persona cuando aceptó comprarlo.
 */
function mp_webhook_procesar_documento_calculo(PDO $pdo, array $pago, string $paymentId, string $externalReference): void
{
    $compraId = (int)substr($externalReference, strlen('doc_calculo_'));
    if ($compraId <= 0) return;

    $stmt = $pdo->prepare('SELECT * FROM compras_documento_calculo WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $compraId]);
    $compra = $stmt->fetch();
    if (!$compra) return;

    $estadoPago = $pago['status'] ?? '';

    if (in_array($estadoPago, ['refunded', 'charged_back', 'cancelled'], true)) {
        $pdo->prepare("UPDATE compras_documento_calculo SET estado = 'cancelada' WHERE id = :id AND estado = 'confirmada'")
            ->execute([':id' => $compraId]);
        return;
    }

    if ($estadoPago !== 'approved') return;

    $upd = $pdo->prepare(
        "UPDATE compras_documento_calculo SET estado = 'confirmada', mp_payment_id = :pago_id, pagado_en = NOW()
         WHERE id = :id AND estado != 'confirmada'"
    );
    $upd->execute([':pago_id' => $paymentId, ':id' => $compraId]);
    if ($upd->rowCount() === 0) return;

    $calc = json_decode((string)$compra['calculo_json'], true);
    if (is_array($calc)) {
        whatsapp_enviar_pdf_calculo($compra['telefono'], $calc, (float)$compra['salario_diario'], (string)($compra['nombre_cliente'] ?? ''));
    }

    $mensaje = '¡Tu pago quedó confirmado! Aquí tienes tu documento.';
    whatsapp_enviar($compra['telefono'], $mensaje);
    $ins = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $ins->execute([':t' => $compra['telefono'], ':texto' => $mensaje]);

    push_notificar_prospecto(
        $pdo, null,
        '¡Venta de documento de cálculo confirmada!',
        ($compra['nombre_cliente'] ?: $compra['telefono']) . " compró el documento de cálculo (\${$compra['monto']} MXN).",
        '/sistema/?abrir=' . urlencode($compra['telefono'])
    );
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

$estadoPago = $pago['status'] ?? '';
$externalReference = (string)($pago['external_reference'] ?? '');
$pdo = db();

// Un pago de curso trae el prefijo "curso_compra_" en external_reference
// (ver mercadopago_crear_preferencia_curso) -- se procesa aparte, nunca se
// mezcla con la lógica de citas de abajo (que espera un id numérico puro).
if (str_starts_with($externalReference, 'curso_compra_')) {
    mp_webhook_procesar_curso($pdo, $pago, $paymentId, $externalReference);
    mp_webhook_responder(200);
}

// Documento membretado del cálculo ($49) -- mismo criterio que
// un curso: no hay llamada que agendar, el documento se manda solo por
// WhatsApp en cuanto se confirma el pago.
if (str_starts_with($externalReference, 'doc_calculo_')) {
    mp_webhook_procesar_documento_calculo($pdo, $pago, $paymentId, $externalReference);
    mp_webhook_responder(200);
}

$citaId = (int)$externalReference;

// Si el abogado hace una devolución directo en el panel de Mercado Pago
// (fuera de este sistema, como pasa hoy), MP manda este mismo webhook de
// nuevo con el mismo pago pero status='refunded' -- antes esto se
// ignoraba en silencio, así que la cita se quedaba "confirmada" para
// siempre en nuestro sistema aunque ya no hubiera cobro real detrás. Eso
// causó un caso real: un cliente reembolsado escribió después y el bot,
// al ver la cita todavía "confirmada" en la base de datos, le reafirmó
// una cita que ya no debía existir, chocando con otro horario. Cancelar
// aquí automáticamente evita que eso se repita, sin que nadie tenga que
// acordarse de cancelarla también aquí después de reembolsar en Mercado Pago.
if (in_array($estadoPago, ['refunded', 'charged_back', 'cancelled'], true) && $citaId > 0) {
    $upd = $pdo->prepare("UPDATE citas_asesoria SET estado = 'cancelada' WHERE id = :id AND estado = 'confirmada'");
    $upd->execute([':id' => $citaId]);
    mp_webhook_responder(200);
}

if ($estadoPago !== 'approved') {
    // Pago rechazado, pendiente, etc. — no hay nada que confirmar
    // todavía. Si llega un aviso posterior con "approved" se procesa
    // entonces.
    mp_webhook_responder(200);
}

if ($citaId <= 0) {
    mp_webhook_responder(200);
}

$stmt = $pdo->prepare('SELECT * FROM citas_asesoria WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $citaId]);
$cita = $stmt->fetch();

if (!$cita) {
    mp_webhook_responder(200);
}

// Bug real detectado en producción (caso Rosa Isela, sep-2026): el link de
// pago de Mercado Pago no expira solo -- si alguien paga días después de
// que su horario original ya pasó (aquí, tras platicarlo directo con un
// abogado por un problema con el pago), este webhook confirmaba la cita
// IGUAL para la fecha/hora original ya vencida, mandándole al cliente "tu
// asesoría queda agendada para el lunes 7 de septiembre" cuando ya era 12
// de septiembre. Si el horario original ya pasó, NO se le asigna un
// horario nuevo por su cuenta (a propósito -- el sistema no sabe si a esa
// hora le funciona, ver el caso de Rosa Isela pidiendo que no le cambien
// su descanso): se le mandan los horarios reales disponibles para que
// ELLA elija, igual que en el flujo normal de agendar antes de pagar.
$citaYaPaso = strtotime($cita['fecha'] . ' ' . $cita['hora_inicio']) < time();
$horariosParaElegir = $citaYaPaso ? citas_calcular_horarios_disponibles($pdo, 12, 5) : [];

// El UPDATE solo afecta una fila si todavía NO estaba confirmada — esto es
// atómico a nivel de base de datos, así que aunque Mercado Pago mande el
// mismo aviso dos veces casi al mismo tiempo (le pasa seguido), solo uno de
// los dos avisos puede "ganar" la carrera y mandar el WhatsApp de
// confirmación. El otro ve rowCount() = 0 y no hace nada más.
// La fecha/hora NO se toca aquí a propósito cuando ya pasó -- se deja tal
// cual quedó registrada, así "Próximas asesorías agendadas" la sigue
// mostrando marcada como vencida ("¡Ya pasó!") hasta que alguien la
// actualice a mano con la fecha real que elija la clienta.
// monto se guarda aquí con el transaction_amount REAL que confirma
// Mercado Pago, no con el que se calculó al momento de generar el link
// (ver mercadopago_monto_asesoria_a_respetar) -- así el dato queda
// blindado contra cualquier bug futuro en ese cálculo: lo que se guarda
// es lo que Mercado Pago dice que de verdad se cobró. Bug real detectado
// en producción: la columna 'monto' de citas_asesoria nunca se llenaba
// en ningún punto del flujo (ni al crear la cita ni aquí), así que se
// quedaba siempre en su DEFAULT de la tabla (299.00) sin importar el
// precio real cobrado -- el cobro en Mercado Pago sí era correcto, pero
// los reportes del sistema mostraban el precio viejo para todo mundo.
$stmt = $pdo->prepare(
    "UPDATE citas_asesoria SET estado = 'confirmada', mp_payment_id = :pago_id, pagado_en = NOW(), monto = :monto
     WHERE id = :id AND estado != 'confirmada'"
);
$stmt->execute([':pago_id' => $paymentId, ':id' => $citaId, ':monto' => (float)($pago['transaction_amount'] ?? 0)]);

if ($stmt->rowCount() === 0) {
    // Ya estaba confirmada (aviso duplicado) o perdió la carrera contra otro
    // aviso simultáneo — no hay que volver a mandar el WhatsApp.
    mp_webhook_responder(200);
}

// Si el horario original ya pasó, no hay ninguna fecha válida que mostrar
// como "agendada" -- ver los mensajes de abajo, que usan $citaYaPaso en
// vez de $horarioTexto en ese caso.
$horarioTexto = $citaYaPaso
    ? 'pendiente de que la clienta elija nueva fecha'
    : citas_formatear_fecha_hora($cita['fecha'], substr($cita['hora_inicio'], 0, 5));

// Mientras el bot ofrecía horarios y generaba el link de pago solo, esta
// persona nunca se guardó en Prospectos (para no llenarle la lista al
// despacho con interesados que no habían pagado todavía) — ya que sí pagó,
// recién aquí se registra, con los datos reales de la cita, y se pausa el
// bot (a partir de aquí un humano se encarga de preparar y hacer la llamada).
//
// Bug real detectado en producción: como este es el PRIMER guardado del
// prospecto, el "Resumen del caso" se quedaba solo con "Asesoría pagada...
// y agendada para..." — sin ningún dato de qué le pasó a la persona,
// aunque ya lo hubiera contado con detalle en la conversación (el abogado
// llegaba a la llamada sin haber leído nada real del caso). Se rescata el
// primer mensaje real que mandó el cliente (la descripción original de su
// caso) para que el resumen sí diga algo útil.
$stmtPrimerMsg = $pdo->prepare(
    "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY creado_en ASC LIMIT 1"
);
$stmtPrimerMsg->execute([':t' => $cita['telefono']]);
$primerMensaje = trim((string)($stmtPrimerMsg->fetchColumn() ?: ''));

$resumenPago = $citaYaPaso
    ? "Asesoría pagada (\${$cita['monto']} MXN) -- el pago llegó después de la fecha original ({$cita['fecha']} {$cita['hora_inicio']}), ya vencida. Se le mandaron horarios disponibles para que elija uno nuevo; falta confirmar con ella cuál eligió y actualizar la cita."
    : "Asesoría pagada (\${$cita['monto']} MXN) y agendada para {$horarioTexto}.";
$resumenCompleto = $primerMensaje !== ''
    ? $resumenPago . ' Consulta original del cliente: "' . mb_strimwidth($primerMensaje, 0, 300, '…') . '"'
    : $resumenPago;

guardar_prospecto($pdo, $cita['telefono'], $cita['nombre_cliente'], [
    'tipo' => 'asesoria_paga',
    'estado' => '',
    'nombre' => $cita['nombre_cliente'] ?? '',
    'resumen' => $resumenCompleto,
], true, true);

// Se avisa directo al abogado que le tocó la cita (no al "asignado" del
// prospecto, que normalmente está vacío en este punto) — es quien tiene
// que hacer la llamada.
$notaPush = $citaYaPaso ? ' (pago llegó tarde, la fecha original ya pasó -- se le mandaron horarios para que elija, falta confirmar cuál con ella)' : '';
push_enviar_a_usuario(
    $pdo,
    (int)$cita['usuario_id'],
    '¡Pago confirmado!',
    ($cita['nombre_cliente'] ?: $cita['telefono']) . ' — asesoría agendada para ' . $horarioTexto . $notaPush,
    '/sistema/?abrir=' . urlencode($cita['telefono'])
);

// Se agenda sola en el Google Calendar del abogado que le tocó la cita —
// sin esto, solo se sincronizaba cuando alguien entraba al sistema y le
// daba clic a "Sincronizar ahora". Si no hay una fecha válida todavía
// (la clienta no ha elegido su nuevo horario), no hay nada que
// sincronizar por ahora -- se hace cuando se actualice la cita a mano.
if (!$citaYaPaso) {
    google_sincronizar_cita_pagada($pdo, $cita, $horarioTexto);
}

if ($citaYaPaso) {
    if ($horariosParaElegir) {
        $listaHorarios = '';
        foreach ($horariosParaElegir as $i => $h) {
            $listaHorarios .= ($i + 1) . ". {$h['texto']}\n";
        }
        $mensaje = "¡Tu pago quedó confirmado! Como la fecha que teníamos agendada ya pasó, aquí tienes los horarios disponibles para tu asesoría -- dime cuál te acomoda mejor:\n{$listaHorarios}En cuanto me confirmes cuál prefieres, te la agendamos.";
    } else {
        $mensaje = '¡Tu pago quedó confirmado! Como tu cita original ya había pasado y no encontramos horarios disponibles en este momento, un abogado del despacho te va a contactar directo por este mismo WhatsApp para coordinar un nuevo horario para tu asesoría telefónica de 1 hora.';
    }
} else {
    $mensaje = "¡Tu pago quedó confirmado! Tu asesoría telefónica de 1 hora queda agendada para el {$horarioTexto}. El Lic. Rubén Buerhend te va a llamar a este mismo número de WhatsApp a esa hora — por favor ten tu teléfono a la mano.\n\nImportante: este horario ya quedó reservado especialmente para ti, así que no es posible reprogramarlo antes de la cita. Y si no fue posible contactarte en 2 intentos a la hora acordada, tampoco aplica devolución del pago ni reprogramación.\n\nCualquier cosa antes, aquí mismo nos puedes escribir.";
}
whatsapp_enviar($cita['telefono'], $mensaje);

$stmt = $pdo->prepare(
    "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
);
$stmt->execute([':t' => $cita['telefono'], ':texto' => $mensaje]);

mp_webhook_responder(200);
