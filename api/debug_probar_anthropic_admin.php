<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/anthropic_admin_helpers.php';

// Herramienta temporal: prueba que ANTHROPIC_ADMIN_API_KEY esté bien
// configurada, llamando a anthropic_costo_mensual_usd() para el mes
// actual -- si regresa un número, la llave funciona. Si regresa null,
// revisa api/anthropic_admin_debug.log para ver el error exacto (llave
// mal copiada, sin permisos, etc.). Solo Administrador. Se puede borrar
// cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$mes = (string)($_GET['mes'] ?? date('Y-m'));

echo "Probando ANTHROPIC_ADMIN_API_KEY para el mes {$mes}...\n\n";

$costo = anthropic_costo_mensual_usd($mes);

if ($costo === null) {
    echo "NULL -- no funcionó. Posibles causas:\n";
    echo "  1. La llave no quedó bien guardada en api/anthropic_credentials.php\n";
    echo "  2. La línea no dice exactamente: define('ANTHROPIC_ADMIN_API_KEY', 'tu-llave-aqui');\n";
    echo "  3. Hubo un error de red o de permisos -- revisa api/anthropic_admin_debug.log para el detalle exacto.\n";
} else {
    echo "¡Funcionó! Gasto real de IA en {$mes}: \${$costo} USD\n";
}
