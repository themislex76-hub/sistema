<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra el calculo_json guardado en
// compras_documento_calculo para un teléfono -- para poder generar el
// PDF por fuera del sistema (con un script aparte) mientras falta
// api/vendor/ en el servidor y el envío automático del PDF no funciona.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_obtener_calculo_guardado.php?telefono=52XXXXXXXXXX
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Uso: debug_obtener_calculo_guardado.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, nombre_cliente, salario_diario, monto, estado, pagado_en, calculo_json
     FROM compras_documento_calculo WHERE telefono = :t ORDER BY creado_en DESC LIMIT 1"
);
$stmt->execute([':t' => $telefono]);
$compra = $stmt->fetch();

if (!$compra) {
    echo "No hay ninguna compra de documento registrada para {$telefono}.\n";
    exit;
}

echo "id: {$compra['id']}\n";
echo "nombre_cliente: {$compra['nombre_cliente']}\n";
echo "salario_diario: {$compra['salario_diario']}\n";
echo "monto: {$compra['monto']}\n";
echo "estado: {$compra['estado']}\n";
echo "pagado_en: {$compra['pagado_en']}\n\n";
echo "calculo_json:\n" . $compra['calculo_json'] . "\n";
