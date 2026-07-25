<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$pdo = db();
$rows = $pdo->query('SELECT id, fecha, descripcion, ambito FROM dias_inhabiles ORDER BY fecha ASC')->fetchAll();

respond(['dias' => $rows]);
