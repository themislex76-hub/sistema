<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();
$stmt = $pdo->query(
    "SELECT p.*, e.exp AS expediente_exp
     FROM prospectos p
     LEFT JOIN expedientes e ON e.id = p.expediente_id
     ORDER BY (p.estatus = 'nuevo') DESC, p.actualizado_en DESC
     LIMIT 300"
);

$prospectos = [];
foreach ($stmt->fetchAll() as $r) {
    $prospectos[] = [
        'id' => (int)$r['id'],
        'telefono' => $r['telefono'],
        'tipo' => $r['tipo'],
        'nombre' => $r['nombre'],
        'estado_ubicacion' => $r['estado_ubicacion'],
        'resumen_caso' => $r['resumen_caso'],
        'estatus' => $r['estatus'],
        'pausado_bot' => (bool)$r['pausado_bot'],
        'notas_internas' => $r['notas_internas'],
        'expediente_id' => $r['expediente_id'] !== null ? (int)$r['expediente_id'] : null,
        'expediente_exp' => $r['expediente_exp'],
        'creado_en' => $r['creado_en'],
        'actualizado_en' => $r['actualizado_en'],
    ];
}

respond(['prospectos' => $prospectos]);
