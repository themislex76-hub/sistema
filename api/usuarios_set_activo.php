<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$admin = require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$activo = !empty($in['activo']);
if ($id <= 0) fail('Falta el id del usuario.', 400);
if ($id === $admin['id'] && !$activo) fail('No puedes desactivar tu propia cuenta.', 400);

$stmt = db()->prepare('UPDATE usuarios SET activo = :a WHERE id = :id');
$stmt->execute([':a' => $activo ? 1 : 0, ':id' => $id]);

respond(['ok' => true]);
