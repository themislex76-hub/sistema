<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Misma idea que debug_respuestas_duplicadas_ayer.php, pero para
// cualquier fecha (?fecha=YYYY-MM-DD, por default hoy) y con el
// timestamp completo (con segundos) para poder cruzarlo después contra
// whatsapp_send_debug.log si hace falta investigar más a fondo. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$fecha = (string)($_GET['fecha'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo "Fecha inválida, usa el formato YYYY-MM-DD.\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT telefono, id, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND creado_en >= :desde AND creado_en < :hasta
     ORDER BY telefono, creado_en ASC"
);
$stmt->execute([':desde' => $fecha, ':hasta' => date('Y-m-d', strtotime($fecha . ' +1 day'))]);
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
        if ($segundos >= 0 && $segundos <= 90) {
            $encontrados[] = [$telefono, $anterior, $actual, $segundos];
        }
    }
}

echo "Pares de respuestas del bot separadas por 90 segundos o menos, el $fecha (" . count($encontrados) . "):\n\n";
if (!$encontrados) {
    echo "Ninguno.\n";
    exit;
}
foreach ($encontrados as [$telefono, $a, $b, $segundos]) {
    echo "  {$telefono} -- ids {$a['id']}/{$b['id']} -- {$segundos}s de diferencia ({$a['creado_en']} -> {$b['creado_en']})\n";
    echo "    1) \"" . mb_strimwidth((string)$a['texto'], 0, 200, '…') . "\"\n";
    echo "    2) \"" . mb_strimwidth((string)$b['texto'], 0, 200, '…') . "\"\n\n";
}
