<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$pdo = db();
$rows = $pdo->query('SELECT clave, valor FROM configuracion')->fetchAll();
$config = [];
foreach ($rows as $r) {
    $config[$r['clave']] = $r['valor'];
}

respond(['configuracion' => $config]);
