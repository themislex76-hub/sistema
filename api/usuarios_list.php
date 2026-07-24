<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();
$rows = $pdo->query(
    'SELECT u.id, u.nombre, u.email, u.rol, u.activo, u.debe_cambiar_password, u.creado_en,
            (SELECT COUNT(*) FROM expedientes e WHERE e.abogado_id = u.id) AS num_casos
     FROM usuarios u ORDER BY u.rol DESC, u.nombre ASC'
)->fetchAll();

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['activo'] = (bool)$r['activo'];
    $r['debe_cambiar_password'] = (bool)$r['debe_cambiar_password'];
    $r['num_casos'] = (int)$r['num_casos'];
}

respond(['usuarios' => $rows]);
