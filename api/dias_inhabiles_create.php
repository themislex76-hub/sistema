<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$fecha = (string)($in['fecha'] ?? '');
$descripcion = trim((string)($in['descripcion'] ?? ''));
$ambito = (string)($in['ambito'] ?? 'todos');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) fail('Fecha inválida.', 400);
if ($descripcion === '') fail('Falta la descripción.', 400);
if (!in_array($ambito, ['federal', 'cdmx', 'edomex', 'todos'], true)) fail('Ámbito no válido.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO dias_inhabiles (fecha, descripcion, ambito) VALUES (:fecha, :descripcion, :ambito)
     ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)'
);
$stmt->execute([':fecha' => $fecha, ':descripcion' => $descripcion, ':ambito' => $ambito]);

respond(['ok' => true]);
