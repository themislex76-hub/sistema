<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
$pdo = db();
$isAdmin = $user['rol'] === 'administrador';

$where = $isAdmin ? '1=1' : 'e.abogado_id = :uid';
$soloNuevos = isset($_GET['nuevos']) && $_GET['nuevos'] === '1';
$estadoFiltro = $soloNuevos ? " AND a.estado = 'nuevo'" : '';

$sql = "SELECT a.*, e.exp AS expediente_exp, e.actor AS expediente_actor, e.demandado AS expediente_demandado,
               ru.nombre AS revisado_por_nombre, cu.nombre AS creado_por_nombre
        FROM avisos_boletin a
        JOIN expedientes e ON e.id = a.expediente_id
        LEFT JOIN usuarios ru ON ru.id = a.revisado_por
        LEFT JOIN usuarios cu ON cu.id = a.creado_por
        WHERE $where $estadoFiltro
        ORDER BY (a.estado = 'nuevo') DESC, a.creado_en DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
if (!$isAdmin) $stmt->execute([':uid' => $user['id']]);
else $stmt->execute();

$avisos = [];
foreach ($stmt->fetchAll() as $r) {
    $avisos[] = [
        'id' => (int)$r['id'],
        'expediente_id' => (int)$r['expediente_id'],
        'expediente_exp' => $r['expediente_exp'],
        'expediente_actor' => $r['expediente_actor'],
        'expediente_demandado' => $r['expediente_demandado'],
        'fuente' => $r['fuente'],
        'origen' => $r['origen'],
        'fecha_publicacion' => $r['fecha_publicacion'],
        'resumen' => $r['resumen'],
        'url_verificacion' => $r['url_verificacion'],
        'estado' => $r['estado'],
        'creado_por_nombre' => $r['creado_por_nombre'],
        'creado_en' => $r['creado_en'],
        'revisado_por_nombre' => $r['revisado_por_nombre'],
        'revisado_en' => $r['revisado_en'],
    ];
}

respond(['avisos' => $avisos]);
