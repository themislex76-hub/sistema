<?php
declare(strict_types=1);

// Lógica compartida para procesar un mensaje entrante de WhatsApp (llamar a
// la IA, decidir si es lead, contestar). La usan tanto whatsapp_webhook.php
// (Meta llamando directo) como whatsapp_relay.php (cuando Meta llama a
// través de un puente externo porque el hosting bloquea la conexión
// directa — ver docs/DEPLOY_CPANEL.md).

require_once __DIR__ . '/prospectos_helpers.php';

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

    $resultado = ia_responder_whatsapp($pdo, $mensajesIA, $telefono);
    $respuesta = $resultado['texto'];
    $lead = $resultado['lead'];

    // Un lead de despido no tiene nada más que el bot pueda hacer — se
    // guarda de inmediato como prospecto y se avisa que un humano contacta
    // directo. Uno de asesoría paga NO se guarda aquí todavía: el bot ya
    // trae los horarios/link de pago dentro de $respuesta (ver
    // ofrecer_horarios_asesoria/confirmar_horario_asesoria en
    // ia_helpers.php) y sigue el flujo solo — para no llenarle Prospectos
    // al despacho con interesados que todavía no pagan, esos leads solo se
    // guardan si el flujo automático se atora o si el pago se confirma
    // (ver ia_registrar_prospecto_atorado en ia_helpers.php y
    // mercadopago_webhook.php).
    if ($lead && $lead['tipo'] === 'despido') {
        $respuesta .= "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo.";
        guardar_prospecto($pdo, $telefono, $nombrePerfil, $lead);
    }

    whatsapp_enviar($telefono, $respuesta);

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $respuesta]);
}

// Reintenta contestar una conversación cuyo último mensaje fue la
// respuesta de emergencia (IA_FALLBACK_TEXTO) — típicamente porque en ese
// momento la API de Claude falló (sin saldo, credencial inválida, etc.).
// Se usa desde "Conversaciones (WhatsApp)" para recuperar esos casos una
// vez arreglado el problema, sin esperar a que el cliente vuelva a escribir.
// Devuelve ['ok' => bool, 'motivo' => string] — motivo solo se llena si ok=false.
function reintentar_conversacion_fallida(PDO $pdo, string $telefono): array
{
    // Si un humano ya tomó el caso, no lo interrumpimos con una respuesta
    // automática — mismo criterio que procesar_mensaje_entrante().
    $stmt = $pdo->prepare('SELECT pausado_bot FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();
    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        return ['ok' => false, 'motivo' => 'Un humano ya está atendiendo esta conversación.'];
    }

    $stmt = $pdo->prepare('SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
    $stmt->execute([':t' => $telefono]);
    $historial = array_reverse($stmt->fetchAll());
    if (!$historial) {
        return ['ok' => false, 'motivo' => 'No hay mensajes para este número.'];
    }

    // Antes de corregir el flujo de varias rondas, el texto de emergencia a
    // veces salía pegado con un texto genérico adicional (p. ej. leads de
    // asesoría vieja) — por eso se compara con "empieza con" y no con
    // igualdad exacta, para poder recuperar también esos casos viejos.
    $ultimo = end($historial);
    if ($ultimo['direccion'] !== 'saliente' || strpos($ultimo['texto'], IA_FALLBACK_TEXTO) !== 0) {
        return ['ok' => false, 'motivo' => 'Esta conversación ya tiene una respuesta real; no hace falta reintentar.'];
    }

    // El historial que se manda a Claude debe terminar en un mensaje del
    // cliente (role=user) — se descarta la respuesta de emergencia previa.
    array_pop($historial);
    $mensajesIA = [];
    foreach ($historial as $h) {
        $mensajesIA[] = [
            'role' => $h['direccion'] === 'entrante' ? 'user' : 'assistant',
            'content' => $h['texto'],
        ];
    }
    if (!$mensajesIA || end($mensajesIA)['role'] !== 'user') {
        return ['ok' => false, 'motivo' => 'No se encontró un mensaje del cliente pendiente de contestar.'];
    }

    $resultado = ia_responder_whatsapp($pdo, $mensajesIA, $telefono);
    $respuesta = $resultado['texto'];
    $lead = $resultado['lead'];

    if ($respuesta === IA_FALLBACK_TEXTO) {
        return ['ok' => false, 'motivo' => 'La IA sigue sin poder contestar (revisa las credenciales o el saldo de Anthropic).'];
    }

    if ($lead && $lead['tipo'] === 'despido') {
        $respuesta .= "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo.";
        guardar_prospecto($pdo, $telefono, null, $lead);
    }

    if (!whatsapp_enviar($telefono, $respuesta)) {
        return ['ok' => false, 'motivo' => 'No se pudo enviar el mensaje por WhatsApp.'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $respuesta]);

    return ['ok' => true, 'motivo' => ''];
}
