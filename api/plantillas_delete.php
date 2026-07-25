<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM plantillas_docx WHERE id = :id');
$stmt->execute([':id' => $id]);
$p = $stmt->fetch();
if (!$p) fail('Plantilla no encontrada.', 404);

$ruta = __DIR__ . '/../data/plantillas/' . $p['nombre_disco'];
if (is_file($ruta)) unlink($ruta);

$del = $pdo->prepare('DELETE FROM plantillas_docx WHERE id = :id');
$del->execute([':id' => $id]);

respond(['ok' => true]);
