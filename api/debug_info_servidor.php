<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: info básica del servidor para diagnosticar el
// problema de api/vendor/ faltante (dompdf no instalado -- rompe el PDF
// de cálculo de liquidación y también las notificaciones push, que
// dependen de la misma carpeta). Solo Administrador. Se puede borrar
// cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . PHP_VERSION . "\n";
echo "Extensiones relevantes: gd=" . (extension_loaded('gd') ? 'sí' : 'NO')
    . ", mbstring=" . (extension_loaded('mbstring') ? 'sí' : 'NO')
    . ", curl=" . (extension_loaded('curl') ? 'sí' : 'NO') . "\n\n";

$vendorPath = __DIR__ . '/vendor';
echo "api/vendor/ existe: " . (is_dir($vendorPath) ? 'SÍ' : 'NO') . "\n";
if (is_dir($vendorPath)) {
    echo "api/vendor/autoload.php existe: " . (file_exists($vendorPath . '/autoload.php') ? 'sí' : 'NO') . "\n";
}
echo "api/composer.lock existe: " . (file_exists(__DIR__ . '/composer.lock') ? 'sí' : 'NO') . "\n";
