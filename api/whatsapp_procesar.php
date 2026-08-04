<?php
declare(strict_types=1);

// Lógica compartida para procesar un mensaje entrante de WhatsApp (llamar a
// la IA, decidir si es lead, contestar). La usan tanto whatsapp_webhook.php
// (Meta llamando directo) como whatsapp_relay.php (cuando Meta llama a
// través de un puente externo porque el hosting bloquea la conexión
// directa — ver docs/DEPLOY_CPANEL.md).

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

    if ($lead) {
        // Un lead de despido no tiene nada más que el bot pueda hacer —
        // siempre se avisa que un humano contacta directo. Uno de
        // asesoría paga YA trae los horarios/link de pago dentro de
        // $respuesta (ver ofrecer_horarios_asesoria/confirmar_horario_asesoria
        // en ia_helpers.php), así que no hace falta ningún texto extra aquí.
        if ($lead['tipo'] === 'despido') {
            $respuesta .= "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo.";
        }
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

    $ultimo = end($historial);
    if ($ultimo['direccion'] !== 'saliente' || $ultimo['texto'] !== IA_FALLBACK_TEXTO) {
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

    if ($lead) {
        if ($lead['tipo'] === 'despido') {
            $respuesta .= "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo.";
        }
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

function guardar_prospecto(PDO $pdo, string $telefono, ?string $nombrePerfil, array $lead): void
{
    $nombre = $lead['nombre'] !== '' ? $lead['nombre'] : $nombrePerfil;
    $estado = $lead['estado'] !== '' ? $lead['estado'] : null;
    // Un lead de despido nunca se degrada a asesoría paga si llega uno
    // nuevo después — es el más valioso de los dos (posible cliente de
    // litigio, no solo de una consulta de una hora).
    //
    // Un lead de despido SÍ pausa el bot de inmediato (necesita intake
    // humano real). Uno de asesoría paga NO se pausa aquí todavía — se
    // deja que el bot siga solo para ofrecer horarios y generar el link
    // de pago (ver ia_helpers.php); se pausa hasta que el pago se
    // confirme (mercadopago_webhook.php) o si ya no hay nada automático
    // que hacer (ia_pausar_prospecto). Si ya estaba pausado a mano por un
    // abogado, este UPDATE nunca lo vuelve a activar solo.
    $pausar = $lead['tipo'] === 'despido' ? 1 : 0;
    $stmt = $pdo->prepare(
        "INSERT INTO prospectos (telefono, tipo, nombre, estado_ubicacion, resumen_caso, pausado_bot)
         VALUES (:t, :tipo, :nombre, :estado, :resumen, :pausar)
         ON DUPLICATE KEY UPDATE
           tipo = IF(tipo = 'despido', 'despido', VALUES(tipo)),
           nombre = COALESCE(VALUES(nombre), nombre),
           estado_ubicacion = COALESCE(VALUES(estado_ubicacion), estado_ubicacion),
           resumen_caso = VALUES(resumen_caso),
           pausado_bot = IF(tipo = 'despido' OR VALUES(tipo) = 'despido', 1, pausado_bot)"
    );
    $stmt->execute([
        ':t' => $telefono,
        ':tipo' => $lead['tipo'],
        ':nombre' => $nombre,
        ':estado' => $estado,
        ':resumen' => $lead['resumen'],
        ':pausar' => $pausar,
    ]);
}
