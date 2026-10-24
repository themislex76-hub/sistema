<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca expedientes por nombre del actor (LIKE) y
// muestra TODOS sus pagos reales guardados en expediente_pagos -- para
// diagnosticar casos donde el mismo actor/monto aparece repetido en
// "Asuntos con pago sin reportar" (ver si de verdad son 2 registros
// distintos, o un duplicado por capturar dos veces). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_pagos_expediente.php?actor=Marcos%20Florin
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$actor = trim((string)($_GET['actor'] ?? ''));
if ($actor === '') {
    echo "Uso: debug_pagos_expediente.php?actor=Marcos%20Florin\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, actor, demandado FROM expedientes WHERE actor LIKE :a ORDER BY id");
$stmt->execute([':a' => '%' . $actor . '%']);
$expedientes = $stmt->fetchAll();

if (!$expedientes) {
    echo "No se encontró ningún expediente con actor que contenga \"{$actor}\".\n";
    exit;
}

foreach ($expedientes as $e) {
    echo "=== Expediente #{$e['id']} -- {$e['actor']} vs {$e['demandado']} ===\n";
    $p = $pdo->prepare(
        "SELECT id, fecha, monto, cobrado, fecha_cobro, orden FROM expediente_pagos WHERE expediente_id = :eid ORDER BY orden ASC, id ASC"
    );
    $p->execute([':eid' => $e['id']]);
    $pagos = $p->fetchAll();
    if (!$pagos) {
        echo "  (sin pagos registrados)\n\n";
        continue;
    }
    foreach ($pagos as $pago) {
        echo "  pago #{$pago['id']} -- fecha={$pago['fecha']} -- monto=\${$pago['monto']} -- cobrado=" . ($pago['cobrado'] ? 'SÍ' : 'NO')
            . " -- fecha_cobro=" . ($pago['fecha_cobro'] ?? '(null)') . " -- orden={$pago['orden']}\n";
    }
    echo "\n";
}
