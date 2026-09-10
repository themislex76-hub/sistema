<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra whatsapp_relay_debug.log, donde
// whatsapp_relay.php anota cada intento que le llega del puente de
// Cloudflare (ela-whatsapp-relay.workers.dev) -- si dice "RECHAZADO", la
// llave compartida (WHATSAPP_RELAY_KEY / RELAY_KEY) no coincide entre el
// Worker y este servidor. Si NO aparece nada nuevo después de mandar un
// WhatsApp de prueba, el mensaje ni siquiera está llegando hasta acá
// (problema de red entre Cloudflare y este hosting). Solo Administrador.
// Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

echo "Hora del servidor ahora mismo: " . date('Y-m-d H:i:s') . "\n\n";

$logFile = __DIR__ . '/whatsapp_relay_debug.log';
if (!file_exists($logFile)) {
    echo "No existe whatsapp_relay_debug.log todavía -- eso significa que, desde que se\n";
    echo "subió este cambio, NINGUNA petición del puente de Cloudflare ha tocado\n";
    echo "whatsapp_relay.php (ni para aceptarla ni para rechazarla). Manda un WhatsApp\n";
    echo "de prueba y vuelve a abrir esta página.\n";
    exit;
}

$lineas = file($logFile, FILE_IGNORE_NEW_LINES);
echo "Últimas 30 líneas:\n\n";
echo implode("\n", array_slice($lineas, -30)) . "\n";
