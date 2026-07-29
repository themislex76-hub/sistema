<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$pdo = db();
$rows = $pdo->query('SELECT estado, nombre FROM tribunales_custom ORDER BY estado, nombre')->fetchAll();

$porEstado = [];
foreach ($rows as $r) {
    $porEstado[$r['estado']][] = $r['nombre'];
}

respond(['tribunales' => $porEstado]);
