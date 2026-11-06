<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: diagnóstico directo de por qué la IA no está
// contestando — revisa que exista el archivo de credenciales y hace una
// llamada real (mínima) a la API de Claude para ver la respuesta exacta,
// sin depender de otros logs. Solo Administrador. Se puede borrar cuando
// ya no haga falta.
require_admin();
require_once __DIR__ . '/ia_helpers.php';
header('Content-Type: text/plain; charset=utf-8');

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
echo "1) Archivo anthropic_credentials.php existe: " . (file_exists($credentialsFile) ? 'SÍ' : 'NO — este es el problema, hay que volver a subirlo') . "\n";

if (!file_exists($credentialsFile)) {
    echo "\nNo se puede seguir sin ese archivo.\n";
    exit;
}
require_once $credentialsFile;
echo "2) Constante ANTHROPIC_API_KEY definida: " . (defined('ANTHROPIC_API_KEY') ? 'SÍ' : 'NO') . "\n";
if (defined('ANTHROPIC_API_KEY')) {
    $k = ANTHROPIC_API_KEY;
    echo "   Empieza con: " . substr($k, 0, 12) . "... (largo: " . strlen($k) . " caracteres)\n";
}

echo "\n3) Haciendo una llamada de prueba real a la API de Claude...\n";
$resultado = ia_llamar_claude([
    ['role' => 'user', 'content' => 'Responde solo con la palabra: prueba'],
]);

if ($resultado === null) {
    echo "FALLÓ la llamada. Revisa arriba en ia_debug.log el detalle más reciente (debería haberse agregado una línea nueva ahí también con esta prueba).\n";
} else {
    echo "ÉXITO. La API respondió bien:\n";
    foreach (($resultado['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') echo "Respuesta: " . $bloque['text'] . "\n";
    }
}
