<?php
declare(strict_types=1);

// Envío de mensajes de texto por WhatsApp Cloud API (Meta), usando el
// número dedicado configurado en whatsapp_credentials.php.

// Horario de atención: todos los días, 8:00-19:00 hora de Ciudad de México
// (date_default_timezone_set ya se fija globalmente en db.php). Único punto
// de verdad para "está abierto el despacho" -- lo usa tanto
// whatsapp_procesar.php (para contestar o no en tiempo real) como los crons
// de seguimiento (cron_seguimiento_calculadora.php, cron_seguimiento_cursos.php),
// para que ningún mensaje saliente (proactivo o de respuesta) se mande fuera
// de este rango. Antes cada script tenía su propio límite de hora (uno
// cortaba a las 19h, otro a las 21h, y uno no revisaba la hora en
// absoluto) -- eso causaba que un cron le escribiera a un cliente a las
// 8:30pm y, en cuanto contestaba, el bot le respondía con el aviso de
// "estamos cerrados", como si no lo conociera.
function dentro_de_horario_atencion(): bool
{
    $hora = (int)date('G');
    return $hora >= 8 && $hora < 19;
}

// WhatsApp solo deja que el despacho le escriba primero a alguien (texto
// libre, imagen, documento, lo que sea) si esa persona escribió en las
// últimas 24 horas -- fuera de esa ventana, Meta "acepta" la petición al
// instante (por eso el sistema pensaba que sí se mandó) pero la entrega
// falla después, en silencio, y solo se ve horas más tarde en
// whatsapp_send_debug.log (error 131047 "Re-engagement message"). Bug
// real detectado en producción: un abogado mandó un PDF a un cliente que
// no escribía desde hace 5 días, el sistema dijo "enviado" y nunca le
// llegó. Esta función se usa para avisar ANTES de intentar el envío, en
// vez de fallar en silencio.
function whatsapp_dentro_ventana_24h(PDO $pdo, string $telefono): bool
{
    $stmt = $pdo->prepare(
        "SELECT creado_en FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':t' => $telefono]);
    $ultimo = $stmt->fetchColumn();
    if (!$ultimo) return false;
    return strtotime((string)$ultimo) >= time() - 24 * 3600;
}

// Respaldo determinístico y GENERAL para reclamos -- no depende de que la
// IA decida llamar escalar_a_humano (se detectó en producción, repetidas
// veces, que el modelo solo REDACTA "ya avisé a un abogado" sin de verdad
// llamar la herramienta, dejando al cliente esperando una escalación que
// nunca pasó). Detecta si un texto se parece a un reclamo real --
// acusación de fraude/estafa, amenaza de exhibir al despacho, exigir
// devolución, o insistir en que ya pagó -- sin condición extra (no hace
// falta que haya una cita de por medio: un reclamo es un reclamo aunque
// no sea sobre un pago).
//
// Extraída de procesar_mensaje_entrante() (whatsapp_procesar.php) para
// poder aplicar el mismo criterio también al caption de una imagen o
// documento (ver procesar_media_entrante) -- antes un archivo con un
// caption como "aquí está mi comprobante, YA PAGUÉ y nadie me contesta"
// solo escalaba si de casualidad había una cita con pago pendiente
// registrada; con esto, el propio texto del caption basta.
function whatsapp_texto_parece_reclamo(string $texto): bool
{
    return
        // "fraude/estafa/robo/engaño" NO cuenta si el cliente está
        // describiendo una acusación que ÉL recibió (de su jefe, en su
        // trabajo) -- ej. "me culparon de un fraude", "me acusaron de
        // robo y me despidieron" -- eso es información normal de su
        // caso, no una queja contra el despacho. Tampoco cuenta si habla
        // de fraudes telefónicos en general (ej. "por los fraudes ya no
        // contesto números desconocidos") -- eso es contexto cultural,
        // no una acusación contra nosotros.
        // Bug real detectado en producción: "es un robo" (sin \b al
        // final) hacía match dentro de la palabra "robot" -- alguien
        // preguntó "¿Eres un robot?" y el sistema lo tomó como una
        // acusación de robo/fraude, contestándole el mensaje de
        // escalación en vez de la pregunta real que hizo. Mismo tipo de
        // bug con "enga[ñn]" (sin \b al inicio ni negación de "ch"):
        // hacía match dentro de "que TENGAN buen día"/"que VENGAN" (un
        // cierre de conversación normalísimo) y dentro de "ENGANCHE"
        // (anticipo de un carro, nada que ver con fraude) -- escalaba a
        // un humano despedidas corteses y menciones de enganches.
        (preg_match('/estafa|fraude|\benga[ñn](?!ch)|es un robo\b/iu', $texto) === 1
            && preg_match('/me (culp(an|aron)?|acus(an|aron)?|despidieron|corrieron).{0,30}(fraude|estafa|robo|\benga[ñn](?!ch))|(fraude|estafa|robo|\benga[ñn](?!ch)).{0,30}me (culp|acus)|(jefe|patr[oó]n|empresa|trabajo).{0,30}(fraude|estafa|robo|\benga[ñn](?!ch))|(contest(amos?|an|o)|llamada|tel[eé]fono|n[uú]mero).{0,60}(fraude|estafa)|(fraude|estafa).{0,60}(contest(amos?|an|o)|llamada|tel[eé]fono|n[uú]mero)/iu', $texto) !== 1)
        // "tiktok"/"redes sociales" solos NO cuentan -- un cliente real
        // puede decir "lo vi en tiktok" sin ninguna amenaza. Solo cuenta
        // si va junto con un verbo de amenaza (exhibir/exponer/publicar/
        // denunciar/quemar), en cualquier orden.
        || preg_match('/(exhib|expon|public|denunci|quem).{0,40}(tik\s*tok|redes sociales)|(tik\s*tok|redes sociales).{0,40}(exhib|expon|public|denunci|quem)|voy a (publicar|denunciar|quemar|exponer|exhibir)/iu', $texto) === 1
        // "devolución"/"reembolso" solos NO cuentan -- un cliente puede
        // mencionar una devolución ajena dentro de su propio caso (ej. "un
        // proveedor no generó la devolución de un pago de arrendamiento"
        // narrando su despido) sin que sea un reclamo contra el despacho.
        // Solo cuenta si está en primera persona, sobre SU dinero.
        || preg_match('/mi\s+(devoluci[oó]n|reembolso)|(devoluci[oó]n|reembolso)\s+de\s+mi\s+(pago|dinero|asesor[ií]a)|regr[eé]same mi dinero|quiero mi dinero|no me han (devuelto|reembolsado)|me (devuelvan|reembolsen)\b/iu', $texto) === 1
        // REGLA DURA: "ya" tiene que estar pegado a un verbo de pago en
        // primera persona (ya pagué/deposité/transferí) -- no basta con
        // que "ya" y "pag" aparezcan cerca por cualquier motivo (ej. "no
        // firmé YA QUE dije que me PAGaran" es una conjunción normal, no
        // una afirmación de pago, y no debe escalar).
        || preg_match('/\bya\s+(te\s+|le\s+|les\s+)?(pagu[eé]|deposit[eé]|transfer[ií])\b/iu', $texto) === 1
        // Caso real detectado en producción: alguien escribió "Ya esta el
        // pago solo quiero que se me confirme" -- no calzaba con el
        // patrón de arriba (no es "ya pagué", es "ya está el pago"), así
        // que se coló al flujo normal de la IA en vez de escalar aquí, y
        // la IA terminó confirmándole el pago/cita sin haberlo verificado
        // de verdad (la cita nunca se pagó). Se cubren aquí las variantes
        // más comunes de "afirmar que el pago ya se hizo" sin usar
        // exactamente pagué/deposité/transferí en primera persona.
        || preg_match('/\bya\s+(hice|realic[eé]|efectu[eé])\s+(el\s+)?pago\b/iu', $texto) === 1
        || preg_match('/\b(el\s+)?pago\s+ya\s+(est[aá]|qued[oó]|se\s+(hizo|realiz[oó]))\b/iu', $texto) === 1
        || preg_match('/\bya\s+est[aá]\s+(el\s+)?pago\b/iu', $texto) === 1
        || preg_match('/\bacabo\s+de\s+(pagar|hacer\s+el\s+pago|realizar\s+el\s+pago|transferir|depositar)\b/iu', $texto) === 1
        || preg_match('/\bya\s+((est[aá]|qued[oó])\s+)?(pagado|depositado|transferido)\b/iu', $texto) === 1;
}

// Bug real detectado en producción: el seguimiento automático de
// calculadora (ver cron_seguimiento_calculadora.php) le insistía a
// clientes que YA habían dicho claramente que no les interesaba la
// asesoría de pago (ej. "No, muchas gracias", "no así está bien") --
// el cron solo revisaba si un humano había tomado el caso o si ya
// había pagado, nunca si la persona ya había declinado. Sesgado a
// propósito hacia "sí parece que declinó" en casos dudosos: el costo de
// no mandar un seguimiento de más es mínimo, pero insistirle a alguien
// que ya dijo que no genera desconfianza real.
function whatsapp_texto_parece_declinar(string $texto): bool
{
    return preg_match(
        '/\bno\b.{0,25}(gracias|me interesa|por ahora|por el momento|as[ií]\s+est[aá]\s+bien)/iu',
        $texto
    ) === 1;
}

// Patrón real detectado revisando conversaciones completas para mejorar
// qué tan "humano" se siente el bot: cuando alguien solo manda un
// agradecimiento/cierre corto ("gracias", "ok", un emoji) DESPUÉS de que
// el bot ya se había despedido, el bot le seguía contestando con otra
// despedida completa -- se veían cadenas de hasta 15 mensajes de
// "gracias"/"con gusto" seguidos, algo que ningún humano hace. Estas dos
// funciones, usadas juntas en procesar_mensaje_entrante(), cortan esa
// cadena: si el mensaje nuevo es un cierre simple Y el ÚLTIMO mensaje
// saliente ya sonaba a despedida, no se contesta nada más.
function whatsapp_texto_es_cierre_simple(string $texto): bool
{
    $limpio = trim($texto);
    if ($limpio === '' || mb_strlen($limpio) > 20 || strpos($limpio, '?') !== false) {
        return false;
    }
    // Sin ninguna letra (puro emoji o puntuación, ej. "😊", "👍") cuenta
    // como cierre por sí solo.
    if (preg_match('/\p{L}/u', $limpio) !== 1) {
        return true;
    }
    // Debe ser SOLO la palabra de cierre (con puntuación/emoji después, si
    // acaso) -- no basta con que empiece así, porque "gracias, pero..." o
    // "ok, mi salario es..." traen contenido nuevo que sí hay que contestar.
    return preg_match(
        '/^(muchas\s+)?gracias[\s!.,👍🙏😊❤️]*$|^(ok(?:ay|ey)?|va|s[ií]+|vale|listo|perfecto|entendido|de\s+acuerdo|de\s+nada|claro\s+que\s+s[ií])[\s!.,👍🙏😊]*$/iu',
        $limpio
    ) === 1;
}

function whatsapp_texto_parece_despedida(string $texto): bool
{
    return preg_match(
        '/buen[ao]s?\s+(d[ií]as?|tardes?|noches?)|que\s+tengas|que\s+est[eé]s?\s+(muy\s+)?bien|cu[ií]date|nos\s+vemos|con\s+gusto|aqu[ií]\s+(estoy|sigo|quedo|ando)|[eé]xito|saludos/iu',
        $texto
    ) === 1;
}

function whatsapp_enviar(string $telefono, string $texto): bool
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/whatsapp_credentials.php');
        return false;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v20.0/' . WHATSAPP_PHONE_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'text',
        'text' => ['body' => $texto],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return false;
    }
    return true;
}

// Manda un mensaje de PLANTILLA (HSM) aprobada por Meta -- a diferencia de
// whatsapp_enviar(), esta SÍ puede llegar fuera de la ventana de 24h (es
// justo su propósito: recordatorio_1 se usa cuando whatsapp_dentro_ventana_24h()
// da false). $parametros son los valores de las variables {{1}}, {{2}}...
// del cuerpo de la plantilla, en orden -- deben coincidir exacto con lo que
// Meta aprobó, si no la API regresa error.
function whatsapp_enviar_plantilla(string $telefono, string $nombrePlantilla, array $parametros, string $idioma = 'es_MX'): bool
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        error_log('Falta api/whatsapp_credentials.php');
        return false;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'template',
        'template' => [
            'name' => $nombrePlantilla,
            'language' => ['code' => $idioma],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $parametros),
            ]],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | plantilla=$nombrePlantilla | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return false;
    }
    return true;
}

// Hallazgo real revisando conversaciones completas para que el bot se
// sienta más humano: siempre mandaba TODA su respuesta en un solo
// mensaje largo con viñetas, aunque tuviera varias ideas separadas --
// una persona real en WhatsApp normalmente parte sus pensamientos en
// varias burbujas cortas. Esta función parte el texto por párrafos
// (doble salto de línea -- ver la instrucción correspondiente en
// IA_SYSTEM_PROMPT) y manda cada uno como su propio mensaje, con una
// pausa corta entre cada uno.
//
// Si el texto no trae ningún doble salto de línea (un solo párrafo, o
// una lista de horarios con saltos simples que debe quedarse junta),
// esto no cambia nada: se manda igual que antes, en un solo mensaje --
// o sea, es un cambio sin riesgo para cualquier respuesta que ya venía
// como un solo bloque.
//
// Cada parte se guarda como su propio mensaje 'saliente' en el
// historial (no rompe el contexto de la IA: ia_mensajes_desde_historial()
// ya fusiona mensajes consecutivos del mismo rol en un solo turno). Si
// falla el envío de una parte, se detiene ahí -- igual que whatsapp_enviar(),
// la parte que sí se alcanzó a mandar se guarda de todos modos, para no
// perder rastro de qué llegó de verdad -- y regresa false.
function whatsapp_enviar_respuesta(PDO $pdo, string $telefono, string $texto): bool
{
    $partes = preg_split('/\n{2,}/', trim($texto));
    $partes = array_values(array_filter(array_map('trim', $partes), fn($p) => $p !== ''));
    if (!$partes) {
        return true;
    }

    foreach ($partes as $i => $parte) {
        if ($i > 0) {
            usleep(random_int(2, 4) * 1_000_000);
        }
        $ok = whatsapp_enviar($telefono, $parte);
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $stmt->execute([':t' => $telefono, ':texto' => $parte]);
        if (!$ok) {
            return false;
        }
    }
    return true;
}

// Marca el mensaje entrante como leído y activa el indicador nativo de
// WhatsApp "escribiendo..." (dura hasta 25 segundos o hasta que se manda
// el siguiente mensaje) — para que la espera antes de la respuesta se
// sienta como una persona escribiendo, no como un bot contestando al
// instante.
function whatsapp_marcar_leido_y_escribiendo(string $messageId): bool
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return false;
    }
    require_once $credentialsFile;

    // El indicador de "escribiendo..." es una función más nueva de la Graph
    // API que el envío de texto normal — v20.0 (la que usa whatsapp_enviar)
    // puede no reconocer el campo typing_indicator, por eso aquí se usa una
    // versión más reciente.
    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => $messageId,
        'typing_indicator' => ['type' => 'text'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [escribiendo] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return false;
    }
    return true;
}

// Descarga un archivo que un cliente MANDÓ por WhatsApp (imagen o
// documento), usando su media_id -- son dos pasos: primero se le pide a la
// Graph API la URL temporal real del archivo, y luego se descarga desde
// ahí (los dos pasos necesitan el mismo token de acceso, la URL temporal
// por sí sola no es pública). Devuelve ['bytes' => string, 'mime_type' =>
// string], o null si algo falla.
function whatsapp_descargar_media(string $mediaId): ?array
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return null;
    }
    require_once $credentialsFile;

    $ch = curl_init('https://graph.facebook.com/v23.0/' . $mediaId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [descargar_media:url] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }
    $data = json_decode($raw, true);
    $url = $data['url'] ?? null;
    $mimeType = (string)($data['mime_type'] ?? 'application/octet-stream');
    if (!$url) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT => 30,
    ]);
    $bytes = curl_exec($ch);
    $status2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError2 = curl_error($ch);
    curl_close($ch);
    if ($bytes === false || $status2 < 200 || $status2 >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [descargar_media:archivo] status=$status2 | curl=$curlError2\n", FILE_APPEND);
        return null;
    }

    return ['bytes' => $bytes, 'mime_type' => $mimeType];
}

// Sube un archivo a los servidores de Meta — paso obligatorio antes de
// poder mandarlo como documento (WhatsApp no acepta el archivo directo
// en el mismo mensaje). Devuelve el media_id, o null si falla.
function whatsapp_subir_documento(string $rutaArchivo, string $nombreArchivo): ?string
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return null;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/media';
    $payload = [
        'messaging_product' => 'whatsapp',
        'file' => new CURLFile($rutaArchivo, 'application/pdf', $nombreArchivo),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [subir_documento] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }
    $data = json_decode($raw, true);
    return $data['id'] ?? null;
}

// Igual que whatsapp_subir_documento() pero para cualquier tipo de
// archivo (se usa para mandar imágenes desde el sistema, ej. un
// comprobante de devolución) -- ese estaba fijo a application/pdf.
function whatsapp_subir_media(string $rutaArchivo, string $nombreArchivo, string $mimeType): ?string
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return null;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/media';
    $payload = [
        'messaging_product' => 'whatsapp',
        'file' => new CURLFile($rutaArchivo, $mimeType, $nombreArchivo),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [subir_media] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }
    $data = json_decode($raw, true);
    return $data['id'] ?? null;
}

// Manda una imagen ya subida (con su media_id) por WhatsApp.
function whatsapp_enviar_imagen(string $telefono, string $mediaId, string $caption = ''): bool
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return false;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'image',
        'image' => [
            'id' => $mediaId,
            'caption' => $caption,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [enviar_imagen] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return false;
    }
    return true;
}

// Manda un documento ya subido (con su media_id) por WhatsApp.
function whatsapp_enviar_documento(string $telefono, string $mediaId, string $nombreArchivo, string $caption = ''): bool
{
    $credentialsFile = __DIR__ . '/whatsapp_credentials.php';
    if (!file_exists($credentialsFile)) {
        return false;
    }
    require_once $credentialsFile;

    $url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'document',
        'document' => [
            'id' => $mediaId,
            'filename' => $nombreArchivo,
            'caption' => $caption,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [enviar_documento] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return false;
    }
    return true;
}

// Genera el PDF de un cálculo de liquidación, lo sube y lo manda por
// WhatsApp como documento — todo en un paso, con limpieza del archivo
// temporal al final (se mande bien o falle). No lanza excepción si algo
// falla (revisa whatsapp_send_debug.log): un PDF fallido no debe tumbar
// la respuesta normal de texto que ya recibió el cliente.
function whatsapp_enviar_pdf_calculo(string $telefono, array $calc, float $salarioDiario, string $nombre = ''): bool
{
    $carpetaTmp = __DIR__ . '/tmp';
    if (!is_dir($carpetaTmp)) {
        @mkdir($carpetaTmp, 0755, true);
    }
    $rutaTemporal = $carpetaTmp . '/calculo_' . bin2hex(random_bytes(8)) . '.pdf';
    $enviado = false;

    try {
        // require_once con un archivo que no existe es un error fatal de
        // PHP que NO se puede atrapar con catch — se revisa a mano antes,
        // para que si falta vendor/ (dompdf no instalado) quede
        // registrado en vez de tumbar la petición completa en silencio.
        $vendorAutoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($vendorAutoload)) {
            throw new \RuntimeException('Falta api/vendor/ (dompdf no está instalado) — revisa que se haya subido y extraído la carpeta vendor completa.');
        }
        require_once __DIR__ . '/pdf_calculo_liquidacion.php';
        $pdfBytes = generar_pdf_calculo_liquidacion($calc, $salarioDiario, $nombre);
        file_put_contents($rutaTemporal, $pdfBytes);

        $mediaId = whatsapp_subir_documento($rutaTemporal, 'Calculo_liquidacion_Expertos_Laborales.pdf');
        if ($mediaId === null) {
            return false;
        }
        $enviado = whatsapp_enviar_documento($telefono, $mediaId, 'Calculo_liquidacion_Expertos_Laborales.pdf');
        return $enviado;
    } catch (\Throwable $e) {
        // Cualquier error real (falta vendor/, falta una clase, permisos,
        // etc.) se captura aquí en vez de tumbar la petición completa en
        // silencio — así queda registrado el motivo exacto.
        file_put_contents(__DIR__ . '/whatsapp_send_debug.log', date('c')
            . " | [pdf_calculo] excepcion=" . get_class($e) . ' | mensaje=' . $e->getMessage()
            . ' | archivo=' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
        return false;
    } finally {
        @unlink($rutaTemporal);
        // Bug real detectado: el envío del PDF nunca quedaba registrado en
        // whatsapp_conversaciones, así que desde el sistema (la pantalla de
        // Conversaciones/Prospectos) era imposible saber si de verdad se
        // mandó o no -- había que confiar en el mensaje de texto del bot
        // ("en unos segundos te llega el PDF") sin poder comprobarlo. Se
        // deja constancia aquí, se haya logrado mandar o no.
        try {
            $pdo = db();
            $texto = $enviado
                ? '📄 PDF de cálculo de liquidación enviado.'
                : '⚠️ El PDF de cálculo de liquidación NO se pudo enviar (revisar whatsapp_send_debug.log).';
            $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            )->execute([':t' => $telefono, ':texto' => $texto]);
        } catch (\Throwable $e) {
            // Nunca dejar que un fallo al registrar esto tumbe el envío ya
            // intentado.
        }
    }
}
