<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta temporal: confirma qué versión de mercadopago_helpers.php
// está realmente viva en el servidor -- para diagnosticar por qué las
// citas recientes seguían saliendo en $299 después de subir el cambio a
// $399. Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

echo "MERCADOPAGO_MONTO_ASESORIA (precio vigente en el servidor): " . MERCADOPAGO_MONTO_ASESORIA . "\n";
echo "Existe mercadopago_monto_asesoria_a_respetar(): " . (function_exists('mercadopago_monto_asesoria_a_respetar') ? 'sí' : 'NO -- archivo viejo') . "\n\n";

if (function_exists('mercadopago_monto_asesoria_a_respetar')) {
    $pdo = db();
    $ref = new ReflectionFunction('mercadopago_monto_asesoria_a_respetar');
    $filename = $ref->getFileName();
    echo "Archivo: {$filename}\n";
    echo "Última modificación en disco: " . date('Y-m-d H:i:s', filemtime($filename)) . "\n\n";

    echo "-- Últimos 10 teléfonos que agendaron -- qué precio les tocaría HOY mismo si volvieran a agendar:\n";
    $stmt = $pdo->query(
        "SELECT DISTINCT telefono FROM citas_asesoria WHERE estado = 'confirmada' ORDER BY id DESC LIMIT 10"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tel) {
        $monto = mercadopago_monto_asesoria_a_respetar($pdo, $tel);
        echo "{$tel} -> \${$monto}\n";
    }
}
