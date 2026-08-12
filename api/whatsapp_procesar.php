<?php
declare(strict_types=1);

// Lógica compartida para procesar un mensaje entrante de WhatsApp (llamar a
// la IA, decidir si es lead, contestar). La usan tanto whatsapp_webhook.php
// (Meta llamando directo) como whatsapp_relay.php (cuando Meta llama a
// través de un puente externo porque el hosting bloquea la conexión
// directa — ver docs/DEPLOY_CPANEL.md).

require_once __DIR__ . '/prospectos_helpers.php';
require_once __DIR__ . '/push_helpers.php';

// Protege contra un número que abuse del bot (spam, pruebas, troleo) y
// dispare el gasto de IA sin control — 30 mensajes entrantes en 24 horas
// es generoso para cualquier conversación real (hasta un caso complicado
// de despido rara vez pasa de 15-20 mensajes del cliente), pero corta a
// alguien mandando decenas de mensajes seguidos sin sentido.
const WHATSAPP_LIMITE_MENSAJES_DIA = 30;

// Horario de atención: lunes a sábado, 8:00-19:00 hora de Ciudad de México
// (date_default_timezone_set ya se fija globalmente en db.php, que carga
// antes que este archivo en la cadena de whatsapp_webhook.php). Domingo
// cerrado todo el día. Fuera de este horario no se contesta como si un
// humano estuviera despierto a las 3am — se manda un aviso de "fuera de
// horario" genérico, igual que cualquier negocio, en vez de simular
// presencia en tiempo real.
const WHATSAPP_MENSAJE_FUERA_HORARIO = 'Gracias por escribir a Expertos Laborales Abogados. Te recordamos que nuestro horario de atención es de lunes a sábado de 8:00 am a 7:00 pm — en cuanto uno de nuestros abogados pueda, con gusto te contestamos.';

function dentro_de_horario_atencion(): bool
{
    $diaSemana = (int)date('N'); // 1=lunes ... 7=domingo
    if ($diaSemana === 7) {
        return false;
    }
    $hora = (int)date('G');
    return $hora >= 8 && $hora < 19;
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
    $stmt = $pdo->prepare('SELECT pausado_bot, estatus, asignado_a, nombre FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();

    // Un candidato (prospecto) ya registrado que vuelve a escribir —
    // avisa siempre, esté o no pausado el bot (si está pausado es
    // justo cuando más hace falta que un humano se entere). Los
    // descartados no avisan: el bot los sigue atendiendo solo.
    if ($prospecto && $prospecto['estatus'] !== 'descartado') {
        push_notificar_prospecto(
            $pdo,
            $prospecto['asignado_a'] !== null ? (int)$prospecto['asignado_a'] : null,
            'Nuevo mensaje de ' . ($prospecto['nombre'] ?: $telefono),
            mb_strimwidth($texto, 0, 140, '…'),
            '/sistema/?abrir=' . urlencode($telefono)
        );
    }

    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        return;
    }

    if (!dentro_de_horario_atencion()) {
        $stmt = $pdo->prepare(
            "SELECT creado_en FROM whatsapp_conversaciones
             WHERE telefono = :t AND direccion = 'saliente' AND texto = :texto
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':t' => $telefono, ':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
        $ultimoAviso = $stmt->fetch();
        $yaAvisado = $ultimoAviso && strtotime((string)$ultimoAviso['creado_en']) >= time() - 6 * 3600;
        if (!$yaAvisado) {
            whatsapp_enviar($telefono, WHATSAPP_MENSAJE_FUERA_HORARIO);
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $stmt->execute([':t' => $telefono, ':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
        }
        return;
    }

    // Ventana móvil de 24 horas (no por día de calendario) — cuenta el
    // mensaje que se acaba de insertar arriba.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM whatsapp_conversaciones
         WHERE telefono = :t AND direccion = 'entrante' AND creado_en >= :desde"
    );
    $stmt->execute([':t' => $telefono, ':desde' => date('Y-m-d H:i:s', time() - 86400)]);
    $mensajesUltimas24h = (int)$stmt->fetch()['n'];
    if ($mensajesUltimas24h > WHATSAPP_LIMITE_MENSAJES_DIA) {
        if ($mensajesUltimas24h === WHATSAPP_LIMITE_MENSAJES_DIA + 1) {
            // Justo se pasó del límite — se avisa UNA sola vez; los
            // mensajes de después de este se ignoran en silencio (no hace
            // falta seguir gastando IA ni repitiendo el aviso).
            $avisoLimite = 'Por hoy ya platicamos bastante — dale chance a que un abogado revise tu caso directo. En cuanto pueda te contacta. ¡Gracias por tu paciencia!';
            whatsapp_enviar($telefono, $avisoLimite);
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $stmt->execute([':t' => $telefono, ':texto' => $avisoLimite]);
        }
        return;
    }

    // Indicador nativo de WhatsApp "escribiendo..." mientras se genera la
    // respuesta — y punto de partida para medir cuánto tardó todo el
    // proceso, para el retraso natural de abajo.
    $messageId = (string)($msg['id'] ?? '');
    if ($messageId !== '') {
        whatsapp_marcar_leido_y_escribiendo($messageId);
    }
    $tiempoInicio = microtime(true);

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
        // Si ya existía el prospecto, el aviso de "nuevo mensaje" de arriba
        // ya cubrió este caso — este es solo para un candidato nuevo.
        if (!$prospecto) {
            push_notificar_prospecto($pdo, null, 'Nuevo prospecto de despido', $lead['nombre'] ?: $telefono, '/sistema/?abrir=' . urlencode($telefono));
        }
    }

    // Retraso natural: simula el tiempo que tardaría alguien en escribir
    // la respuesta (~12 caracteres/segundo), restando lo que ya tardó la
    // llamada a la IA — así no se siente como una respuesta instantánea
    // de bot, sin hacer esperar de más si la IA ya tardó bastante.
    $segundosDeseados = min(20, max(3, mb_strlen($respuesta) / 12));
    $segundosFaltantes = $segundosDeseados - (microtime(true) - $tiempoInicio);
    if ($segundosFaltantes > 0) {
        usleep((int)($segundosFaltantes * 1_000_000));
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
        if (!$prospecto) {
            push_notificar_prospecto($pdo, null, 'Nuevo prospecto de despido', $lead['nombre'] ?: $telefono, '/sistema/?abrir=' . urlencode($telefono));
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
