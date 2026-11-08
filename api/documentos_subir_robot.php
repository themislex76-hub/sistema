<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Llamado por los robots de monitoreo (por ahora solo Federal) cuando
// encuentran un acuerdo nuevo y logran descargar su PDF -- lo guarda en el
// mismo almacén de documentos que ya usa cada expediente (pestaña
// "Documentos"), para que el aviso pueda enlazarlo directo (ver
// avisos_ingest.php, parámetro documento_id) sin que nadie tenga que
// entrar al portal de origen a mano.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_robot_key();

const TAMANO_MAXIMO_BYTES_ROBOT = 20 * 1024 * 1024; // 20 MB, igual que la subida manual

$in = json_input();
$expedienteId = (int)($in['expediente_id'] ?? 0);
$nombreOriginal = trim((string)($in['nombre_archivo'] ?? ''));
$contenidoBase64 = (string)($in['contenido_base64'] ?? '');
$categoria = (string)($in['categoria'] ?? 'acuerdo');

if ($expedienteId <= 0) fail('Falta el expediente_id.', 400);
if ($nombreOriginal === '') fail('Falta el nombre del archivo.', 400);
if ($contenidoBase64 === '') fail('Falta el contenido del archivo.', 400);
if (!in_array($categoria, CATEGORIA_DOCUMENTO_KEYS, true)) $categoria = 'otro';

$ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
if (!in_array($ext, EXTENSION_DOCUMENTO_PERMITIDA, true)) {
    fail('Tipo de archivo no permitido. Solo se aceptan: ' . implode(', ', EXTENSION_DOCUMENTO_PERMITIDA) . '.', 400);
}

$contenido = base64_decode($contenidoBase64, true);
if ($contenido === false || $contenido === '') fail('El contenido del archivo no es un base64 válido.', 400);
if (strlen($contenido) > TAMANO_MAXIMO_BYTES_ROBOT) fail('El archivo pesa más de 20 MB.', 400);

$pdo = db();
$existeExpediente = $pdo->prepare('SELECT id FROM expedientes WHERE id = :id');
$existeExpediente->execute([':id' => $expedienteId]);
if (!$existeExpediente->fetch()) fail('El expediente no existe.', 404);

$nombreDisco = bin2hex(random_bytes(16)) . '.' . $ext;
$destino = documentos_dir($expedienteId) . '/' . $nombreDisco;
if (file_put_contents($destino, $contenido) === false) {
    fail('No se pudo guardar el archivo en el servidor.', 500);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$tipoMime = $finfo ? (finfo_file($finfo, $destino) ?: null) : null;
if ($finfo) finfo_close($finfo);

$stmt = $pdo->prepare(
    'INSERT INTO expediente_documentos (expediente_id, nombre_archivo, nombre_disco, tipo_mime, tamano_bytes, categoria, subido_por)
     VALUES (:eid, :nombre, :disco, :mime, :tamano, :categoria, NULL)'
);
$stmt->execute([
    ':eid' => $expedienteId,
    ':nombre' => $nombreOriginal,
    ':disco' => $nombreDisco,
    ':mime' => $tipoMime,
    ':tamano' => strlen($contenido),
    ':categoria' => $categoria,
]);

respond(['id' => (int)$pdo->lastInsertId()], 201);
