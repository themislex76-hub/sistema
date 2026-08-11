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
