<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del prospecto.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM prospectos WHERE id = :id');
$stmt->execute([':id' => $id]);
$prospecto = $stmt->fetch();
if (!$prospecto) fail('Prospecto no encontrado.', 404);
if ($user['rol'] !== 'administrador' && (int)$prospecto['asignado_a'] !== (int)$user['id']) {
    fail('No tienes acceso a este prospecto.', 403);
}
if ($prospecto['expediente_id']) fail('Este prospecto ya fue convertido en expediente.', 409);

// Si ya se había turnado el prospecto a un abogado desde la vista
// Prospectos, el expediente nace asignado a ese mismo abogado; si no,
// nace sin asignar y el Administrador lo asigna después, igual que con
// cualquier otro asunto.
$notas = trim("Prospecto captado por WhatsApp ({$prospecto['estado_ubicacion']}).\n\n{$prospecto['resumen_caso']}");

$stmt = $pdo->prepare(
    'INSERT INTO expedientes (status, actor, telefono, notas_internas, abogado_id) VALUES (:status, :actor, :telefono, :notas, :abogado_id)'
);
$stmt->execute([
    ':status' => 'Status',
    ':actor' => $prospecto['nombre'],
    ':telefono' => $prospecto['telefono'],
    ':notas' => $notas,
    ':abogado_id' => $prospecto['asignado_a'] !== null ? (int)$prospecto['asignado_a'] : null,
]);
$expedienteId = (int)$pdo->lastInsertId();

log_historial($pdo, $expedienteId, $user, 'expediente', null, 'Expediente creado desde prospecto de WhatsApp');

$upd = $pdo->prepare("UPDATE prospectos SET expediente_id = :eid, estatus = 'convertido', pausado_bot = 1 WHERE id = :id");
$upd->execute([':eid' => $expedienteId, ':id' => $id]);

respond(['expediente_id' => $expedienteId], 201);
