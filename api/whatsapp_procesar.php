<?php
declare(strict_types=1);

// Lógica compartida para procesar un mensaje entrante de WhatsApp (llamar a
// la IA, decidir si es lead, contestar). La usan tanto whatsapp_webhook.php
// (Meta llamando directo) como whatsapp_relay.php (cuando Meta llama a
// través de un puente externo porque el hosting bloquea la conexión
// directa — ver docs/DEPLOY_CPANEL.md).

require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/prospectos_helpers.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/soporte_tecnico_helpers.php';

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
// Subido de 8 a 18 segundos -- bug real detectado en producción: alguien
// preguntó el precio de la asesoría y, unos segundos después (más de 8,
// menos de 18), mandó su nombre en una burbuja aparte ("Mi nombre es
// Kevin Rojas") -- como pasaron más de 8 segundos, la primera burbuja ya
// se había alcanzado a contestar sola, y la segunda generó una SEGUNDA
// respuesta casi idéntica (repitiendo el precio) antes de que llegara la
// tercera, ya correcta, que sí agrupaba ambos mensajes. 18 segundos cubre
// mejor ese patrón real (preguntar algo y mandar el nombre poco después)
// sin alargar demasiado la espera de alguien que solo manda un mensaje.
const WHATSAPP_ESPERA_AGRUPAR_SEGUNDOS = 18;

// Horario de atención: todos los días, 8:00-19:00 hora de Ciudad de México.
// Domingo tiene el mismo horario que el resto de la semana — cerrarlo
// perdía leads que escriben en fin de semana sin necesidad, ya que la IA no
// depende de que haya un humano despierto para contestar bien. Fuera de
// este horario (noche/madrugada) no se contesta como si alguien estuviera
// despierto a las 3am — se manda un aviso de "fuera de horario" genérico,
// igual que cualquier negocio, en vez de simular presencia en tiempo real.
// dentro_de_horario_atencion() vive en whatsapp_helpers.php (único punto de
// verdad, también lo usan los crons de seguimiento).
const WHATSAPP_MENSAJE_FUERA_HORARIO = 'Gracias por escribir a Expertos Laborales Abogados. Te recordamos que nuestro horario de atención es de 8:00 am a 7:00 pm — en cuanto uno de nuestros abogados pueda, con gusto te contestamos.';

// Manda el aviso de "fuera de horario" si corresponde (una sola vez cada
// 6h por número) y devuelve true si el llamador debe cortar aquí sin
// seguir procesando -- true tanto si se acaba de mandar el aviso como si
// ya se había mandado hace poco. Compartida entre el flujo de texto y el
// de archivos para que ambos respeten exactamente el mismo criterio.
function whatsapp_avisar_fuera_horario_si_aplica(PDO $pdo, string $telefono): bool
{
    if (dentro_de_horario_atencion()) {
        return false;
    }
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
    return true;
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

// Un cliente mandó una imagen o documento -- antes esto se perdía por
// completo: el bot solo contestaba "no puedo leer esto" y el archivo
// nunca quedaba guardado en ningún lado. Se detectó en producción con un
// cliente que insistía en haber mandado el comprobante de un pago y nadie
// del despacho pudo verlo nunca, porque de verdad no se guardaba nada.
// Ahora se descarga de los servidores de Meta y se guarda siempre para
// que un abogado lo pueda revisar desde Conversaciones/Prospectos.
//
// Un comprobante de pago nunca se le manda a la IA para que decida sola
// -- eso siempre lo revisa un humano con cuidado (si hay un pago
// pendiente de cobrar de por medio, o el propio caption ya se parece a un
// reclamo -- ver whatsapp_texto_parece_reclamo, más abajo). Cualquier
// OTRO archivo (contrato, recibo de nómina, acta) sí se le manda a la IA
// como imagen/PDF para que siga la conversación con su contenido en vez
// de solo guardarlo -- antes se guardaba pero nadie llegaba a revisarlo a
// tiempo, así que en la práctica quedaba sin leer.
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
        // No debería pasar con un mensaje real de Meta -- se registra el
        // payload completo para poder diagnosticarlo si vuelve a ocurrir.
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . ' | [media_sin_id] tel=' . $telefono . ' | ' . json_encode($msg, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        whatsapp_enviar($telefono, 'Recibí tu archivo, pero hubo un problema técnico leyéndolo — ¿me lo puedes volver a mandar, por favor?');
        ia_registrar_prospecto_atorado($pdo, $telefono, ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''], 'Mandó un archivo (' . $tipo . ') pero hubo un error técnico leyéndolo -- pedirle que lo reenvíe o resolverlo directo con la persona.', $nombrePerfil);
        return;
    }

    $descarga = whatsapp_descargar_media($mediaId);
    if ($descarga === null) {
        whatsapp_enviar($telefono, 'Recibí tu archivo, pero hubo un problema técnico descargándolo de nuestro lado. Un abogado del despacho te va a contactar directo por esto.');
        ia_registrar_prospecto_atorado($pdo, $telefono, ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''], 'Mandó un archivo (' . $tipo . ') pero hubo un error técnico al descargarlo -- pedirle que lo reenvíe o resolverlo directo con la persona.', $nombrePerfil);
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

    // ¿Hay algún pago pendiente de cobrar para este número? Es una señal
    // de contexto fuerte de que este archivo es probablemente un
    // comprobante de pago (lo urgente de verdad) y no solo un documento
    // cualquiera.
    $stmt = $pdo->prepare("SELECT id FROM citas_asesoria WHERE telefono = :t AND estado = 'pendiente_pago' LIMIT 1");
    $stmt->execute([':t' => $telefono]);
    $tienePagoPendiente = (bool)$stmt->fetch();

    // Además del pago pendiente, el propio caption puede ya ser un
    // reclamo (mismo criterio que para un mensaje de texto normal -- ver
    // whatsapp_texto_parece_reclamo) -- ej. "aquí está mi comprobante, YA
    // PAGUÉ y nadie me contesta". El Lic. Buerhend solo necesita
    // enterarse de los archivos que de verdad son parte de un reclamo, no
    // de cualquier foto o documento que le manden.
    $captionPareceReclamo = $caption !== '' && whatsapp_texto_parece_reclamo($caption);

    // Un archivo con contexto de pago/reclamo nunca se manda a la IA --
    // eso se queda como decisión de un humano siempre. Aquí sí puede
    // llegar más de un archivo en ráfaga (cada uno en su propia llamada al
    // webhook, sin "espera para agrupar"), así que sin este freno el mismo
    // aviso se mandaba una vez por archivo -- se detectó en producción a
    // alguien mandando 6 documentos y recibiendo "Recibí tu documento..."
    // 5 veces seguidas, lo más robótico que hay. Se evita mandando el
    // aviso solo si no se le mandó ya ese mismo texto en el último minuto.
    $ventanaAvisoArchivo = date('Y-m-d H:i:s', time() - 60);

    if ($tienePagoPendiente || $captionPareceReclamo) {
        $textoAviso = 'Recibí tu archivo — un abogado del despacho lo va a revisar directamente contigo. 🙏';
        $stmtChk = $pdo->prepare(
            "SELECT 1 FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'saliente' AND texto = :texto AND creado_en >= :desde ORDER BY id DESC LIMIT 1"
        );
        $stmtChk->execute([':t' => $telefono, ':texto' => $textoAviso, ':desde' => $ventanaAvisoArchivo]);
        if (!$stmtChk->fetch()) {
            whatsapp_enviar($telefono, $textoAviso);
            $motivo = $tienePagoPendiente
                ? 'con un pago de asesoría pendiente de cobrar -- probable comprobante'
                : 'con un caption que se parece a un reclamo';
            ia_registrar_prospecto_atorado($pdo, $telefono, ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''], 'Mandó un archivo (' . $tipo . ') ' . $motivo . ' -- revisarlo en Conversaciones (WhatsApp) o Prospectos.', $nombrePerfil);
        }
        return;
    }

    // Sin contexto de pago/reclamo: en vez del aviso genérico de "lo
    // revisaremos" (que en la práctica nadie llegaba a revisar -- no hay
    // tiempo de entrar a ver cada documento uno por uno), el archivo se le
    // manda a la IA como parte normal de la conversación, igual que un
    // mensaje de texto -- mismos filtros (bloqueado, bot pausado, horario)
    // y misma espera para agrupar (ver ia_generar_y_responder), para que
    // varios archivos seguidos se contesten una sola vez con todos juntos
    // en contexto, no uno por uno.
    $stmtBloqueadoMedia = $pdo->prepare('SELECT 1 FROM numeros_bloqueados WHERE telefono = :t');
    $stmtBloqueadoMedia->execute([':t' => $telefono]);
    if ($stmtBloqueadoMedia->fetchColumn()) {
        return;
    }
    $stmtProspectoMedia = $pdo->prepare('SELECT pausado_bot FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmtProspectoMedia->execute([':t' => $telefono]);
    $prospectoMedia = $stmtProspectoMedia->fetch();
    if ($prospectoMedia && (int)$prospectoMedia['pausado_bot'] === 1) {
        return;
    }
    if (whatsapp_avisar_fuera_horario_si_aplica($pdo, $telefono)) {
        return;
    }

    ia_generar_y_responder($pdo, $telefono, $messageId, $idPropio);
}

// Límites de tamaño para mandarle un archivo a la API de Claude como
// imagen/documento -- más grande que esto y se omite el archivo (se queda
// solo el texto/placeholder de la fila, degradando con calma en vez de
// fallar la llamada completa). Bastante por debajo de los límites reales
// de la API (esos son más generosos) -- el margen es a propósito, para no
// quedar pegados justo en el límite con el peso real del payload JSON
// completo (historial + imagen en base64 + prompt + tools).
const IA_MEDIA_MAX_BYTES_IMAGEN = 5 * 1024 * 1024;
const IA_MEDIA_MAX_BYTES_DOCUMENTO = 25 * 1024 * 1024;

// Convierte una fila de whatsapp_conversaciones en los bloques de
// contenido que espera la API de Claude -- normalmente un solo bloque de
// texto, pero si la fila tiene un archivo adjunto (media_ruta/media_mime,
// ver procesar_media_entrante) y es un tipo que Claude puede leer
// (imagen, o PDF como documento), se le agrega el archivo real como
// bloque aparte para que la IA vea su contenido, no solo el nombre. Otros
// tipos de archivo (Word, Excel, etc.) o archivos que ya no se
// encuentran en disco se quedan solo con el texto -- degradación
// silenciosa, no un error.
function ia_bloques_desde_fila(array $fila): array
{
    $bloques = [];
    $texto = (string)($fila['texto'] ?? '');
    if ($texto !== '') {
        $bloques[] = ['type' => 'text', 'text' => $texto];
    }

    $mediaRuta = (string)($fila['media_ruta'] ?? '');
    $mediaMime = (string)($fila['media_mime'] ?? '');
    if ($mediaRuta !== '' && $mediaMime !== '') {
        $esImagen = str_starts_with($mediaMime, 'image/');
        $esPdf = $mediaMime === 'application/pdf';
        if ($esImagen || $esPdf) {
            $rutaCompleta = __DIR__ . '/../data/whatsapp_media/' . $mediaRuta;
            $tamano = is_file($rutaCompleta) ? filesize($rutaCompleta) : false;
            $limite = $esPdf ? IA_MEDIA_MAX_BYTES_DOCUMENTO : IA_MEDIA_MAX_BYTES_IMAGEN;
            if ($tamano !== false && $tamano > 0 && $tamano <= $limite) {
                $datos = base64_encode((string)file_get_contents($rutaCompleta));
                $bloques[] = [
                    'type' => $esPdf ? 'document' : 'image',
                    'source' => ['type' => 'base64', 'media_type' => $mediaMime, 'data' => $datos],
                ];
            }
        }
    }

    if (!$bloques) {
        $bloques[] = ['type' => 'text', 'text' => '(mensaje vacío)'];
    }
    return $bloques;
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
        $bloques = ia_bloques_desde_fila($h);
        if ($mensajes && end($mensajes)['role'] === $role) {
            $ultimoIdx = count($mensajes) - 1;
            $mensajes[$ultimoIdx]['content'] = array_merge($mensajes[$ultimoIdx]['content'], $bloques);
        } else {
            $mensajes[] = ['role' => $role, 'content' => $bloques];
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

    // Número bloqueado (ver numeros_bloquear.php) -- el mensaje ya quedó
    // guardado arriba para no perder el historial, pero el bot NUNCA le
    // contesta solo a un número bloqueado. Aun así SÍ se avisa al
    // despacho (igual que un reclamo) para no perder de vista el caso
    // por completo -- "bloqueado" es "ya no le contesto yo mismo", no
    // "ignóralo del todo". Esto va antes que cualquier otra lógica
    // (prospecto, horario de atención, IA) porque tiene que ganarles a
    // todas sin excepción.
    $stmtBloqueado = $pdo->prepare('SELECT 1 FROM numeros_bloqueados WHERE telefono = :t');
    $stmtBloqueado->execute([':t' => $telefono]);
    if ($stmtBloqueado->fetchColumn()) {
        ia_registrar_prospecto_atorado(
            $pdo, $telefono,
            ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''],
            '🚫 Número bloqueado volvió a escribir: "' . mb_strimwidth($texto, 0, 150, '…') . '"',
            $nombrePerfil
        );
        return;
    }

    // Si ya hay un prospecto y el bot está pausado, un humano lleva el
    // caso: no autorespondemos, solo quedó guardado el mensaje para que
    // el abogado lo vea y conteste desde la vista Prospectos.
    $stmt = $pdo->prepare('SELECT pausado_bot, estatus, asignado_a, nombre, tipo FROM prospectos WHERE telefono = :t LIMIT 1');
    $stmt->execute([':t' => $telefono]);
    $prospecto = $stmt->fetch();

    // Cualquier prospecto con el bot pausado (un humano lo está llevando,
    // o ya no hay nada que el bot pueda seguir haciendo solo -- pago ya
    // confirmado y cita agendada, reclamo ya escalado, etc.) avisa en
    // CADA mensaje nuevo que escriba, sin importar el tipo -- es justo
    // cuando más hace falta que un humano se entere, porque el bot ya no
    // le va a contestar nada. Bug real detectado: antes solo avisaba
    // para tipo='despido' -- alguien con una asesoría YA PAGADA (bot
    // pausado desde ese momento) podía escribir de nuevo y quedarse sin
    // ninguna notificación, invisible hasta que alguien entrara a
    // revisar Prospectos por su cuenta. Los descartados no avisan: el
    // bot los sigue atendiendo solo. Mientras el bot SIGUE llevando el
    // flujo solo (pausado_bot=0, ej. una asesoría de pago que todavía no
    // se paga) no se avisa aquí -- sería ruido innecesario al celular;
    // si de verdad se atora, ya avisa aparte (ver
    // ia_registrar_prospecto_atorado).
    if ($prospecto && $prospecto['estatus'] !== 'descartado' && (int)$prospecto['pausado_bot'] === 1) {
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

    if (whatsapp_avisar_fuera_horario_si_aplica($pdo, $telefono)) {
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

    // Respaldo determinístico y GENERAL para reclamos -- no depende de que
    // la IA decida llamar escalar_a_humano (se detectó en producción,
    // repetidas veces, que el modelo solo REDACTA "ya avisé a un abogado"
    // sin de verdad llamar la herramienta, dejando al cliente esperando
    // una escalación que nunca pasó). Esto corta ANTES de llamar a la IA
    // en cuanto el mensaje se parece a un reclamo real -- acusación de
    // fraude/estafa, amenaza de exhibir al despacho, exigir devolución, o
    // insistir en que ya pagó -- sin condición extra (no hace falta que
    // haya una cita de por medio: un reclamo es un reclamo aunque no sea
    // sobre un pago). Contesta con un mensaje fijo, pausa el bot y avisa
    // a un humano, siempre.
    $pareceReclamo = whatsapp_texto_parece_reclamo($texto);
    if ($pareceReclamo) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [respaldo_reclamo] escalando de $telefono | texto=\"" . mb_strimwidth($texto, 0, 80, '…') . "\"\n", FILE_APPEND);
        $mensajeEscalado = 'Entiendo tu molestia -- voy a avisarle de inmediato a un abogado del despacho para que revise tu caso directamente contigo. En breve te contacta por este mismo WhatsApp. 🙏';
        whatsapp_enviar($telefono, $mensajeEscalado);
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => $mensajeEscalado]);
        ia_registrar_prospecto_atorado(
            $pdo, $telefono,
            ['tipo' => 'reclamo', 'estado' => '', 'nombre' => '', 'resumen' => ''],
            'Reclamo detectado automáticamente -- revisar la conversación completa. Último mensaje: ' . mb_strimwidth($texto, 0, 200, '…'),
            $nombrePerfil
        );
        return;
    }

    // Soporte técnico del sistema Control de Expedientes -- MISMO número
    // de WhatsApp que el bot de asesoría laboral (se decidió no abrir uno
    // aparte), pero completamente separado en prompt/herramientas/modelo
    // (ver soporte_tecnico_helpers.php) para no mezclar los dos dominios.
    // Detección determinística por el mismo motivo que el reclamo de
    // arriba: nunca se le pregunta a la IA si esto es soporte técnico.
    if (soporte_parece_pregunta_tecnica($texto)) {
        $stmt = $pdo->prepare('SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
        $stmt->execute([':t' => $telefono]);
        $historialSoporte = array_reverse($stmt->fetchAll());
        $mensajesSoporte = ia_mensajes_desde_historial($historialSoporte);
        if (!$mensajesSoporte || end($mensajesSoporte)['role'] !== 'user') {
            $mensajesSoporte[] = ['role' => 'user', 'content' => $texto];
        }
        $respuestaSoporte = soporte_responder($pdo, $telefono, $mensajesSoporte, $nombrePerfil);
        whatsapp_enviar($telefono, $respuestaSoporte);
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => $respuestaSoporte]);
        return;
    }

    // Patrón real detectado revisando conversaciones completas: cuando el
    // bot ya se había despedido y la persona solo contesta con un
    // agradecimiento/cierre corto ("gracias", "ok", un emoji), el bot le
    // seguía mandando OTRA despedida completa -- se veían cadenas de hasta
    // 15 mensajes seguidos de "gracias"/"con gusto", algo que ningún
    // humano hace. Si el mensaje nuevo es un cierre simple y el ÚLTIMO
    // mensaje saliente ya sonaba a despedida, no se contesta nada más (se
    // corta aquí, antes de gastar IA).
    if (whatsapp_texto_es_cierre_simple($texto)) {
        $stmtUltimoSaliente = $pdo->prepare(
            "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'saliente' ORDER BY id DESC LIMIT 1"
        );
        $stmtUltimoSaliente->execute([':t' => $telefono]);
        $ultimoSaliente = (string)($stmtUltimoSaliente->fetchColumn() ?: '');
        if (whatsapp_texto_parece_despedida($ultimoSaliente)) {
            return;
        }
    }

    ia_generar_y_responder($pdo, $telefono, $messageId, $idPropio);
}

// Genera la respuesta de la IA para lo último que llegó de este número
// (mensaje de texto O archivo) y la manda -- extraído de
// procesar_mensaje_entrante() para que procesar_media_entrante() use
// exactamente el mismo mecanismo (espera para agrupar, doble revisión
// anti-duplicado, retraso natural) en vez de un envío inmediato aparte.
// $idPropio es el id de la fila (texto o archivo) que disparó esta
// llamada -- puede ser null solo si el INSERT correspondiente no llegó a
// correr, en cuyo caso se sigue de todos modos en vez de arriesgarse a
// nunca contestar.
function ia_generar_y_responder(PDO $pdo, string $telefono, string $messageId, ?int $idPropio): void
{
    // Espera para agrupar: si la persona sigue escribiendo (varias burbujas
    // seguidas, o mandando varios archivos), se le da tiempo antes de
    // gastar una llamada de IA. Al terminar la espera se checa si llegó
    // algo MÁS NUEVO de este mismo número mientras tanto -- si sí, esta
    // invocación se retira sin contestar (ni gastar IA): la más reciente
    // hará este mismo checeo y será la que junte todo el bloque y conteste
    // una sola vez.
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

    $stmt = $pdo->prepare('SELECT direccion, texto, media_ruta, media_mime FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
    $stmt->execute([':t' => $telefono]);
    $historial = array_reverse($stmt->fetchAll());

    $mensajesIA = ia_mensajes_desde_historial($historial);
    if (!$mensajesIA || end($mensajesIA)['role'] !== 'user') {
        // No debería pasar -- el mensaje/archivo que disparó esta llamada
        // ya quedó insertado en whatsapp_conversaciones antes de llegar
        // aquí, así que el historial siempre debería terminar en 'user'.
        return;
    }

    $resultado = ia_responder_whatsapp($pdo, $mensajesIA, $telefono);
    $respuesta = $resultado['texto'];

    // Ya no hay contacto gratis para nadie (el despacho lo eliminó por
    // completo) -- todo interesado se empuja a la asesoría de pago. Un
    // lead de asesoría paga NO se guarda aquí todavía: el bot ya trae los
    // horarios/link de pago dentro de $respuesta (ver
    // ofrecer_horarios_asesoria/confirmar_horario_asesoria en
    // ia_helpers.php) y sigue el flujo solo -- para no llenarle Prospectos
    // al despacho con interesados que todavía no pagan, esos leads solo se
    // guardan si el flujo automático se atora o si el pago se confirma
    // (ver ia_registrar_prospecto_atorado en ia_helpers.php y
    // mercadopago_webhook.php).

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

    // Segunda revisión, justo antes de mandar -- la primera (arriba, antes
    // de llamar a la IA) solo cubre los primeros WHATSAPP_ESPERA_AGRUPAR_SEGUNDOS
    // segundos, pero el proceso completo (llamada a la IA + el retraso
    // natural de 20-28s de más abajo) tarda 35-45 segundos en total. Bug
    // real detectado en producción: docenas de clientes recibieron 2
    // respuestas casi idénticas seguidas en un solo día porque mandaban una
    // segunda burbuja DESPUÉS de los primeros segundos pero DENTRO de esos
    // 35-45s totales, y nadie volvía a checar hasta que ya era tarde. Si ya
    // hay algo más nuevo, esta respuesta quedó desactualizada -- se
    // descarta sin mandarla (ni guardarla ni mandar su PDF si traía): el
    // procesamiento del mensaje nuevo, que sí va a incluir este último
    // mensaje en su historial completo, es el que contesta.
    if ($idPropio !== null) {
        $stmtRecheck = $pdo->prepare(
            "SELECT id FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 1"
        );
        $stmtRecheck->execute([':t' => $telefono]);
        $masRecienteAhora = $stmtRecheck->fetch();
        if ($masRecienteAhora && (int)$masRecienteAhora['id'] !== $idPropio) {
            return;
        }
    }

    whatsapp_enviar_respuesta($pdo, $telefono, $respuesta);

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

    $stmt = $pdo->prepare('SELECT direccion, texto, creado_en, media_ruta, media_mime FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
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

    if ($respuesta === IA_FALLBACK_TEXTO) {
        return ['ok' => false, 'motivo' => 'La IA no pudo contestar (revisa credenciales/saldo de Anthropic).'];
    }

    if (!whatsapp_enviar_respuesta($pdo, $telefono, $respuesta)) {
        return ['ok' => false, 'motivo' => 'No se pudo enviar el mensaje por WhatsApp (revisa whatsapp_send_debug.log — puede ser que ya pasaron más de 24h desde su último mensaje).'];
    }

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

    $stmt = $pdo->prepare('SELECT direccion, texto, media_ruta, media_mime FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id DESC LIMIT 20');
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

    if ($respuesta === IA_FALLBACK_TEXTO) {
        return ['ok' => false, 'motivo' => 'La IA sigue sin poder contestar (revisa las credenciales o el saldo de Anthropic).'];
    }

    if (!whatsapp_enviar_respuesta($pdo, $telefono, $respuesta)) {
        return ['ok' => false, 'motivo' => 'No se pudo enviar el mensaje por WhatsApp.'];
    }

    if ($resultado['pdf_calculo'] !== null) {
        sleep(random_int(4, 9));
        whatsapp_enviar_pdf_calculo($telefono, $resultado['pdf_calculo']['calc'], $resultado['pdf_calculo']['salario_diario']);
    }

    return ['ok' => true, 'motivo' => ''];
}
