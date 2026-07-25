<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM plantillas_docx WHERE id = :id');
$stmt->execute([':id' => $id]);
$p = $stmt->fetch();
if (!$p) fail('Plantilla no encontrada.', 404);

$ruta = __DIR__ . '/../data/plantillas/' . $p['nombre_disco'];
if (!is_file($ruta)) fail('El archivo de la plantilla ya no está disponible en el servidor.', 404);

respond(['nombre' => $p['nombre'], 'base64' => base64_encode(file_get_contents($ruta))]);
