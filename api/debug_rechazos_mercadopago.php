<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta temporal: consulta DIRECTO a la API de Mercado Pago (no la
// base de datos local) el historial real de intentos de pago NO
// aprobados -- rechazados, pendientes, cancelados -- con el motivo real
// (status_detail) que manda Mercado Pago. A diferencia de
// debug_motivos_rechazo.php (que depende de lo que el webhook haya ido
// guardando localmente desde el 5 de octubre), esto trae el historial
// COMPLETO de la cuenta, sin importar desde cuándo se empezó a guardar
// nada localmente -- mismo dato que verías entrando a Mercado Pago
// directo, solo que resumido por motivo. Solo Administrador. Se puede
// borrar cuando ya no haga falta.
//
// Uso: debug_rechazos_mercadopago.php?dias=14 (default 14)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 14);
if ($dias <= 0) $dias = 14;

$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify("-{$dias} days");

$pagos = mercadopago_buscar_pagos_rechazados($desde, $hasta);
if ($pagos === null) {
    fail('No se pudo consultar Mercado Pago. Revisa mercadopago_debug.log.', 502);
}

if (!$pagos) {
    echo "No hubo intentos de pago no aprobados en los últimos {$dias} día(s) -- o no se pudo consultar (revisa mercadopago_debug.log si esto es inesperado).\n";
    exit;
}

$porMotivo = [];
$porEstado = [];
foreach ($pagos as $p) {
    $motivo = $p['status_detail'] !== '' ? $p['status_detail'] : '(sin detalle)';
    $porMotivo[$motivo][] = $p;
    $porEstado[$p['status']] = ($porEstado[$p['status']] ?? 0) + 1;
}
arsort($porMotivo);

echo "=== Intentos de pago NO aprobados (API real de Mercado Pago) -- últimos {$dias} día(s) ===\n\n";
echo "Total de intentos no aprobados: " . count($pagos) . "\n";
foreach ($porEstado as $estado => $n) echo "  {$estado}: {$n}\n";
echo "\n=== Agrupado por motivo real ===\n\n";

foreach ($porMotivo as $motivo => $lista) {
    echo "{$motivo}: " . count($lista) . " intento(s)\n";
    foreach (array_slice($lista, 0, 10) as $p) {
        $tel = $p['payer_phone'] !== '' ? $p['payer_phone'] : '(sin teléfono)';
        echo "  [{$p['date_created']}] {$tel} -- \${$p['transaction_amount']} -- {$p['description']}\n";
    }
    if (count($lista) > 10) echo "  ... y " . (count($lista) - 10) . " más\n";
    echo "\n";
}
