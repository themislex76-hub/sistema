<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Confirma o descarta una sugerencia de calendario (11 nov 2026) -- SIEMPRE
// requiere este tap, nunca se aplica sola (el texto de un evento de
// Google Calendar es libre, puede haber tocayos/errores de dedo).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$aplicar = !empty($in['aplicar']);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT s.*, v.abogado_id AS calendario_abogado_id
     FROM google_calendario_sugerencias s
     LEFT JOIN google_calendarios_vigilados v ON v.calendar_id = s.calendar_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$sug = $stmt->fetch();
if (!$sug) fail('Sugerencia no encontrada.', 404);
if ($sug['estado'] !== 'pendiente') fail('Esta sugerencia ya se resolvió.', 400);

if ($user['rol'] !== 'administrador' && (int)$sug['calendario_abogado_id'] !== (int)$user['id']) {
    fail('No tienes permiso sobre esta sugerencia.', 403);
}

if (!$aplicar) {
    $pdo->prepare("UPDATE google_calendario_sugerencias SET estado = 'descartada', resuelto_en = NOW() WHERE id = :id")
        ->execute([':id' => $id]);
    respond(['ok' => true]);
}

if (!$sug['expediente_id'] || !$sug['etapa_key']) fail('Esta sugerencia no tiene expediente/etapa para aplicar.', 400);

$expedienteId = (int)$sug['expediente_id'];
guard_expediente_access($pdo, $user, $expedienteId);

// UPSERT dirigido SOLO a fecha_programada/hora -- nunca toca fecha (la
// celebrada) ni resultado si ya existían para esta etapa.
$pdo->prepare(
    'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha_programada, hora)
     VALUES (:eid, :key, :fecha, :hora)
     ON DUPLICATE KEY UPDATE fecha_programada = VALUES(fecha_programada), hora = VALUES(hora)'
)->execute([
    ':eid' => $expedienteId,
    ':key' => $sug['etapa_key'],
    ':fecha' => $sug['fecha'],
    ':hora' => $sug['hora'],
]);

log_historial(
    $pdo, $expedienteId, $user,
    'etapa_' . $sug['etapa_key'], null,
    "fecha agendada: {$sug['fecha']} (confirmado desde Google Calendar: \"{$sug['titulo_evento']}\")"
);
$pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $expedienteId]);

$pdo->prepare("UPDATE google_calendario_sugerencias SET estado = 'aplicada', resuelto_en = NOW() WHERE id = :id")
    ->execute([':id' => $id]);

respond(['ok' => true]);
