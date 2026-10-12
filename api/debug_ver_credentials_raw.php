<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_admin();
header('Content-Type: text/plain; charset=utf-8');

// Herramienta temporal: muestra EXACTAMENTE qué está leyendo el servidor
// de api/anthropic_credentials.php en este momento (con las llaves
// recortadas, nunca completas) -- para descartar de una vez problemas de
// caché, guardado, o contenido inesperado. Solo Administrador. Se puede
// borrar cuando ya no haga falta.

$archivo = __DIR__ . '/anthropic_credentials.php';

if (!file_exists($archivo)) {
    echo "El archivo NO existe en el servidor: {$archivo}\n";
    exit;
}

echo "Archivo: {$archivo}\n";
echo "Tamaño: " . filesize($archivo) . " bytes\n";
echo "Última modificación: " . date('Y-m-d H:i:s', filemtime($archivo)) . "\n\n";

$contenido = file_get_contents($archivo);
echo "=== Contenido (llaves recortadas) ===\n\n";

// Recorta cualquier valor largo tipo llave (después de sk-ant-...) para
// no exponer la llave completa aquí.
$recortado = preg_replace_callback(
    '/sk-ant-[A-Za-z0-9_\-]{10,}/',
    function ($m) {
        return substr($m[0], 0, 20) . '...[recortado, ' . strlen($m[0]) . ' caracteres]...' . substr($m[0], -6);
    },
    $contenido
);
echo $recortado . "\n";

echo "\n=== Constantes definidas al incluirlo ===\n";
require_once $archivo;
echo "ANTHROPIC_API_KEY definida: " . (defined('ANTHROPIC_API_KEY') ? 'SÍ' : 'NO') . "\n";
echo "ANTHROPIC_ADMIN_API_KEY definida: " . (defined('ANTHROPIC_ADMIN_API_KEY') ? 'SÍ' : 'NO') . "\n";
