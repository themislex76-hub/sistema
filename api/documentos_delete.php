<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM expediente_documentos WHERE id = :id');
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) fail('Documento no encontrado.', 404);

guard_expediente_access($pdo, $user, (int)$doc['expediente_id']);

$ruta = documentos_dir((int)$doc['expediente_id']) . '/' . $doc['nombre_disco'];
if (is_file($ruta)) unlink($ruta);

$del = $pdo->prepare('DELETE FROM expediente_documentos WHERE id = :id');
$del->execute([':id' => $id]);

respond(['ok' => true]);
