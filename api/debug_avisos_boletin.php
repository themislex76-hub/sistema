<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra los últimos avisos de boletín que
// entraron al sistema (de cualquier fuente/robot) y, por separado, los
// expedientes que SÍ están configurados para monitoreo (los que le
// mandaría expedientes_monitorear.php a los robots) -- para diagnosticar
// si los robots no están corriendo en absoluto, o si corren pero no
// encuentran/mandan nada. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Últimos 20 avisos de boletín en el sistema (cualquier fuente) ===\n";
$stmt = $pdo->query(
    "SELECT a.id, a.expediente_id, e.exp, a.fuente, a.origen, a.fecha_publicacion, a.estado, a.creado_en
     FROM avisos_boletin a LEFT JOIN expedientes e ON e.id = a.expediente_id
     ORDER BY a.id DESC LIMIT 20"
);
$avisos = $stmt->fetchAll();
if (!$avisos) {
    echo "  (NINGUNO -- nunca ha entrado ni un solo aviso al sistema)\n";
}
foreach ($avisos as $a) {
    echo "  [{$a['creado_en']}] exp={$a['exp']} (id {$a['expediente_id']}) | fuente={$a['fuente']} | origen={$a['origen']} | estado={$a['estado']} | fecha_pub={$a['fecha_publicacion']}\n";
}

echo "\n=== Expedientes configurados para monitoreo (lo que verían los robots) ===\n";
$sql = "SELECT id, exp, junta, tribunal, amparo_expediente
        FROM expedientes
        WHERE (junta IS NOT NULL AND junta <> '') OR (tribunal IS NOT NULL AND tribunal <> '')
           OR (amparo_expediente IS NOT NULL AND amparo_expediente <> '')
        ORDER BY id";
$rows = $pdo->query($sql)->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    $esFederal = stripos(($r['junta'] ?? '') . ' ' . ($r['tribunal'] ?? ''), 'federal') !== false ? 'FEDERAL' : 'local';
    echo "  id={$r['id']} exp={$r['exp']} | junta/tribunal=" . ($r['junta'] ?: $r['tribunal']) . " ({$esFederal}) | amparo={$r['amparo_expediente']}\n";
}
