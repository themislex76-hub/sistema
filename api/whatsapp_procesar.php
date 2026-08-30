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

// Cuántos segundos esperar, antes de llamar a la IA, a ver si el mismo
// número manda más mensajes seguidos (muy común en WhatsApp: la gente
// parte una idea en varias burbujas cortas -- "10", "20 dias", "si señor"
// -- en vez de un solo párrafo). Sin esto, cada burbuja disparaba su
// propia llamada completa a la IA y el cliente recibía una respuesta
// distinta por cada una -- se detectó en producción a un cliente que
// mandó su explicación en 6 mensajes seguidos y el bot le contestó 6
// veces, con textos distintos entre sí (6x el gasto de IA para un solo
// intercambio real). Ver el bloque "espera para agrupar" más abajo.
const WHATSAPP_ESPERA_AGRUPAR_SEGUNDOS = 8;

// Horario de atención: todos los días, 8:00-19:00 hora de Ciudad de México
// (date_default_timezone_set ya se fija globalmente en db.php, que carga
// antes que este archivo en la cadena de whatsapp_webhook.php). Domingo
// tiene el mismo horario que el resto de la semana — cerrarlo perdía leads
// que escriben en fin de semana sin necesidad, ya que la IA no depende de
// que haya un humano despierto para contestar bien. Fuera de este horario
// (noche/madrugada) no se contesta como si alguien estuviera despierto a
// las 3am — se manda un aviso de "fuera de horario" genérico, igual que
// cualquier negocio, en vez de simular presencia en tiempo real.
const WHATSAPP_MENSAJE_FUERA_HORARIO = 'Gracias por escribir a Expertos Laborales Abogados. Te recordamos que nuestro horario de atención es de 8:00 am a 7:00 pm — en cuanto uno de nuestros abogados pueda, con gusto te contestamos.';

function dentro_de_horario_atencion(): bool
{
    $hora = (int)date('G');
    return $hora >= 8 && $hora < 19;
}

// Tipos de mensaje de WhatsApp CON archivo adjunto que sí guardamos, para
// que un abogado los revise (típicamente un comprobante de pago) -- audio,
// video, stickers y ubicación quedan fuera de alcance por ahora y caen en
// el aviso genérico de "no puedo leer esto" más abajo.
const WHATSAPP_TIPOS_MEDIA_SOPORTADOS = ['image', 'document'];

function whatsapp_extension_por_mime(string $mime): string
{
    $mapa = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    return $mapa[$mime] ?? 'bin';
}

// Respuesta cuando se guarda un archivo pero NO se escala a un humano
// (ver más abajo el criterio) -- deja claro que el bot no revisa
// documentos por este medio, para no generar la expectativa de que
// alguien le va a hacer una revisión gratis a un contrato u otro
// documento que nada tiene que ver con un pago.
const WHATSAPP_MENSAJE_ARCHIVO_SIN_CONTEXTO_PAGO = 'Recibí tu archivo, ya quedó guardado. Por este medio el asistente automático no puede leer ni revisar el contenido de documentos (como contratos) -- si necesitas que un abogado lo revise a fondo, eso es parte de la asesoría personalizada. Mientras tanto, ¿en qué más te puedo ayudar?';

// Un cliente mandó una imagen o documento -- antes esto se perdía por
// completo: el bot solo contestaba "no puedo leer esto" y el archivo
// nunca quedaba guardado en ningún lado. Se detectó en producción con un
// cliente que insistía en haber mandado el comprobante de un pago y nadie
// del despacho pudo verlo nunca, porque de verdad no se guardaba nada.
// Ahora se descarga de los servidores de Meta y se guarda siempre para
// que un abogado lo pueda revisar desde Conversaciones/Prospectos.
//
// El bot NO interpreta el contenido del archivo (no se lo manda a la IA)
// -- por costo, y porque un comprobante hay que revisarlo con cuidado, no
// que lo "lea" un modelo y decida solo.
//
// Pero guardarlo no significa siempre interrumpir a un humano: si
// cualquier archivo (una foto, un "revísame este contrato" de alguien que
// nunca ha pagado nada) pausara el bot y mandara una alerta urgente, el
// despacho se llenaría de avisos que no son nada urgente -- solo se
// escala de verdad cuando hay un pago pendiente de cobrar de por medio
// (el escenario real: alguien insistiendo en que ya pagó su asesoría). En
// cualquier otro caso, el archivo queda guardado y visible igual, pero el
// bot sigue la conversación normal, dejando claro que no revisa
// documentos por este medio.
function procesar_media_entrante(PDO $pdo, string $telefono, array $msg, string $tipo, ?string $nombrePerfil): void
{
    $messageId = (string)($msg['id'] ?? '');
    $mediaInfo = $msg[$tipo] ?? [];
    $mediaId = (string)($mediaInfo['id'] ?? '');
    $caption = trim((string)($mediaInfo['caption'] ?? ''));
    $nombreArchivo = trim((string)($mediaInfo['filename'] ?? ''));
    $textoPlaceholder = $caption !== ''
        ? $caption
        : ($tipo === 'image' ? '(imagen adjunta)' : '(documento adjunto' . ($nombreArchivo !== '' ? ': ' . $nombreArchivo : '') . ')');

    $idPropio = null;
    if ($messageId !== '') {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por, whatsapp_message_id) VALUES (:t, 'entrante', :texto, 'ia', :mid)"
            );
            $stmt->execute([':t' => $telefono, ':texto' => $textoPlaceholder, ':mid' => $messageId]);
            $idPropio = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return; // Reintento de Meta del mismo archivo -- ya se guardó antes.
            }
            throw $e;
        }
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'entrante', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => $textoPlaceholder]);
        $idPropio = (int)$pdo->lastInsertId();
    }

    if ($mediaId === '') {
        whatsapp_enviar($telefono, 'Recibí tu archivo, pero hubo un problema técnico leyéndolo — ¿me lo puedes volver a mandar, por favor?');
        return;
    }

    $descarga = whatsapp_descargar_media($mediaId);
    if ($descarga === null) {
        whatsapp_enviar($telefono, 'Recibí tu archivo, pero hubo un problema técnico descargándolo de nuestro lado. Un abogado del despacho te va a contactar directo por esto.');
        ia_registrar_prospecto_atorado($pdo, $telefono, null, 'Mandó un archivo (' . $tipo . ') pero hubo un error técnico al descargarlo -- pedirle que lo reenvíe o resolverlo directo con la persona.', $nombrePerfil);
        return;
    }

    $ext = whatsapp_extension_por_mime($descarga['mime_type']);
    $carpetaTelefono = preg_replace('/[^0-9A-Za-z]/', '', $telefono) ?: 'sin_numero';
    $dirCarpeta = __DIR__ . '/../data/whatsapp_media/' . $carpetaTelefono;
    if (!is_dir($dirCarpeta)) {
        @mkdir($dirCarpeta, 0755, true);
    }
    $nombreDisco = $idPropio . '.' . $ext;
    file_put_contents($dirCarpeta . '/' . $nombreDisco, $descarga['bytes']);
    $rutaRelativa = $carpetaTelefono . '/' . $nombreDisco;

    $stmt = $pdo->prepare('UPDATE whatsapp_conversaciones SET media_ruta = :ruta, media_mime = :mime WHERE id = :id');
    $stmt->execute([':ruta' => $rutaRelativa, ':mime' => $descarga['mime_type'], ':id' => $idPropio]);

    // ¿Hay algún pago pendiente de cobrar para este número? Es la única
    // señal de contexto que tenemos para saber si este archivo es
    // probablemente un comprobante de pago (lo urgente de verdad) y no
    // solo un documento cualquiera.
    $stmt = $pdo->prepare("SELECT id FROM citas_asesoria WHERE telefono = :t AND estado = 'pendiente_pago' LIMIT 1");
    $stmt->execute([':t' => $telefono]);
    $tienePagoPendiente = (bool)$stmt->fetch();

    if ($tienePagoPendiente) {
        whatsapp_enviar($telefono, 'Recibí tu archivo — un abogado del despacho lo va a revisar directamente contigo. 🙏');
        ia_registrar_prospecto_atorado($pdo, $telefono, null, 'Mandó un archivo (' . $tipo . ') con un pago de asesoría pendiente de cobrar -- probable comprobante, revisarlo en Conversaciones (WhatsApp) o Prospectos.', $nombrePerfil);
    } else {
        whatsapp_enviar($telefono, WHATSAPP_MENSAJE_ARCHIVO_SIN_CONTEXTO_PAGO);
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => WHATSAPP_MENSAJE_ARCHIVO_SIN_CONTEXTO_PAGO]);
    }
}

// Convierte el historial de whatsapp_conversaciones (una fila por mensaje)
// al arreglo que espera la API de Claude, fusionando mensajes CONSECUTIVOS
// del mismo rol en un solo turno. Hace falta porque la API de Anthropic
// exige que los roles alternen entre "user" y "assistant" -- sin esto, un
// bloque de varios mensajes seguidos del cliente (ver la espera para
// agrupar en procesar_mensaje_entrante) llegaría como varios "user"
// consecutivos y la llamada fallaría.
function ia_mensajes_desde_historial(array $historial): array
{
    $mensajes = [];
    foreach ($historial as $h) {
        $role = $h['direccion'] === 'entrante' ? 'user' : 'assistant';
        if ($mensajes && end($mensajes)['role'] === $role) {
            $mensajes[count($mensajes) - 1]['content'] .= "\n" . $h['texto'];
        } else {
            $mensajes[] = ['role' => $role, 'content' => $h['texto']];
        }
    }
    return $mensajes;
}

function procesar_mensaje_entrante(PDO $pdo, array $msg, ?string $nombrePerfil): void
{
    $telefono = (string)($msg['from'] ?? '');
    if ($telefono === '') return;

    $tipoMensaje = (string)($msg['type'] ?? '');
    if (in_array($tipoMensaje, WHATSAPP_TIPOS_MEDIA_SOPORTADOS, true)) {
        procesar_media_entrante($pdo, $telefono, $msg, $tipoMensaje, $nombrePerfil);
        return;
    }
    if ($tipoMensaje !== 'text') {
        whatsapp_enviar($telefono, 'Por ahora solo puedo leer mensajes de texto, imágenes y documentos. Cuéntame tu duda escribiéndola, por favor.');
        return;
    }

    $texto = trim((string)($msg['text']['body'] ?? ''));
    if ($texto === '') return;

    // Deduplicación: Meta puede reintentar la entrega del mismo webhook si
    // no le respondemos a tiempo (el retraso natural + el indicador de
    // "escribiendo..." + la llamada a la IA pueden sumar varios segundos)
    // — sin esto, cada reintento procesaba el mensaje de nuevo: gastaba IA
    // de más y el cliente veía la misma respuesta repetida varias veces.
    // Se detecta con un índice único sobre el id real del mensaje que
    // manda Meta: si el INSERT choca porque ya existe, es un reintento
    // del mismo mensaje, no uno nuevo — se corta aquí, antes de gastar
    // nada de IA.
    $messageId = (string)($msg['id'] ?? '');
    $idPropio = null;
    if ($messageId !== '') {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por, whatsapp_message_id) VALUES (:t, 'entrante', :texto, 'ia', :mid)"
            );
            $stmt->execute([':t' => $telefono, ':texto' => $texto, ':mid' => $messageId]);
            $idPropio = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    } else {
        // Sin id (no debería pasar con un mensaje real de Meta, pero por
        // si acaso) — se aplica un dedup de respaldo por contenido: si el
        // mensaje entrante más reciente de este mismo teléfono, hace
        // segundos, tiene el texto EXACTO, es casi seguro un reintento
        // duplicado (nadie manda el mismo párrafo largo dos veces
        // seguidas en cuestión de segundos), no un mensaje nuevo.
        $chkDup = $pdo->prepare(
            "SELECT id FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' AND texto = :texto AND creado_en >= :desde ORDER BY id DESC LIMIT 1"
        );
        $chkDup->execute([':t' => $telefono, ':texto' => $texto, ':desde' => date('Y-m-d H:i:s', time() - 30)]);
        if ($chkDup->fetch()) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'entrante', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => $texto]);
        $idPropio = (int)$pdo->lastInsertId();
    }

    // Si ya hay un prospecto y el bot está pausado, un humano lleva el
    // caso: no autorespondemos, solo quedó guardado el mensaje para que
    // el abogado lo vea y conteste desde la vista Prospectos.
    $stmt = $pdo->prepare('SELECT pausado_bot, estatus, asignado_a, nombre, tipo FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();

    // Un candidato (prospecto) de DESPIDO ya registrado que vuelve a
    // escribir — avisa siempre, esté o no pausado el bot (si está pausado
    // es justo cuando más hace falta que un humano se entere). Los
    // descartados no avisan: el bot los sigue atendiendo solo. Los de
    // asesoría de pago NO avisan aquí — ese flujo lo sigue el bot solo
    // (horarios, link de pago), y si de verdad se atora, ya avisa aparte
    // (ver ia_registrar_prospecto_atorado) — avisar en cada mensaje suyo
    // sería ruido innecesario al celular.
    if ($prospecto && $prospecto['estatus'] !== 'descartado' && $prospecto['tipo'] === 'despido') {
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

    // Si el cliente manda literalmente el mismo texto (no trivial) 3
    // veces seguidas, es señal de que el texto no le está funcionando —
    // seguir mandándolo a la IA solo repite la misma pregunta y gasta
    // saldo sin avanzar. En vez de insistir, se pasa directo a un humano.
    if (mb_strlen($texto) > 15) {
        $stmt = $pdo->prepare(
            "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 3"
        );
        $stmt->execute([':t' => $telefono]);
        $ultimosTresEntrantes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ultimosTresEntrantes) === 3 && count(array_unique($ultimosTresEntrantes)) === 1) {
            $mensajeAtorado = 'Veo que me escribiste lo mismo varias veces — para no hacerte perder más tiempo, mejor pido que un abogado del despacho te contacte directo y lo platiquen con calma. En breve te buscan. 🙏';
            whatsapp_enviar($telefono, $mensajeAtorado);
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $stmt->execute([':t' => $telefono, ':texto' => $mensajeAtorado]);
            guardar_prospecto($pdo, $telefono, $nombrePerfil, [
                'tipo' => 'reclamo',
                'estado' => '',
                'nombre' => '',
                'resumen' => 'Conversación atorada: el cliente repitió el mismo mensaje varias veces sin que el bot lograra avanzar con las preguntas de calificación — contactar directo por teléfono. Último mensaje: ' . mb_strimwidth($texto, 0, 200, '…'),
            ], true);
            if (!$prospecto) {
                push_notificar_prospecto($pdo, null, 'Conversación atorada — contactar directo', mb_strimwidth($texto, 0, 140, '…'), '/sistema/?abrir=' . urlencode($telefono));
            }
            return;
        }
    }

    // Respaldo determinístico: si el cliente insiste en que ya pagó Y hay
    // un pago de asesoría pendiente de cobrar para este número, se escala
    // de inmediato SIN depender de que la IA decida llamar
    // escalar_a_humano -- se detectó en producción que el modelo a veces
    // solo REDACTA "ya avisé a un abogado" sin de verdad llamar la
    // herramienta (la misma falla que causó el problema real con un
    // cliente que insistía en haber pagado y nadie del despacho se
    // enteró nunca). Esta red de seguridad no depende de que la IA se
    // acuerde -- si el patrón se repite, se corta aquí y se escala solo,
    // sin ni siquiera llamar a la IA.
    $pareceReclamoDePago = preg_match('/\bya\b.{0,20}pag/iu', $texto) === 1
        || preg_match('/\bya\b.{0,20}(deposit|transfer)/iu', $texto) === 1;
    if ($pareceReclamoDePago) {
        // Registro de depuración -- se detectaron varias pruebas reales
        // donde este bloque parecía no dispararse y no había forma de
        // saber, sin esto, si de verdad no se estaba llegando a correr
        // este código (archivo viejo en el servidor) o si sí corría pero
        // no encontraba ninguna cita que justificara escalar. Revisa
        // api/whatsapp_send_debug.log después de una prueba real.
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [respaldo_pago] patrón detectado de $telefono | texto=\"" . mb_strimwidth($texto, 0, 80, '…') . "\"\n", FILE_APPEND);
        // No solo "pendiente_pago ahora mismo" -- el caso real que motivó
        // esto (un cliente insistiendo días después de que su link expiró)
        // ya no tenía la cita en pendiente_pago para entonces, solo
        // expirada/cancelada. Cualquier cita suya que nunca se pagó de
        // verdad cuenta -- pero si YA tiene una cita realmente confirmada
        // (pagada de verdad), no se dispara: ahí sí ya pagó y no hace
        // falta ninguna advertencia.
        $stmt = $pdo->prepare(
            "SELECT id FROM citas_asesoria WHERE telefono = :t AND estado IN ('pendiente_pago', 'expirada', 'cancelada') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':t' => $telefono]);
        $tieneCitaSinPagar = (bool)$stmt->fetch();
        $yaConfirmado = false;
        if ($tieneCitaSinPagar) {
            $stmt = $pdo->prepare("SELECT id FROM citas_asesoria WHERE telefono = :t AND estado = 'confirmada' LIMIT 1");
            $stmt->execute([':t' => $telefono]);
            $yaConfirmado = (bool)$stmt->fetch();
        }
        if ($tieneCitaSinPagar && !$yaConfirmado) {
            $mensajeSinConfirmacion = 'Por aquí todavía no me aparece tu pago confirmado en el sistema -- en cuanto Mercado Pago lo registre, el abogado te contacta directo. Ya le avisé para que revise tu caso. 🙏';
            whatsapp_enviar($telefono, $mensajeSinConfirmacion);
            $stmt = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $stmt->execute([':t' => $telefono, ':texto' => $mensajeSinConfirmacion]);
            ia_registrar_prospecto_atorado(
                $pdo, $telefono,
                ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''],
                'Insiste en que ya pagó su asesoría pero no hay confirmación de Mercado Pago -- revisar y aclarar con la persona.',
                $nombrePerfil
            );
            return;
        }
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [respaldo_pago] NO escaló -- tieneCitaSinPagar=" . ($tieneCitaSinPagar ? 'si' : 'no')
            . " yaConfirmado=" . ($yaConfirmado ? 'si' : 'no') . " (sigue a la IA normal)\n", FILE_APPEND);
    }

    // Espera para agrupar: si la persona sigue escribiendo (varias burbujas
    // seguidas), se le da tiempo antes de gastar una llamada de IA. Al
    // terminar la espera se checa si llegó un mensaje MÁS NUEVO de este
    // mismo número mientras tanto -- si sí, esta invocación se retira sin
    // contestar (ni gastar IA): la más reciente hará este mismo checeo y
    // será la que junte todo el bloque y conteste una sola vez. $idPropio
    // puede quedar null solo si el INSERT de arriba no llegó a correr
    // (no debería pasar a estas alturas), en cuyo caso se sigue de todos
    // modos en vez de arriesgarse a nunca contestar.
    if ($idPropio !== null) {
        sleep(WHATSAPP_ESPERA_AGRUPAR_SEGUNDOS);
        $stmt = $pdo->prepare(
            "SELECT id FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':t' => $telefono]);
        $masReciente = $stmt->fetch();
        if ($masReciente && (int)$masReciente['id'] !== $idPropio) {
            return;
        }
    }

    // Indicador nativo de WhatsApp "escribiendo..." mientras se genera la
    // respuesta — y punto de partida para medir cuánto tardó todo el
    // proceso, para el retraso natural de abajo.
    if ($messageId !== '') {
        whatsapp_marcar_leido_y_escribiendo($messageId);
    }
    $tiempoInicio = microtime(true);

    $stmt = $pdo->prepare('SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
    $stmt->execute([':t' => $telefono]);
    $historial = array_reverse($stmt->fetchAll());

    $mensajesIA = ia_mensajes_desde_historial($historial);
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
        // Si ya era prospecto de DESPIDO, el aviso de "nuevo mensaje" de
        // arriba ya cubrió este caso. Pero si era nuevo, o si ya existía
        // como prospecto de asesoría de pago y ahora se convierte en un
        // caso de despido real, sí hay que avisar aquí — ese aviso de
        // arriba no dispara para tipo=asesoria_paga.
        if (!$prospecto || $prospecto['tipo'] !== 'despido') {
            push_notificar_prospecto($pdo, null, 'Nuevo prospecto de despido', $lead['nombre'] ?: $telefono, '/sistema/?abrir=' . urlencode($telefono));
        }
    }

    // Retraso natural antes de contestar: entre 20 y 28s (con variación
    // al azar), restando lo que ya tardó la llamada a la IA. Tope duro de
    // 28s: WhatsApp/Meta espera la confirmación del webhook en poco
    // tiempo — pasarse mucho de ahí arriesga que reintente el mensaje y
    // se duplique la respuesta, así que este es el máximo que se
    // considera seguro dentro de este mecanismo (una espera mucho más
    // larga y realista necesitaría contestar en segundo plano, no aquí).
    $segundosBase = max(20, mb_strlen($respuesta) / 9) + random_int(-1, 3);
    $segundosDeseados = min(28, max(20, $segundosBase));
    $segundosFaltantes = $segundosDeseados - (microtime(true) - $tiempoInicio);
    if ($segundosFaltantes > 0) {
        // Si la espera pasa de 15s, se vuelve a activar el "escribiendo..."
        // a la mitad — el indicador nativo de WhatsApp dura máximo 25s y,
        // si no se renueva, desaparece antes de que llegue la respuesta.
        if ($segundosFaltantes > 15 && $messageId !== '') {
            usleep((int)(($segundosFaltantes - 15) * 1_000_000));
            whatsapp_marcar_leido_y_escribiendo($messageId);
            usleep(15 * 1_000_000);
        } else {
            usleep((int)($segundosFaltantes * 1_000_000));
        }
    }

    whatsapp_enviar($telefono, $respuesta);

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $respuesta]);

    // El PDF del cálculo (si lo hubo) se manda AL FINAL, después del
    // texto — nunca al mismo tiempo, para que no se sienta como un envío
    // automatizado de golpe. Retraso corto (4-9s) en vez de otro de
    // 20-28s completo: ya se esperó lo normal para el texto, esto es
    // nada más la sensación de "ahora te mando el PDF".
    if ($resultado['pdf_calculo'] !== null) {
        usleep(random_int(4, 9) * 1_000_000);
        whatsapp_enviar_pdf_calculo($telefono, $resultado['pdf_calculo']['calc'], $resultado['pdf_calculo']['salario_diario']);
    }
}

// Contesta automáticamente, en cuanto abre el horario de atención, la
// pregunta de un cliente que se quedó sin respuesta real porque escribió
// fuera de horario (solo recibió el aviso automático) — para que no se
// quede colgado esperando hasta que él mismo vuelva a escribir. Pensada
// para correr desde un Cron Job — ver cron_reanudar_horario.php.
// Devuelve ['ok' => bool, 'motivo' => string].
function reanudar_conversacion_fuera_horario(PDO $pdo, string $telefono): array
{
    $stmt = $pdo->prepare('SELECT pausado_bot, tipo FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();
    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        // pausado_bot=1 no significa que alguien esté contestando en este
        // momento — significa que ya es un prospecto real (normalmente un
        // lead de despido) y el bot se hizo a un lado a propósito para que
        // un abogado le dé seguimiento personal. Puede llevar ahí un rato
        // sin que nadie lo haya visto todavía — revisar Prospectos (WhatsApp).
        return ['ok' => false, 'motivo' => 'Ya es un prospecto registrado (bot pausado a propósito) — pendiente de seguimiento personal en Prospectos (WhatsApp), no de una respuesta automática.'];
    }

    $stmt = $pdo->prepare('SELECT direccion, texto, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
    $stmt->execute([':t' => $telefono]);
    $historial = array_reverse($stmt->fetchAll());
    if (!$historial) {
        return ['ok' => false, 'motivo' => 'No hay mensajes para este número.'];
    }

    // Quita el/los avisos automáticos de "fuera de horario" del final —
    // no son parte real de la conversación, nada más se mandaron mientras
    // estaba cerrado.
    while ($historial && end($historial)['direccion'] === 'saliente' && end($historial)['texto'] === WHATSAPP_MENSAJE_FUERA_HORARIO) {
        array_pop($historial);
    }
    if (!$historial) {
        return ['ok' => false, 'motivo' => 'No queda nada pendiente que contestar.'];
    }

    // WhatsApp/Meta rechaza mandar un mensaje libre si ya pasaron más de 24h
    // desde el último mensaje del cliente -- sin este freno, un número que
    // quedó así (por lo que sea nunca se le contestó a tiempo) se queda
    // "atorado" para siempre: cada corrida de este cron (cada 15-30 min, sin
    // parar) volvía a llamar a la IA completa (prompt grande, hasta 4
    // rondas) para terminar fallando el envío de todas formas, sin nunca
    // resolverse -- un gasto real que solo crecía con cada número nuevo que
    // caía en esta trampa. Se corta ANTES de gastar nada en la IA.
    $ultimoEntrante = null;
    for ($i = count($historial) - 1; $i >= 0; $i--) {
        if ($historial[$i]['direccion'] === 'entrante') {
            $ultimoEntrante = $historial[$i]['creado_en'];
            break;
        }
    }
    if ($ultimoEntrante !== null && (time() - strtotime($ultimoEntrante)) > 23 * 3600) {
        return ['ok' => false, 'motivo' => 'Ya pasaron más de 23h desde el último mensaje del cliente -- WhatsApp ya no deja mandar un mensaje libre. No se llamó a la IA.'];
    }

    $mensajesIA = ia_mensajes_desde_historial($historial);
    if (!$mensajesIA || end($mensajesIA)['role'] !== 'user') {
        return ['ok' => false, 'motivo' => 'No se encontró un mensaje del cliente pendiente de contestar.'];
    }

    $resultado = ia_responder_whatsapp($pdo, $mensajesIA, $telefono);
    $respuesta = $resultado['texto'];
    $lead = $resultado['lead'];

    if ($respuesta === IA_FALLBACK_TEXTO) {
        return ['ok' => false, 'motivo' => 'La IA no pudo contestar (revisa credenciales/saldo de Anthropic).'];
    }

    if ($lead && $lead['tipo'] === 'despido') {
        $respuesta .= "\n\nPor lo que me cuentas, un abogado del despacho te va a contactar en breve para revisar tu caso a detalle, sin costo.";
        if (!$prospecto || $prospecto['tipo'] !== 'despido') {
            push_notificar_prospecto($pdo, null, 'Nuevo prospecto de despido', $lead['nombre'] ?: $telefono, '/sistema/?abrir=' . urlencode($telefono));
        }
        guardar_prospecto($pdo, $telefono, null, $lead);
    }

    if (!whatsapp_enviar($telefono, $respuesta)) {
        return ['ok' => false, 'motivo' => 'No se pudo enviar el mensaje por WhatsApp (revisa whatsapp_send_debug.log — puede ser que ya pasaron más de 24h desde su último mensaje).'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
    );
    $stmt->execute([':t' => $telefono, ':texto' => $respuesta]);

    if ($resultado['pdf_calculo'] !== null) {
        sleep(random_int(4, 9));
        whatsapp_enviar_pdf_calculo($telefono, $resultado['pdf_calculo']['calc'], $resultado['pdf_calculo']['salario_diario']);
    }

    return ['ok' => true, 'motivo' => ''];
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
    $stmt = $pdo->prepare('SELECT pausado_bot, tipo FROM prospectos WHERE telefono = :t LIMIT 1');
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
    $mensajesIA = ia_mensajes_desde_historial($historial);
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
        if (!$prospecto || $prospecto['tipo'] !== 'despido') {
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

    if ($resultado['pdf_calculo'] !== null) {
        sleep(random_int(4, 9));
        whatsapp_enviar_pdf_calculo($telefono, $resultado['pdf_calculo']['calc'], $resultado['pdf_calculo']['salario_diario']);
    }

    return ['ok' => true, 'motivo' => ''];
}
