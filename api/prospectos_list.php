<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
// El Administrador ve todos los prospectos; un abogado solo ve los que se
// le turnaron desde esta misma vista (ver prospectos_update.php).
$esAdmin = $user['rol'] === 'administrador';
$sql = "SELECT p.*, e.exp AS expediente_exp, u.nombre AS asignado_nombre
        FROM prospectos p
        LEFT JOIN expedientes e ON e.id = p.expediente_id
        LEFT JOIN usuarios u ON u.id = p.asignado_a"
     . (!$esAdmin ? ' WHERE p.asignado_a = :uid' : '')
     . " ORDER BY (p.estatus = 'nuevo') DESC, p.actualizado_en DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($esAdmin ? [] : [':uid' => $user['id']]);

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
        'asignado_a' => $r['asignado_a'] !== null ? (int)$r['asignado_a'] : null,
        'asignado_nombre' => $r['asignado_nombre'],
        'notas_internas' => $r['notas_internas'],
        'expediente_id' => $r['expediente_id'] !== null ? (int)$r['expediente_id'] : null,
        'expediente_exp' => $r['expediente_exp'],
        'creado_en' => $r['creado_en'],
        'actualizado_en' => $r['actualizado_en'],
    ];
}

respond(['prospectos' => $prospectos]);
