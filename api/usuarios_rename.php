<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$nombre = trim((string)($in['nombre'] ?? ''));
if ($id <= 0 || $nombre === '') fail('Datos incompletos.', 400);

$stmt = db()->prepare('UPDATE usuarios SET nombre = :nombre WHERE id = :id');
$stmt->execute([':nombre' => $nombre, ':id' => $id]);

respond(['ok' => true]);
