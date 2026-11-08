<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Solo Administrador puede borrar un expediente -- es irreversible (pensado
// para corregir altas duplicadas), por eso no está disponible para el socio
// asignado. Las tablas relacionadas (etapas, pagos, pendientes, notas,
// historial, documentos, avisos de boletín) se borran solas por el
// ON DELETE CASCADE de sus llaves foráneas.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM expedientes WHERE id = :id');
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) fail('Expediente no encontrado.', 404);

$pdo->prepare('DELETE FROM expedientes WHERE id = :id')->execute([':id' => $id]);

// Los documentos subidos viven en disco, fuera de la base de datos -- la fila
// de expediente_documentos ya se fue por el CASCADE, pero el archivo no.
$dir = __DIR__ . '/../data/documentos/' . $id;
if (is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) unlink($f);
    }
    @rmdir($dir);
}

respond(['ok' => true]);
