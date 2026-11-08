<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: cuenta cuántos cálculos de liquidación
// (calcular_estimado_liquidacion) se han hecho en el mes, para estimar
// el costo marginal de IA que representan -- el Console de Anthropic no
// desglosa el gasto por herramienta, así que esto es un ESTIMADO
// razonado (no un número exacto de facturación), basado en que cada
// cálculo exitoso implica una llamada extra a la API (vuelve a mandar el
// bloque de system+tools cacheado + el historial de la conversación +
// el resultado de texto), aparte de la llamada normal del mensaje.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$desde = date('Y-m-01');

$stmt = $pdo->prepare("SELECT COUNT(*) FROM calculos_liquidacion WHERE creado_en >= :desde");
$stmt->execute([':desde' => $desde]);
$totalMes = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM calculos_liquidacion WHERE creado_en >= :desde");
$stmt->execute([':desde' => date('Y-m-d', strtotime('-7 days'))]);
$total7dias = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM calculos_liquidacion WHERE creado_en >= :desde");
$stmt->execute([':desde' => date('Y-m-d')]);
$totalHoy = (int)$stmt->fetchColumn();

// Estimado de costo marginal por llamada extra de cálculo: el bloque
// cacheado (system+tools, ~18,500 tokens) a precio de lectura de caché
// (~10% del precio base) + historial de conversación ya cacheado
// (variable, se toma un estimado conservador de 3,000 tokens) + salida
// de texto nueva (~400 tokens) a precio normal de salida. Con precios de
// Sonnet 5 (~$3/1M entrada, ~$15/1M salida; lectura de caché ~$0.3/1M):
$tokensCacheados = 18500 + 3000;
$costoEntradaCacheada = $tokensCacheados / 1_000_000 * 0.3;
$costoSalida = 400 / 1_000_000 * 15;
$costoPorCalculo = round($costoEntradaCacheada + $costoSalida, 4);

echo "=== Estimado de costo de IA por cálculos de liquidación ===\n\n";
echo "Cálculos hechos hoy: {$totalHoy}\n";
echo "Cálculos hechos este mes (desde el 1): {$totalMes}\n";
echo "Cálculos hechos en los últimos 7 días: {$total7dias}\n\n";
echo "Costo estimado por cada llamada extra de cálculo: ~\${$costoPorCalculo} USD\n";
echo "Estimado total del mes: ~\$" . round($totalMes * $costoPorCalculo, 2) . " USD\n\n";
echo "OJO: esto es un ESTIMADO razonado, no el número real de facturación -- el Console de Anthropic no desglosa el gasto por herramienta. El PDF en sí (generarlo y mandarlo) no tiene costo de IA, es aparte.\n";
