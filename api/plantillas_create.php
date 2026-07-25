<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$nombre = trim((string)($in['nombre'] ?? ''));
$descripcion = trim((string)($in['descripcion'] ?? ''));
$b64 = (string)($in['base64'] ?? '');

if ($nombre === '') fail('Falta el nombre de la plantilla.', 400);
if ($b64 === '') fail('Falta el archivo.', 400);

$bytes = base64_decode($b64, true);
if ($bytes === false || strlen($bytes) < 4 || substr($bytes, 0, 2) !== 'PK') {
    fail('El archivo no parece un .docx válido.', 400);
}

$dir = __DIR__ . '/../data/plantillas';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$nombreDisco = bin2hex(random_bytes(16)) . '.docx';
file_put_contents($dir . '/' . $nombreDisco, $bytes);

$user = current_user();
$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO plantillas_docx (nombre, descripcion, nombre_disco, creado_por) VALUES (:nombre, :descripcion, :disco, :uid)'
);
$stmt->execute([
    ':nombre' => $nombre,
    ':descripcion' => $descripcion !== '' ? $descripcion : null,
    ':disco' => $nombreDisco,
    ':uid' => $user['id'],
]);

respond(['id' => (int)$pdo->lastInsertId()]);
