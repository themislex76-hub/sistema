<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: lista los mensajes SALIENTES del bot de HOY que
// mencionan cobro/costo -- para encontrar, sin saber el número, cuál
// conversación es la de una queja real del live (el usuario no sabe qué
// teléfono escribió). Es una lista amplia para revisar a ojo, no un
// filtro exacto -- puede traer casos correctos junto con el problemático.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->query(
    "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND creado_en >= CURDATE()
       AND (texto LIKE '%cobr%' OR texto LIKE '%costo%' OR texto LIKE '%cuesta%')
     ORDER BY creado_en DESC LIMIT 100"
);
$filas = $stmt->fetchAll();

echo "Mensajes del bot de hoy que mencionan cobro/costo (" . count($filas) . "):\n\n";
if (!$filas) {
    echo "Ninguno todavía.\n";
    exit;
}
foreach ($filas as $r) {
    echo "  " . $r['telefono'] . " -- " . $r['creado_en'] . "\n";
    echo "    \"" . mb_strimwidth((string)$r['texto'], 0, 220, '…') . "\"\n\n";
}
