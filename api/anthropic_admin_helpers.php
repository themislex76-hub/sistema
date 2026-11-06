<?php
declare(strict_types=1);

// Llama a la API de Costos de Anthropic (Admin API) para saber cuánto se
// gastó de verdad en IA en un mes -- separada de anthropic_credentials.php
// porque usa una llave DISTINTA (ANTHROPIC_ADMIN_API_KEY, de solo lectura
// de gasto, nunca la llave que manda mensajes al bot). Si esa llave no
// está configurada, todas las funciones de aquí regresan null en vez de
// tronar -- el panel de Costos vs. Ingresos sigue funcionando normal, solo
// sin la sugerencia automática.

/**
 * Suma el gasto real de IA (en USD) de un mes calendario completo,
 * consultando https://api.anthropic.com/v1/organizations/cost_report.
 * Regresa null si la llave ANTHROPIC_ADMIN_API_KEY no está configurada, o
 * si la consulta falla (revisar anthropic_admin_debug.log).
 */
function anthropic_costo_mensual_usd(string $mes): ?float
{
    $credentialsFile = __DIR__ . '/anthropic_credentials.php';
    if (file_exists($credentialsFile)) {
        require_once $credentialsFile;
    }
    if (!defined('ANTHROPIC_ADMIN_API_KEY')) {
        return null;
    }

    $inicio = DateTimeImmutable::createFromFormat('Y-m-d', $mes . '-01', new DateTimeZone('UTC'));
    if ($inicio === false) return null;
    $inicio = $inicio->setTime(0, 0, 0);
    $fin = $inicio->modify('first day of next month');

    $total = 0.0;
    $page = null;
    $vueltasSeguridad = 0;

    do {
        $params = [
            'starting_at' => $inicio->format('Y-m-d\TH:i:s\Z'),
            'ending_at' => $fin->format('Y-m-d\TH:i:s\Z'),
            'bucket_width' => '1d',
            'limit' => 31,
        ];
        if ($page !== null) $params['page'] = $page;

        $ch = curl_init('https://api.anthropic.com/v1/organizations/cost_report?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . ANTHROPIC_ADMIN_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            file_put_contents(__DIR__ . '/anthropic_admin_debug.log', date('c')
                . " | cost_report | status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
            return null;
        }

        $data = json_decode($raw, true);
        foreach (($data['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $r) {
                // amount viene en centavos (unidad más chica de la
                // moneda) como string decimal -- "123.45" son $1.2345,
                // no $123.45. Se divide entre 100 para pasar a dólares.
                $total += ((float)($r['amount'] ?? 0)) / 100;
            }
        }

        $page = ($data['has_more'] ?? false) ? ($data['next_page'] ?? null) : null;
        $vueltasSeguridad++;
    } while ($page !== null && $vueltasSeguridad < 10);

    return round($total, 2);
}
