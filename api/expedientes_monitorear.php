<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por los robots de monitoreo de boletines (no por el frontend) para
// saber qué expedientes activos tienen tribunal capturado y necesitan
// revisión diaria. No filtra por jurisdicción aquí — cada robot decide qué
// le corresponde según el texto de 'junta'/'tribunal'.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$pdo = db();
$sql = "SELECT id, exp, actor, demandado, junta, tribunal
        FROM expedientes
        WHERE (junta IS NOT NULL AND junta <> '') OR (tribunal IS NOT NULL AND tribunal <> '')
        ORDER BY id";
$rows = $pdo->query($sql)->fetchAll();

$expedientes = [];
foreach ($rows as $r) {
    // Los tribunales laborales FEDERALES siempre incluyen la palabra
    // "federal" en su nombre oficial, sin importar la ciudad/estado donde
    // tengan su sede -- por eso los robots deben confiar en este campo y
    // no en menciones de "Ciudad de México"/"CDMX"/"Estado de México".
    $esFederal = stripos((string)($r['junta'] ?? ''), 'federal') !== false
        || stripos((string)($r['tribunal'] ?? ''), 'federal') !== false;
    $expedientes[] = [
        'id' => (int)$r['id'],
        'exp' => $r['exp'],
        'actor' => $r['actor'],
        'demandado' => $r['demandado'],
        'junta' => $r['junta'],
        'tribunal' => $r['tribunal'],
        'es_federal' => $esFederal,
    ];
}

respond(['expedientes' => $expedientes]);
