<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$expedienteId = (int)($_GET['expediente_id'] ?? 0);
if ($expedienteId <= 0) fail('Falta el expediente_id.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $expedienteId);

$stmt = $pdo->prepare(
    'SELECT d.id, d.expediente_id, d.nombre_archivo, d.tipo_mime, d.tamano_bytes, d.categoria, d.creado_en,
            u.nombre AS subido_por_nombre
     FROM expediente_documentos d
     LEFT JOIN usuarios u ON u.id = d.subido_por
     WHERE d.expediente_id = :eid
     ORDER BY d.creado_en DESC'
);
$stmt->execute([':eid' => $expedienteId]);

respond(['documentos' => $stmt->fetchAll()]);
