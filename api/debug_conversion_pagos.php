<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: reporte de conversión de asesorías de pago --
// cuántas citas creadas terminan pagadas vs. expiradas/canceladas, y
// separa (con un criterio simple) quién probablemente tuvo fricción real
// de pago (varios intentos seguidos con el mismo teléfono, casi siempre
// para el mismo día, antes de lograr pagar o de rendirse del todo) de
// quién simplemente se arrepintió (un solo intento, sin volver a
// intentar). No es perfecto (no sabe la razón real de cada expiración),
// pero da una idea con datos reales en vez de solo la impresión. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_conversion_pagos.php?dias=14 (default 14)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 14);
if ($dias <= 0) $dias = 14;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT id, telefono, fecha, estado, creado_en, pagado_en, monto
     FROM citas_asesoria
     WHERE creado_en >= NOW() - INTERVAL :dias DAY
     ORDER BY telefono, creado_en"
);
$stmt->execute([':dias' => $dias]);
$filas = $stmt->fetchAll();

$porEstado = ['confirmada' => 0, 'pendiente_pago' => 0, 'expirada' => 0, 'cancelada' => 0];
$porTelefono = [];
foreach ($filas as $f) {
    $porEstado[$f['estado']] = ($porEstado[$f['estado']] ?? 0) + 1;
    $porTelefono[$f['telefono']][] = $f;
}

$total = count($filas);
$cerradas = $porEstado['confirmada'] + $porEstado['expirada'] + $porEstado['cancelada']; // excluye las que siguen pendientes ahorita mismo
$tasaConversion = $cerradas > 0 ? round($porEstado['confirmada'] / $cerradas * 100, 1) : 0;

echo "=== Conversión de asesorías -- últimos {$dias} día(s) ===\n\n";
echo "Total de citas creadas: {$total}\n";
echo "  Confirmadas (pagadas): {$porEstado['confirmada']}\n";
echo "  Expiradas (no pagó a tiempo): {$porEstado['expirada']}\n";
echo "  Canceladas: {$porEstado['cancelada']}\n";
echo "  Pendientes ahorita mismo (todavía dentro de los 30 min): {$porEstado['pendiente_pago']}\n\n";
echo "Tasa de conversión (confirmadas / cerradas, sin contar las que siguen pendientes ahorita): {$tasaConversion}%\n\n";

// Clasifica cada teléfono: si tuvo 2+ intentos (filas) y terminó pagando,
// o tuvo 2+ intentos y NUNCA pagó -- eso sugiere que de verdad quería
// pagar pero algo (probablemente el link/Mercado Pago) se lo impidió
// varias veces. Un solo intento sin pagar es más probable que sea
// simple arrepentimiento, no fricción de pago.
$conFriccionYPago = [];
$conFriccionSinPago = [];
foreach ($porTelefono as $tel => $intentos) {
    if (count($intentos) < 2) continue;
    $pago = false;
    foreach ($intentos as $i) if ($i['estado'] === 'confirmada') $pago = true;
    if ($pago) {
        $conFriccionYPago[] = $tel;
    } else {
        $sinResolver = false;
        foreach ($intentos as $i) if ($i['estado'] === 'pendiente_pago') $sinResolver = true;
        if (!$sinResolver) $conFriccionSinPago[] = $tel;
    }
}

echo "=== Señal de fricción real de pago (2+ intentos con el mismo teléfono) ===\n\n";
echo "Insistieron varias veces y AL FINAL sí lograron pagar: " . count($conFriccionYPago) . "\n";
foreach ($conFriccionYPago as $tel) echo "  {$tel}\n";
echo "\nInsistieron varias veces y NUNCA lograron pagar (se perdieron, probable fricción real): " . count($conFriccionSinPago) . "\n";
foreach ($conFriccionSinPago as $tel) echo "  {$tel}\n";

echo "\n=== Se rindieron al primer intento (1 sola cita, expiró o se canceló, sin reintentar) ===\n";
$unSoloIntento = 0;
foreach ($porTelefono as $tel => $intentos) {
    if (count($intentos) === 1 && in_array($intentos[0]['estado'], ['expirada', 'cancelada'], true)) $unSoloIntento++;
}
echo "{$unSoloIntento} teléfono(s) -- estos probablemente son arrepentimiento genuino, no fricción de pago.\n";
