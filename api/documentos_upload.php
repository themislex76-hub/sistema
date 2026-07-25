<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

const TAMANO_MAXIMO_BYTES = 20 * 1024 * 1024; // 20 MB por archivo

$expedienteId = (int)($_POST['expediente_id'] ?? 0);
$categoria = (string)($_POST['categoria'] ?? 'otro');
if ($expedienteId <= 0) fail('Falta el expediente_id.', 400);
if (!in_array($categoria, CATEGORIA_DOCUMENTO_KEYS, true)) $categoria = 'otro';

$pdo = db();
guard_expediente_access($pdo, $user, $expedienteId);

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
    fail('No se recibió ningún archivo.', 400);
}
$archivo = $_FILES['archivo'];
if ($archivo['error'] !== UPLOAD_ERR_OK) {
    fail('Error al subir el archivo (código ' . $archivo['error'] . '). Si es muy pesado, prueba con uno más ligero.', 400);
}
if ($archivo['size'] <= 0 || $archivo['size'] > TAMANO_MAXIMO_BYTES) {
    fail('El archivo debe pesar entre 1 byte y 20 MB.', 400);
}

$nombreOriginal = (string)$archivo['name'];
$ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
if (!in_array($ext, EXTENSION_DOCUMENTO_PERMITIDA, true)) {
    fail('Tipo de archivo no permitido. Solo se aceptan: ' . implode(', ', EXTENSION_DOCUMENTO_PERMITIDA) . '.', 400);
}

$nombreDisco = bin2hex(random_bytes(16)) . '.' . $ext;
$destino = documentos_dir($expedienteId) . '/' . $nombreDisco;
if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
    fail('No se pudo guardar el archivo en el servidor.', 500);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$tipoMime = $finfo ? (finfo_file($finfo, $destino) ?: null) : null;
if ($finfo) finfo_close($finfo);

$stmt = $pdo->prepare(
    'INSERT INTO expediente_documentos (expediente_id, nombre_archivo, nombre_disco, tipo_mime, tamano_bytes, categoria, subido_por)
     VALUES (:eid, :nombre, :disco, :mime, :tamano, :categoria, :uid)'
);
$stmt->execute([
    ':eid' => $expedienteId,
    ':nombre' => $nombreOriginal,
    ':disco' => $nombreDisco,
    ':mime' => $tipoMime,
    ':tamano' => $archivo['size'],
    ':categoria' => $categoria,
    ':uid' => $user['id'],
]);

respond(['id' => (int)$pdo->lastInsertId()]);
