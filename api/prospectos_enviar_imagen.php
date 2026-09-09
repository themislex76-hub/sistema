<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Manda una imagen por WhatsApp desde el sistema (ej. comprobante de una
// devolución) -- mismo control de acceso que prospectos_enviar.php
// (texto), pero recibe la imagen como multipart/form-data en vez de
// JSON, porque es un archivo real. Se guarda igual que las imágenes que
// manda el cliente (data/whatsapp_media/<telefono>/, media_ruta/
// media_mime en whatsapp_conversaciones) para que se vea como miniatura
// en el historial del chat, no solo como texto plano.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$telefono = trim((string)($_POST['telefono'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$archivo = $_FILES['imagen'] ?? null;
if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail('No se recibió ninguna imagen.', 400);
}

// WhatsApp solo acepta estos 3 formatos de imagen -- se valida por el
// contenido real del archivo (finfo), no por la extensión del nombre ni
// por lo que el navegador diga que es, para no confiar en datos que
// vienen del cliente.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeReal = finfo_file($finfo, $archivo['tmp_name']);
finfo_close($finfo);
$mimesPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($mimesPermitidos[$mimeReal])) {
    fail('Solo se pueden mandar imágenes JPG, PNG o WEBP.', 400);
}
// Mismo límite que acepta WhatsApp para imágenes (5 MB).
if ($archivo['size'] > 5 * 1024 * 1024) {
    fail('La imagen pesa más de 5 MB -- ese es el límite de WhatsApp para imágenes.', 400);
}

$pdo = db();
if ($user['rol'] !== 'administrador') {
    $chk = $pdo->prepare('SELECT asignado_a FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $telefono]);
    $prospecto = $chk->fetch();
    if (!$prospecto || (int)$prospecto['asignado_a'] !== (int)$user['id']) {
        fail('No tienes acceso a este prospecto.', 403);
    }
}

$mediaId = whatsapp_subir_media($archivo['tmp_name'], $archivo['name'], $mimeReal);
if ($mediaId === null) {
    fail('No se pudo subir la imagen a WhatsApp. Revisa las credenciales del bot.', 502);
}
if (!whatsapp_enviar_imagen($telefono, $mediaId, $caption)) {
    fail('No se pudo enviar la imagen por WhatsApp.', 502);
}

$stmt = $pdo->prepare(
    "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'humano')"
);
$stmt->execute([':t' => $telefono, ':texto' => $caption]);
$idPropio = (int)$pdo->lastInsertId();

$ext = $mimesPermitidos[$mimeReal];
$carpetaTelefono = preg_replace('/[^0-9A-Za-z]/', '', $telefono) ?: 'sin_numero';
$dirCarpeta = __DIR__ . '/../data/whatsapp_media/' . $carpetaTelefono;
if (!is_dir($dirCarpeta)) {
    @mkdir($dirCarpeta, 0755, true);
}
$nombreDisco = $idPropio . '.' . $ext;
move_uploaded_file($archivo['tmp_name'], $dirCarpeta . '/' . $nombreDisco);
$rutaRelativa = $carpetaTelefono . '/' . $nombreDisco;

$upd = $pdo->prepare('UPDATE whatsapp_conversaciones SET media_ruta = :ruta, media_mime = :mime WHERE id = :id');
$upd->execute([':ruta' => $rutaRelativa, ':mime' => $mimeReal, ':id' => $idPropio]);

respond(['ok' => true]);
