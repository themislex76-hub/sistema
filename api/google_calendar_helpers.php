<?php
declare(strict_types=1);

// Sincronización server-side de citas de asesoría a Google Calendar — para
// que una cita se agende sola en cuanto se confirma el pago (vía el
// webhook de Mercado Pago, que corre sin que nadie tenga el navegador
// abierto), sin depender del botón "Sincronizar ahora" del lado cliente.
// Usa el mismo refresh_token que ya se guarda cuando el abogado conecta su
// cuenta desde el sistema (ver google_access_token.php).

/**
 * Refresca y devuelve un access_token válido para ese usuario, o null si
 * no tiene conectada su cuenta de Google o la conexión ya no es válida
 * (mismo criterio que google_access_token.php, incluyendo que limpia el
 * refresh_token guardado si Google ya lo rechazó).
 */
function google_obtener_access_token(PDO $pdo, int $usuarioId): ?string
{
    $credentialsFile = __DIR__ . '/google_credentials.php';
    if (!file_exists($credentialsFile)) return null;
    require_once $credentialsFile;

    $stmt = $pdo->prepare('SELECT google_refresh_token FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $usuarioId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['google_refresh_token'])) return null;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => $row['google_refresh_token'],
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'grant_type' => 'refresh_token',
        ]),
    ]);
    $respuesta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = $respuesta ? json_decode($respuesta, true) : null;
    if ($httpCode !== 200 || !$data || empty($data['access_token'])) {
        $upd = $pdo->prepare('UPDATE usuarios SET google_refresh_token = NULL, google_calendar_email = NULL, google_conectado_en = NULL WHERE id = :id');
        $upd->execute([':id' => $usuarioId]);
        return null;
    }

    return $data['access_token'];
}

/**
 * El mismo id determinístico que ya usa app.js (googleCitaEventId) para
 * este mismo par usuario+cita — así, si el abogado también sincroniza
 * manual desde el sistema más tarde, actualiza el MISMO evento en vez de
 * crear uno duplicado.
 */
function google_cita_event_id(int $usuarioId, int $citaId): string
{
    return 'ela' . substr(hash('sha256', $usuarioId . '|cita_asesoria|' . $citaId), 0, 40);
}

/**
 * Agenda (o actualiza) en el Google Calendar del abogado asignado la cita
 * ya pagada — no lanza ningún error hacia afuera: si el abogado no
 * conectó su Google Calendar, o Google falla, simplemente no se agenda
 * ahí (la cita sigue existiendo normal dentro del sistema).
 */
function google_sincronizar_cita_pagada(PDO $pdo, array $cita, string $horarioTexto): void
{
    try {
        $accessToken = google_obtener_access_token($pdo, (int)$cita['usuario_id']);
        if ($accessToken === null) return;

        $id = google_cita_event_id((int)$cita['usuario_id'], (int)$cita['id']);
        $body = json_encode([
            'summary' => '[Asesoría] ' . ($cita['nombre_cliente'] ?: $cita['telefono']),
            'description' => "Llamada telefónica de la asesoría pagada (\${$cita['monto']} MXN). Teléfono: {$cita['telefono']}.\n\nGenerado automáticamente por el sistema de Expertos Laborales — no lo edites aquí, los cambios no se reflejan de vuelta al sistema.",
            'start' => ['dateTime' => $cita['fecha'] . 'T' . substr($cita['hora_inicio'], 0, 8), 'timeZone' => 'America/Mexico_City'],
            'end' => ['dateTime' => $cita['fecha'] . 'T' . substr($cita['hora_fin'], 0, 8), 'timeZone' => 'America/Mexico_City'],
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
        $base = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

        $ch = curl_init($base);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(array_merge(['id' => $id], json_decode($body, true)), JSON_UNESCAPED_UNICODE),
        ]);
        $respuesta = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 409) {
            // Ya existía ese id (por ejemplo, un abogado ya la había
            // sincronizado manual antes) — se actualiza en vez de crear.
            $ch = curl_init($base . '/' . $id);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (\Throwable $e) {
        error_log('google_sincronizar_cita_pagada falló: ' . $e->getMessage());
    }
}

/**
 * Lista los calendarios visibles para la cuenta de Google conectada (11 nov
 * 2026, pedido explícito del usuario -- lectura de Google Calendar hacia el
 * sistema, al revés del sync de arriba). Devuelve [] si falla o no hay
 * cuenta conectada -- nunca truena, el admin solo ve una lista vacía y
 * puede reintentar.
 */
function google_listar_calendarios(string $accessToken): array
{
    $ch = curl_init('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $respuesta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !$respuesta) return [];
    $data = json_decode($respuesta, true);
    $items = $data['items'] ?? [];
    return array_map(fn($c) => ['id' => $c['id'], 'nombre' => $c['summary'] ?? $c['id']], $items);
}

/**
 * Eventos próximos (desde hoy hasta $diasAdelante) de un calendario
 * específico -- usado por cron_leer_google_calendar.php. singleEvents=true
 * expande recurrencias a instancias concretas (si algún día se agenda un
 * evento recurrente, cada ocurrencia llega por separado con su propia
 * fecha, en vez de un solo bloque RRULE que habría que interpretar aparte).
 */
function google_listar_eventos(string $accessToken, string $calendarId, int $diasAdelante = 60): array
{
    $timeMin = (new DateTime('today'))->format('c');
    $timeMax = (new DateTime('today'))->modify("+{$diasAdelante} days")->format('c');
    $params = http_build_query([
        'timeMin' => $timeMin,
        'timeMax' => $timeMax,
        'singleEvents' => 'true',
        'orderBy' => 'startTime',
        'maxResults' => 250,
    ]);
    $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $respuesta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !$respuesta) return [];
    $data = json_decode($respuesta, true);
    $items = $data['items'] ?? [];
    $eventos = [];
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'cancelled') continue; // evento borrado en Google -- no procesar
        $start = $it['start']['dateTime'] ?? $it['start']['date'] ?? null;
        if (!$start) continue;
        $fecha = substr($start, 0, 10);
        $hora = isset($it['start']['dateTime']) ? substr($it['start']['dateTime'], 11, 5) : null;
        $eventos[] = [
            'google_event_id' => $it['id'],
            'titulo' => $it['summary'] ?? '(sin título)',
            'descripcion' => $it['description'] ?? null,
            'fecha' => $fecha,
            'hora' => $hora,
            'updated' => $it['updated'] ?? date('c'),
        ];
    }
    return $eventos;
}
