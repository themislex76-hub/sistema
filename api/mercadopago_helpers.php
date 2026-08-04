<?php
declare(strict_types=1);

// Funciones para generar y verificar pagos de la asesoría telefónica de
// pago vía la API de Mercado Pago (Checkout Pro / Preferencias). Ver
// mercadopago_credentials.example.php para configurar el Access Token.

const MERCADOPAGO_MONTO_ASESORIA = 299.00;

/**
 * Carga el Access Token desde mercadopago_credentials.php si ya existe en
 * el servidor, o null si todavía no se ha configurado — para que, antes
 * de que el despacho suba ese archivo, el resto del bot (leads de
 * despido, preguntas generales, etc.) siga funcionando sin tronar.
 */
function mercadopago_token(): ?string
{
    $credentialsFile = __DIR__ . '/mercadopago_credentials.php';
    if (!file_exists($credentialsFile)) return null;
    require_once $credentialsFile;
    return MERCADOPAGO_ACCESS_TOKEN;
}

/**
 * Crea una "preferencia" de pago en Mercado Pago para la asesoría
 * telefónica ($299 MXN) y devuelve el link de pago (init_point) listo
 * para mandarle al cliente por WhatsApp. Solo acepta tarjeta de crédito/
 * débito y el saldo de la cuenta de Mercado Pago (ambos confirman al
 * instante) — se excluyen OXXO, transferencia, depósito en ventanilla y
 * "Meses sin Tarjeta", porque esos no confirman el pago de inmediato o no
 * son un pago real en el momento, lo que dejaría un horario apartado sin
 * certeza real de si se va a pagar o no.
 *
 * $citaId se manda como external_reference para poder relacionar la
 * notificación del webhook con la cita correcta.
 * Devuelve ['id' => preference_id, 'init_point' => url_de_pago] o null si falló.
 */
function mercadopago_crear_preferencia_asesoria(int $citaId, string $telefono, string $notificationUrl): ?array
{
    $token = mercadopago_token();
    if ($token === null) {
        error_log('Falta api/mercadopago_credentials.php');
        return null;
    }

    $payload = [
        'items' => [[
            'title' => 'Asesoría laboral personalizada (llamada telefónica, 1 hora)',
            'quantity' => 1,
            'currency_id' => 'MXN',
            'unit_price' => MERCADOPAGO_MONTO_ASESORIA,
        ]],
        'payment_methods' => [
            'excluded_payment_types' => [
                ['id' => 'ticket'],          // pago en efectivo (OXXO, etc.)
                ['id' => 'bank_transfer'],   // transferencia SPEI
                ['id' => 'atm'],             // depósito en ventanilla/cajero
                ['id' => 'mercado_credito'], // "Meses sin Tarjeta" (línea de crédito de Mercado Pago)
                // El saldo de la cuenta de Mercado Pago (account_money) SÍ
                // se deja disponible — confirma al instante igual que la
                // tarjeta, a diferencia de OXXO/transferencia/depósito.
            ],
            'installments' => 1,
        ],
        'external_reference' => (string)$citaId,
        'notification_url' => $notificationUrl,
        'back_urls' => [
            'success' => 'https://www.expertoslaborales.com/gracias-asesoria',
            'pending' => 'https://www.expertoslaborales.com/gracias-asesoria',
            'failure' => 'https://www.expertoslaborales.com/gracias-asesoria',
        ],
        'statement_descriptor' => 'EXPERTOSLABORALES',
    ];

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
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
        file_put_contents(__DIR__ . '/mercadopago_debug.log', date('c')
            . " | crear_preferencia | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    $data = json_decode($raw, true);
    if (!isset($data['id'], $data['init_point'])) return null;

    return ['id' => (string)$data['id'], 'init_point' => (string)$data['init_point']];
}

/**
 * Consulta el estado real de un pago directo en la API de Mercado Pago —
 * nunca hay que confiar en lo que trae el aviso del webhook por sí solo,
 * siempre se vuelve a preguntar con el Access Token propio para
 * confirmar que el pago existe y su estado real.
 * Devuelve el arreglo decodificado del pago, o null si falló la consulta.
 */
function mercadopago_obtener_pago(string $paymentId): ?array
{
    $token = mercadopago_token();
    if ($token === null) {
        error_log('Falta api/mercadopago_credentials.php');
        return null;
    }

    $ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        file_put_contents(__DIR__ . '/mercadopago_debug.log', date('c')
            . " | obtener_pago($paymentId) | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        return null;
    }

    return json_decode($raw, true);
}
