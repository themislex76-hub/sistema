<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);

$pdo = db();
$stmt = $pdo->query(
    'SELECT v.id, v.calendar_id, v.nombre_calendario, v.abogado_id, v.activo, u.nombre AS abogado_nombre
     FROM google_calendarios_vigilados v
     JOIN usuarios u ON u.id = v.abogado_id
     ORDER BY v.nombre_calendario'
);
respond(['mapeo' => $stmt->fetchAll()]);
