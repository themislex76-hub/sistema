<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Sirve un archivo que un cliente mandó por WhatsApp (imagen o documento,
// típicamente un comprobante de pago) y que ya se descargó y guardó en
// data/whatsapp_media/ -- ver procesar_media_entrante() en
// whatsapp_procesar.php. Mismo control de acceso que prospectos_mensajes.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT telefono, media_ruta, media_mime FROM whatsapp_conversaciones WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row || !$row['media_ruta']) fail('Archivo no encontrado.', 404);

if ($user['rol'] !== 'administrador') {
    $chk = $pdo->prepare('SELECT asignado_a FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $row['telefono']]);
    $prospecto = $chk->fetch();
    if (!$prospecto || (int)$prospecto['asignado_a'] !== (int)$user['id']) {
        fail('No tienes acceso a este archivo.', 403);
    }
}

// La ruta guardada ya viene armada como "telefono/archivo.ext" desde
// procesar_media_entrante() -- se valida que no traiga ".." por si acaso,
// aunque nunca se construye así.
$rutaRelativa = (string)$row['media_ruta'];
if (str_contains($rutaRelativa, '..')) fail('Ruta inválida.', 400);
$ruta = __DIR__ . '/../data/whatsapp_media/' . $rutaRelativa;
if (!is_file($ruta)) fail('El archivo ya no está disponible en el servidor.', 404);

header('Content-Type: ' . ($row['media_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="' . basename($ruta) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
exit;
