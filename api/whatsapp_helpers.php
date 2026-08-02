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
