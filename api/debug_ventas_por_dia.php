<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra cuántas asesorías se vendieron (pago
// confirmado) por día en las últimas semanas, para ver si de verdad bajó
// el ritmo de ventas o no. Se agrupa por la fecha en que se confirmó el
// pago (pagado_en), no por la fecha de la cita -- así se ve cuándo entró
// el dinero realmente, sin importar para cuándo haya quedado agendada la
// llamada. Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$dias = (int)($_GET['dias'] ?? 21);
if ($dias < 1) $dias = 21;

$stmt = $pdo->prepare(
    "SELECT DATE(pagado_en) AS dia, COUNT(*) AS num, SUM(monto) AS total
     FROM citas_asesoria
     WHERE pagado_en IS NOT NULL AND pagado_en >= :limite
     GROUP BY DATE(pagado_en)
     ORDER BY dia ASC"
);
$stmt->execute([':limite' => date('Y-m-d 00:00:00', strtotime("-{$dias} days"))]);
$filas = $stmt->fetchAll();

echo "=== Asesorías vendidas por día (últimos {$dias} días, por fecha de pago) ===\n\n";
if (!$filas) {
    echo "No hay ventas registradas en ese periodo.\n";
    exit;
}

$totalNum = 0;
$totalMonto = 0.0;
foreach ($filas as $f) {
    $barra = str_repeat('#', (int)$f['num']);
    echo str_pad($f['dia'], 12) . " | " . str_pad((string)$f['num'], 3, ' ', STR_PAD_LEFT)
        . " venta(s) | $" . number_format((float)$f['total'], 2) . " MXN  " . $barra . "\n";
    $totalNum += (int)$f['num'];
    $totalMonto += (float)$f['total'];
}

echo "\n--- Total: {$totalNum} asesorías vendidas, $" . number_format($totalMonto, 2) . " MXN ---\n";

$promedioDiario = $totalNum / $dias;
echo "Promedio diario: " . number_format($promedioDiario, 2) . " asesorías/día\n";

// Comparación simple: primera mitad del periodo vs segunda mitad, para
// detectar si realmente va a la baja o es variación normal día a día.
$mitad = (int)ceil($dias / 2);
$fechaCorte = date('Y-m-d', strtotime("-{$mitad} days"));
$numPrimeraMitad = 0;
$numSegundaMitad = 0;
foreach ($filas as $f) {
    if ($f['dia'] < $fechaCorte) {
        $numPrimeraMitad += (int)$f['num'];
    } else {
        $numSegundaMitad += (int)$f['num'];
    }
}
echo "\nPrimera mitad del periodo: {$numPrimeraMitad} ventas\n";
echo "Segunda mitad del periodo: {$numSegundaMitad} ventas\n";
