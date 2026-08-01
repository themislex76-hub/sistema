<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del prospecto.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM prospectos WHERE id = :id');
$stmt->execute([':id' => $id]);
$prospecto = $stmt->fetch();
if (!$prospecto) fail('Prospecto no encontrado.', 404);
if ($prospecto['expediente_id']) fail('Este prospecto ya fue convertido en expediente.', 409);

// Se crea sin abogado asignado — el Administrador lo asigna a un socio
// después, desde el propio expediente, igual que con cualquier otro asunto.
$notas = trim("Prospecto captado por WhatsApp ({$prospecto['estado_ubicacion']}).\n\n{$prospecto['resumen_caso']}");

$stmt = $pdo->prepare(
    'INSERT INTO expedientes (status, actor, telefono, notas_internas) VALUES (:status, :actor, :telefono, :notas)'
);
$stmt->execute([
    ':status' => 'Status',
    ':actor' => $prospecto['nombre'],
    ':telefono' => $prospecto['telefono'],
    ':notas' => $notas,
]);
$expedienteId = (int)$pdo->lastInsertId();

log_historial($pdo, $expedienteId, $user, 'expediente', null, 'Expediente creado desde prospecto de WhatsApp');

$upd = $pdo->prepare("UPDATE prospectos SET expediente_id = :eid, estatus = 'convertido', pausado_bot = 1 WHERE id = :id");
$upd->execute([':eid' => $expedienteId, ':id' => $id]);

respond(['expediente_id' => $expedienteId], 201);
