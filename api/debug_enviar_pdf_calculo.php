<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/liquidacion_calculadora.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Herramienta temporal: recalcula un cálculo de liquidación con la
// herramienta REAL del sistema (no a mano) y manda el PDF corregido por
// WhatsApp -- para cuando el bot ya mandó un PDF con cifras equivocadas y
// hay que reemplazarlo. Manda un PDF por cada llamada (un modo a la vez):
// si el cliente pidió despido y rescisión, llama esto dos veces. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_enviar_pdf_calculo.php?telefono=52XXXXXXXXXX
//        &fecha_ingreso=2025-08-25&fecha_baja=2026-10-01
//        &salario_diario=682.11&tipo=injustificado
//        &dias_vacaciones_anteriores=6&dias_salarios_devengados=0
//        &modo=despido&nombre=Fulano
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$fechaIngreso = trim((string)($_GET['fecha_ingreso'] ?? ''));
$fechaBaja = trim((string)($_GET['fecha_baja'] ?? ''));
$salarioDiario = (float)($_GET['salario_diario'] ?? 0);
$tipo = trim((string)($_GET['tipo'] ?? 'injustificado'));
$diasVacAnt = (float)($_GET['dias_vacaciones_anteriores'] ?? 0);
$diasSalDev = (float)($_GET['dias_salarios_devengados'] ?? 0);
$modo = trim((string)($_GET['modo'] ?? 'despido'));
$nombre = trim((string)($_GET['nombre'] ?? ''));

if ($telefono === '' || $fechaIngreso === '' || $fechaBaja === '' || $salarioDiario <= 0) {
    echo "Faltan datos. Uso: debug_enviar_pdf_calculo.php?telefono=52XXXXXXXXXX"
        . "&fecha_ingreso=AAAA-MM-DD&fecha_baja=AAAA-MM-DD&salario_diario=000.00"
        . "&tipo=injustificado&dias_vacaciones_anteriores=0&dias_salarios_devengados=0"
        . "&modo=despido&nombre=Fulano\n";
    exit;
}

$pdo = db();
$calc = calcular_estimado_liquidacion(
    $pdo,
    $fechaIngreso,
    $fechaBaja,
    $salarioDiario,
    $tipo,
    $diasVacAnt,
    $diasSalDev,
    $modo
);

if ($calc === null) {
    echo "Datos inválidos (revisa fechas y salario) -- no se calculó nada, no se mandó ningún PDF.\n";
    exit;
}

echo "=== Cálculo recalculado con la herramienta real (modo={$modo}) ===\n";
foreach ($calc as $clave => $valor) {
    if (is_bool($valor)) $valor = $valor ? 'true' : 'false';
    echo "{$clave}: {$valor}\n";
}
echo "\n";

$enviado = whatsapp_enviar_pdf_calculo($telefono, $calc, $salarioDiario, $nombre);

echo $enviado
    ? "PDF enviado correctamente a {$telefono}.\n"
    : "NO se pudo enviar el PDF -- revisa whatsapp_send_debug.log en el servidor.\n";
