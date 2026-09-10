<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: encuentra números que escribieron por WhatsApp en
// las últimas 48 horas y se quedaron SIN NINGUNA respuesta del bot (ni
// siquiera el mensaje de emergencia -- eso significa que el proceso truena
// a medias, antes de intentar contestar nada, y como Meta ya recibió su
// "200 OK" de inmediato -ver whatsapp_webhook.php- nunca vuelve a
// reintentar el mensaje solo). También muestra cuándo se subió por última
// vez cada archivo clave del flujo de WhatsApp, para saber si el archivo
// que se cree subido en verdad ya está en el servidor. Solo Administrador.
// Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

echo "Hora del servidor ahora mismo: " . date('Y-m-d H:i:s') . "\n\n";

echo "Última modificación de archivos clave del flujo de WhatsApp:\n";
foreach (['whatsapp_procesar.php', 'ia_helpers.php', 'whatsapp_helpers.php', 'whatsapp_webhook.php'] as $f) {
    $ruta = __DIR__ . '/' . $f;
    echo "  {$f}: " . (file_exists($ruta) ? date('Y-m-d H:i:s', filemtime($ruta)) : 'NO EXISTE') . "\n";
}
echo "\n";

$pdo = db();

echo "Números que escribieron en las últimas 48 horas y se quedaron SIN respuesta del bot:\n\n";
$stmt = $pdo->query(
    "SELECT telefono,
            MAX(CASE WHEN direccion = 'entrante' THEN creado_en END) AS ultimo_entrante,
            MAX(CASE WHEN direccion = 'saliente' THEN creado_en END) AS ultimo_saliente
     FROM whatsapp_conversaciones
     WHERE creado_en >= NOW() - INTERVAL 2 DAY
     GROUP BY telefono
     HAVING ultimo_entrante IS NOT NULL
        AND (ultimo_saliente IS NULL OR ultimo_saliente < ultimo_entrante)
     ORDER BY ultimo_entrante DESC"
);
$filas = $stmt->fetchAll();

if (!$filas) {
    echo "  Ninguno -- todos los que escribieron en las últimas 48h sí recibieron alguna respuesta.\n";
} else {
    $stmtTexto = $pdo->prepare(
        "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY creado_en DESC LIMIT 1"
    );
    foreach ($filas as $r) {
        $stmtTexto->execute([':t' => $r['telefono']]);
        $texto = (string)($stmtTexto->fetchColumn() ?: '');
        echo "  " . $r['telefono'] . " -- escribió " . $r['ultimo_entrante'] . "\n";
        echo "    último mensaje: \"" . mb_strimwidth($texto, 0, 150, '…') . "\"\n";
    }
    echo "\n  Total: " . count($filas) . " número(s) sin ninguna respuesta.\n";
}

echo "\nÚltimas líneas de whatsapp_send_debug.log (fallos de ENTREGA, no de proceso):\n";
$logEntrega = __DIR__ . '/whatsapp_send_debug.log';
if (file_exists($logEntrega)) {
    $lineas = file($logEntrega, FILE_IGNORE_NEW_LINES);
    echo implode("\n", array_slice($lineas, -15)) . "\n";
} else {
    echo "  No existe ese log todavía.\n";
}
