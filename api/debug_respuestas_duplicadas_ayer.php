<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca el mismo patrón detectado en el caso de
// Kevin (5214424388524, 10-sep) en TODAS las conversaciones de ayer, no
// solo las que mencionan cobro -- dos mensajes SALIENTES del bot para el
// mismo número con menos de 25 segundos de diferencia entre sí, señal de
// que se generaron dos respuestas de IA por separado para mensajes que
// debieron agruparse en una sola (ver WHATSAPP_ESPERA_AGRUPAR_SEGUNDOS en
// whatsapp_procesar.php, subido de 8 a 18s por este mismo bug). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->query(
    "SELECT telefono, id, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND creado_en >= CURDATE() - INTERVAL 1 DAY AND creado_en < CURDATE()
     ORDER BY telefono, creado_en ASC"
);
$filas = $stmt->fetchAll();

$porTelefono = [];
foreach ($filas as $r) {
    $porTelefono[$r['telefono']][] = $r;
}

$encontrados = [];
foreach ($porTelefono as $telefono => $mensajes) {
    for ($i = 1; $i < count($mensajes); $i++) {
        $anterior = $mensajes[$i - 1];
        $actual = $mensajes[$i];
        $segundos = strtotime($actual['creado_en']) - strtotime($anterior['creado_en']);
        if ($segundos >= 0 && $segundos <= 25) {
            $encontrados[] = [$telefono, $anterior, $actual, $segundos];
        }
    }
}

echo "Pares de respuestas del bot separadas por 25 segundos o menos, ayer (" . count($encontrados) . "):\n\n";
if (!$encontrados) {
    echo "Ninguno más aparte del caso ya conocido de Kevin.\n";
    exit;
}
foreach ($encontrados as [$telefono, $a, $b, $segundos]) {
    echo "  {$telefono} -- {$segundos}s de diferencia ({$a['creado_en']} -> {$b['creado_en']})\n";
    echo "    1) \"" . mb_strimwidth((string)$a['texto'], 0, 150, '…') . "\"\n";
    echo "    2) \"" . mb_strimwidth((string)$b['texto'], 0, 150, '…') . "\"\n\n";
}
