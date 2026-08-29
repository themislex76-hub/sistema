<?php
declare(strict_types=1);

// Envío de mensajes de texto por WhatsApp Cloud API (Meta), usando el
// número dedicado configurado en whatsapp_credentials.php.

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
        return whatsapp_enviar_documento($telefono, $mediaId, 'Calculo_liquidacion_Expertos_Laborales.pdf');
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
    }
}
