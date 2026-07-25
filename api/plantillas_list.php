<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$pdo = db();
$rows = $pdo->query(
    'SELECT p.id, p.nombre, p.descripcion, p.creado_en, u.nombre AS creado_por_nombre
     FROM plantillas_docx p
     LEFT JOIN usuarios u ON u.id = p.creado_por
     ORDER BY p.nombre ASC'
)->fetchAll();

respond(['plantillas' => $rows]);
