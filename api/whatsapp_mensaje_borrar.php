<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Borra un mensaje del historial de WhatsApp (Prospectos o Conversaciones)
// -- solo Administrador, es una acción destructiva y sin deshacer. Si el
// mensaje tenía un archivo adjunto guardado, también se borra del disco.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT media_ruta FROM whatsapp_conversaciones WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('Mensaje no encontrado.', 404);

$pdo->prepare('DELETE FROM whatsapp_conversaciones WHERE id = :id')->execute([':id' => $id]);

if (!empty($row['media_ruta']) && !str_contains((string)$row['media_ruta'], '..')) {
    $ruta = __DIR__ . '/../data/whatsapp_media/' . $row['media_ruta'];
    if (is_file($ruta)) {
        @unlink($ruta);
    }
}

respond(['ok' => true]);
