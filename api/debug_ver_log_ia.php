<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra las líneas de ia_debug.log que coincidan
// con un filtro (por teléfono y/o por texto) -- para investigar la causa
// raíz de un bloqueo de seguridad (confirmacion_pago_bloqueada,
// calculo_gratis_bloqueado, sin_texto_final, etc.) sin tener acceso
// directo al servidor. Solo Administrador. Se puede borrar cuando ya no
// haga falta.
//
// Uso: debug_ver_log_ia.php?telefono=52XXXXXXXXXX
//      debug_ver_log_ia.php?filtro=confirmacion_pago_bloqueada
//      debug_ver_log_ia.php?telefono=52XXXXXXXXXX&filtro=confirmacion_pago_bloqueada
//      debug_ver_log_ia.php?lineas=50 (cuántas líneas recientes revisar, default 500)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$logPath = __DIR__ . '/ia_debug.log';
if (!file_exists($logPath)) {
    echo "No existe ia_debug.log en el servidor (o no se ha escrito nada todavía).\n";
    exit;
}

$telefono = trim((string)($_GET['telefono'] ?? ''));
$filtro = trim((string)($_GET['filtro'] ?? ''));
$lineasAVer = (int)($_GET['lineas'] ?? 500);
if ($lineasAVer <= 0) $lineasAVer = 500;

// Lee solo las últimas N líneas del archivo (puede ser grande) sin
// cargarlo completo en memoria de golpe.
$todas = file($logPath, FILE_IGNORE_NEW_LINES);
if ($todas === false) {
    echo "No se pudo leer el archivo.\n";
    exit;
}
$ultimas = array_slice($todas, -$lineasAVer);

$coincidencias = [];
foreach ($ultimas as $linea) {
    if ($telefono !== '' && strpos($linea, $telefono) === false) continue;
    if ($filtro !== '' && strpos($linea, $filtro) === false) continue;
    $coincidencias[] = $linea;
}

echo "=== ia_debug.log -- revisando las últimas " . count($ultimas) . " línea(s) del archivo ===\n";
echo "Filtros: teléfono=" . ($telefono ?: '(ninguno)') . " | texto=" . ($filtro ?: '(ninguno)') . "\n\n";

if (!$coincidencias) {
    echo "Ninguna línea coincide con ese filtro.\n";
    exit;
}

foreach ($coincidencias as $l) {
    echo $l . "\n\n";
}
echo "--- " . count($coincidencias) . " línea(s) encontrada(s) ---\n";
