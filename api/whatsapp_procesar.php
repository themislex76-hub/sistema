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

    $resultado = ia_responder_whatsapp($pdo, $mensajesIA);
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
