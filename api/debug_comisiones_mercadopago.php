<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta temporal: consulta DIRECTO la API de Mercado Pago (no la
// base de datos local) el historial real de pagos APROBADOS en la
// cuenta del despacho, y suma la comisión real que Mercado Pago se
// quedó de cada uno (fee_details) -- para llevar un registro de gasto
// real de comisiones, agrupado por mes, sin tener que sacarlo a mano
// del dashboard de Mercado Pago. Mismo mecanismo que ya usa el sistema
// para el historial de cursos (mercadopago_buscar_pagos_aprobados).
// Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_comisiones_mercadopago.php?meses=3 (default 3)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$meses = (int)($_GET['meses'] ?? 3);
if ($meses <= 0) $meses = 3;

$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify("-{$meses} months");

$pagos = mercadopago_buscar_pagos_aprobados($desde, $hasta);
if ($pagos === null) {
    fail('No se pudo consultar Mercado Pago. Revisa mercadopago_debug.log.', 502);
}

if (!$pagos) {
    echo "No hubo pagos aprobados en los últimos {$meses} mes(es).\n";
    exit;
}

$porMes = [];
$totalVentas = 0.0;
$totalComision = 0.0;
foreach ($pagos as $p) {
    if ($p['date_approved'] === '') continue;
    $mes = (new DateTimeImmutable($p['date_approved']))->format('Y-m');
    if (!isset($porMes[$mes])) $porMes[$mes] = ['n' => 0, 'ventas' => 0.0, 'comision' => 0.0];
    $porMes[$mes]['n']++;
    $porMes[$mes]['ventas'] += $p['transaction_amount'];
    $porMes[$mes]['comision'] += $p['comision'];
    $totalVentas += $p['transaction_amount'];
    $totalComision += $p['comision'];
}
krsort($porMes);

echo "=== Comisión real de Mercado Pago -- últimos {$meses} mes(es) ===\n";
echo "(consulta directa a la API de Mercado Pago, suma el fee_details real de cada pago aprobado)\n\n";

printf("%-10s | %-6s | %-14s | %-14s | %-8s\n", "Mes", "Pagos", "Ventas", "Comisión MP", "%");
echo str_repeat('-', 62) . "\n";
foreach ($porMes as $mes => $d) {
    $pct = $d['ventas'] > 0 ? round($d['comision'] / $d['ventas'] * 100, 2) : 0;
    printf("%-10s | %-6d | \$%-13s | \$%-13s | %s%%\n", $mes, $d['n'], number_format($d['ventas'], 2), number_format($d['comision'], 2), $pct);
}

echo str_repeat('-', 62) . "\n";
$pctTotal = $totalVentas > 0 ? round($totalComision / $totalVentas * 100, 2) : 0;
printf("%-10s | %-6d | \$%-13s | \$%-13s | %s%%\n", "TOTAL", count($pagos), number_format($totalVentas, 2), number_format($totalComision, 2), $pctTotal);
