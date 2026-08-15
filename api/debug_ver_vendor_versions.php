<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Diagnóstico temporal (solo Administrador): muestra las versiones
// exactas de las librerías ya instaladas en api/vendor/, para poder
// agregar dompdf sin romper lo que ya usa push_helpers.php (notificaciones
// push). Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$archivo = __DIR__ . '/vendor/composer/installed.json';
if (!file_exists($archivo)) {
    echo "No existe api/vendor/composer/installed.json — ¿está instalado api/vendor/ todavía?\n";
    exit;
}

$data = json_decode((string)file_get_contents($archivo), true);
$paquetes = $data['packages'] ?? $data; // formatos distintos según versión de Composer

echo "Paquetes instalados en api/vendor/:\n\n";
foreach ($paquetes as $p) {
    if (!isset($p['name'])) continue;
    echo $p['name'] . ' => ' . ($p['version'] ?? '?') . "\n";
}
