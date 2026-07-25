<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM expediente_documentos WHERE id = :id');
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) fail('Documento no encontrado.', 404);

guard_expediente_access($pdo, $user, (int)$doc['expediente_id']);

$ruta = documentos_dir((int)$doc['expediente_id']) . '/' . $doc['nombre_disco'];
if (!is_file($ruta)) fail('El archivo ya no está disponible en el servidor.', 404);

header('Content-Type: ' . ($doc['tipo_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['nombre_archivo']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
exit;
