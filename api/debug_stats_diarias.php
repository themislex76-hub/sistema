<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra, día por día (últimos 30 días), cuántos
// mensajes de WhatsApp entraron, cuántos números distintos escribieron y
// cuántos prospectos nuevos (leads calificados) se crearon — para comparar
// a simple vista los días de live contra los días normales. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$mensajes = $pdo->query(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS mensajes, COUNT(DISTINCT telefono) AS numeros
     FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= NOW() - INTERVAL 30 DAY
     GROUP BY DATE(creado_en)"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$prospectos = $pdo->query(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS nuevos
     FROM prospectos
     WHERE creado_en >= NOW() - INTERVAL 30 DAY
     GROUP BY DATE(creado_en)"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$dias = array_unique(array_merge(array_keys($mensajes), array_keys($prospectos)));
rsort($dias);

printf("%-12s | %-10s | %-16s | %-13s\n", "Fecha", "Mensajes", "Números distintos", "Leads nuevos");
echo str_repeat('-', 60) . "\n";
foreach ($dias as $dia) {
    $m = $mensajes[$dia]['mensajes'] ?? 0;
    $n = $mensajes[$dia]['numeros'] ?? 0;
    $p = $prospectos[$dia]['nuevos'] ?? 0;
    printf("%-12s | %-10s | %-16s | %-13s\n", $dia, $m, $n, $p);
}
