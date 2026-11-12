<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

const TAMANO_MAXIMO_BYTES_SUPERVISOR = 20 * 1024 * 1024; // 20 MB, igual que documentos_upload.php
const EXTENSION_PERMITIDA_SUPERVISOR = ['pdf', 'jpg', 'jpeg', 'png'];

// Envío como multipart/form-data (no JSON) porque puede traer un archivo
// adjunto (foto/documento que el abogado sube como prueba de su respuesta,
// ej. una cédula de notificación).
$id = (int)($_POST['id'] ?? 0);
$respuesta = trim((string)($_POST['respuesta'] ?? ''));
if ($id <= 0) fail('Falta el id de la pregunta.', 400);

$archivoSubido = isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE;
if ($respuesta === '' && !$archivoSubido) fail('Escribe una respuesta o adjunta un documento.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM supervisor_preguntas WHERE id = :id');
$stmt->execute([':id' => $id]);
$pregunta = $stmt->fetch();
if (!$pregunta) fail('Pregunta no encontrada.', 404);

// Mismo control de acceso que cualquier otro endpoint de escritura sobre
// un expediente -- administrador ve/edita todos, un abogado solo lo suyo.
guard_expediente_access($pdo, $user, (int)$pregunta['expediente_id']);

$rutaDocumento = null;
$mimeDocumento = null;
if ($archivoSubido) {
    $archivo = $_FILES['archivo'];
    if ($archivo['error'] !== UPLOAD_ERR_OK) fail('Error al subir el archivo (código ' . $archivo['error'] . ').', 400);
    if ($archivo['size'] <= 0 || $archivo['size'] > TAMANO_MAXIMO_BYTES_SUPERVISOR) fail('El archivo debe pesar entre 1 byte y 20 MB.', 400);
    $ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSION_PERMITIDA_SUPERVISOR, true)) fail('Solo se aceptan PDF, JPG o PNG.', 400);

    $nombreDisco = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = documentos_dir((int)$pregunta['expediente_id']) . '/' . $nombreDisco;
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) fail('No se pudo guardar el archivo en el servidor.', 500);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeDocumento = $finfo ? (finfo_file($finfo, $destino) ?: null) : null;
    if ($finfo) finfo_close($finfo);
    $rutaDocumento = $destino;

    $insDoc = $pdo->prepare(
        'INSERT INTO expediente_documentos (expediente_id, nombre_archivo, nombre_disco, tipo_mime, tamano_bytes, categoria, subido_por)
         VALUES (:eid, :nombre, :disco, :mime, :tamano, :categoria, :uid)'
    );
    $insDoc->execute([
        ':eid' => $pregunta['expediente_id'],
        ':nombre' => $archivo['name'],
        ':disco' => $nombreDisco,
        ':mime' => $mimeDocumento,
        ':tamano' => $archivo['size'],
        ':categoria' => 'otro',
        ':uid' => $user['id'],
    ]);
    if ($respuesta === '') $respuesta = '(el abogado adjuntó un documento/foto sin comentario de texto)';
}

$resultado = ia_interpretar_respuesta_supervisor($pregunta['pregunta'], $respuesta, date('Y-m-d'), $rutaDocumento, $mimeDocumento);
$fechaMencionada = $resultado['fecha_mencionada'] ?? null;

$aplicado = false;
if ($fechaMencionada && in_array($pregunta['etapa_key'], ETAPA_KEYS, true)) {
    // Nunca sobrescribe una etapa que ya tiene fecha capturada -- mismo
    // principio que cron_analizar_acuerdos_ia.php con los acuerdos de
    // boletín.
    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :eid');
    $etapasStmt->execute([':eid' => $pregunta['expediente_id']]);
    $etapasExistentes = $etapasStmt->fetchAll();
    $filaExistente = null;
    foreach ($etapasExistentes as $e) {
        if ($e['etapa_key'] === $pregunta['etapa_key']) { $filaExistente = $e; break; }
    }
    $tieneFecha = $filaExistente && !empty($filaExistente['fecha']) && $filaExistente['fecha'] !== '0000-00-00';

    // Mismo candado que en los demás mecanismos que llenan una etapa sola
    // (confirmado real que la IA puede atribuir una fecha a la etapa
    // equivocada, rompiendo el orden del trámite) -- aquí el riesgo es
    // menor porque la etapa ya viene fija (es la de la pregunta), pero
    // la fecha sigue siendo interpretada por IA de texto libre.
    if (!$tieneFecha && !etapa_fecha_es_consistente($pregunta['etapa_key'], $fechaMencionada, $etapasExistentes)) {
        log_historial(
            $pdo, (int)$pregunta['expediente_id'], $user,
            'auditoria_ia_rechazo_' . $pregunta['etapa_key'], null,
            "fecha sugerida $fechaMencionada (respuesta del abogado al supervisor) rechazada por romper el orden cronológico con otras etapas ya registradas"
        );
        $tieneFecha = true; // reutiliza la misma rama de "no aplicar" de abajo
    }

    if (!$tieneFecha) {
        $pdo->prepare(
            'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha)
             VALUES (:eid, :key, :fecha)
             ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)'
        )->execute([':eid' => $pregunta['expediente_id'], ':key' => $pregunta['etapa_key'], ':fecha' => $fechaMencionada]);

        $etiqueta = ETAPA_LABELS[$pregunta['etapa_key']] ?? $pregunta['etapa_key'];
        log_historial(
            $pdo, (int)$pregunta['expediente_id'], $user,
            'etapa_' . $pregunta['etapa_key'], null, "fecha: $fechaMencionada (confirmado por el abogado al supervisor de IA)"
        );
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $pregunta['expediente_id']]);
        $aplicado = true;
    }
}

$pdo->prepare(
    'UPDATE supervisor_preguntas
     SET respuesta = :resumen, fecha_aplicada = :fecha, estado = :estado, respondido_por = :uid, respondido_en = NOW()
     WHERE id = :id'
)->execute([
    ':resumen' => $respuesta,
    ':fecha' => $aplicado ? $fechaMencionada : null,
    ':estado' => $aplicado ? 'aplicada' : 'respondida',
    ':uid' => $user['id'],
    ':id' => $id,
]);

respond(['ok' => true, 'aplicado' => $aplicado]);
