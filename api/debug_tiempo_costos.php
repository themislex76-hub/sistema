<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';
require_once __DIR__ . '/anthropic_admin_helpers.php';
if (file_exists(__DIR__ . '/anthropic_credentials.php')) require_once __DIR__ . '/anthropic_credentials.php';

// Herramienta temporal: mide cuánto tarda CADA paso de
// costos_mensuales_resumen.php por separado (consultas a la base de datos
// local, búsqueda de pagos en Mercado Pago, prueba de la Admin API de
// Anthropic) -- para ver exactamente cuál paso es el que está dejando el
// panel colgado, en vez de seguir adivinando. Solo Administrador. Se
// puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

function paso(string $nombre, callable $fn): void
{
    $t0 = microtime(true);
    try {
        $resultado = $fn();
        $ms = round((microtime(true) - $t0) * 1000);
        $resumen = is_array($resultado) ? (count($resultado) . ' elemento(s)') : var_export($resultado, true);
        echo "[{$ms} ms] {$nombre} -> {$resumen}\n";
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000);
        echo "[{$ms} ms] {$nombre} -> ERROR: " . $e->getMessage() . "\n";
    }
    @ob_flush();
    @flush();
}

echo "Midiendo cada paso del panel de Costos vs. Ingresos...\n\n";
$pdo = db();

paso('1) Asesorías por mes (BD local)', function() use ($pdo) {
    return $pdo->query(
        "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidas, SUM(monto) AS total
         FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en IS NOT NULL GROUP BY mes"
    )->fetchAll();
});

paso('2) Documentos por mes (BD local)', function() use ($pdo) {
    return $pdo->query(
        "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidos, SUM(monto) AS total
         FROM compras_documento_calculo WHERE estado = 'confirmada' AND pagado_en IS NOT NULL GROUP BY mes"
    )->fetchAll();
});

paso('3) Mercado Pago -- pagos aprobados, 6 meses (API externa, paginada)', function() {
    $hasta = new DateTimeImmutable('now');
    $desde = $hasta->modify('-6 months')->modify('first day of this month')->setTime(0, 0, 0);
    $pagos = mercadopago_buscar_pagos_aprobados($desde, $hasta);
    return $pagos ?? 'NULL (falló la consulta, revisa mercadopago_debug.log)';
});

paso('4) Citas con mp_payment_id (BD local)', function() use ($pdo) {
    return $pdo->query(
        "SELECT mp_payment_id, pagado_en FROM citas_asesoria WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL"
    )->fetchAll();
});

paso('5) Documentos con mp_payment_id (BD local)', function() use ($pdo) {
    return $pdo->query(
        "SELECT mp_payment_id, pagado_en FROM compras_documento_calculo WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL"
    )->fetchAll();
});

paso('6) WhatsApp enviados por mes, 12 meses (BD local)', function() use ($pdo) {
    return $pdo->query(
        "SELECT DATE_FORMAT(creado_en, '%Y-%m') AS mes, COUNT(*) AS enviados
         FROM whatsapp_conversaciones WHERE direccion = 'saliente' AND creado_en >= NOW() - INTERVAL 12 MONTH
         GROUP BY mes"
    )->fetchAll();
});

paso('7) Costo IA manual guardado (BD local)', function() use ($pdo) {
    return $pdo->query("SELECT mes, costo_ia FROM costos_mensuales")->fetchAll();
});

paso('8) Anthropic Admin API -- prueba mes actual (API externa)', function() {
    if (!defined('ANTHROPIC_ADMIN_API_KEY')) return 'llave no configurada, se omite';
    return anthropic_costo_mensual_usd(date('Y-m'));
});

echo "\nListo. Si algún paso tardó varios segundos o dice ERROR, ese es el que está\n";
echo "dejando el panel colgado -- mándame esta salida completa.\n";
