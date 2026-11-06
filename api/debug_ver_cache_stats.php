<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: lee ia_cache_stats.log (que anota ia_llamar_claude()
// en cada llamada exitosa a la API de Claude) y muestra un resumen para
// confirmar si el caché de prompts (system+tools con TTL de 1h, más el
// breakpoint sobre el historial de mensajes) está funcionando de verdad en
// producción. Solo Administrador. Se puede borrar junto con el log y el
// bloque que lo escribe en ia_helpers.php cuando ya no haga falta medirlo.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/ia_cache_stats.log';
if (!file_exists($logFile)) {
    echo "No existe ia_cache_stats.log todavía — significa que no ha habido\n"
        . "ninguna llamada exitosa a la IA desde que se subió el archivo con este log.\n";
    exit;
}

$lineas = file($logFile, FILE_IGNORE_NEW_LINES);
$total = count($lineas);

$sumaRead = 0;
$sumaCreation = 0;
$sumaInput = 0;
$sumaOutput = 0;
foreach ($lineas as $linea) {
    if (preg_match('/cache_read=(\d+) \| cache_creation=(\d+) \| input=(\d+) \| output=(\d+)/', $linea, $m)) {
        $sumaRead += (int)$m[1];
        $sumaCreation += (int)$m[2];
        $sumaInput += (int)$m[3];
        $sumaOutput += (int)$m[4];
    }
}

$totalPrefijoCacheable = $sumaRead + $sumaCreation;
$porcentajeAcierto = $totalPrefijoCacheable > 0 ? round($sumaRead / $totalPrefijoCacheable * 100, 1) : 0;

echo "=== Resumen de caché de prompts — {$total} llamadas registradas ===\n\n"
    . "Tokens leídos desde caché (cache_read, ~10% del precio normal):     " . number_format($sumaRead) . "\n"
    . "Tokens escritos al caché (cache_creation, precio doble por 1h TTL): " . number_format($sumaCreation) . "\n"
    . "Tokens normales sin caché (input, precio completo):                " . number_format($sumaInput) . "\n"
    . "Tokens de respuesta generados (output):                            " . number_format($sumaOutput) . "\n\n"
    . "% de acierto de caché (de lo que SÍ es cacheable — system+tools+historial): {$porcentajeAcierto}%\n"
    . "(entre más cerca de 100%, mejor está funcionando; si sale muy bajo casi\n"
    . "siempre, puede ser que el tráfico esté muy espaciado — más de 1 hora entre\n"
    . "mensajes — o que algo esté rompiendo el caché de nuevo.)\n\n"
    . "=== Últimas 30 llamadas (más reciente al final) ===\n"
    . implode("\n", array_slice($lineas, -30)) . "\n";
