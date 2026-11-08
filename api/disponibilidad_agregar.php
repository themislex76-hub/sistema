<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$usuario = require_login();

$body = json_input();
$diaSemana = (int)($body['dia_semana'] ?? 0);
$horaInicio = trim((string)($body['hora_inicio'] ?? ''));
$horaFin = trim((string)($body['hora_fin'] ?? ''));

if ($diaSemana < 1 || $diaSemana > 7) fail('Día de la semana inválido.', 422);
if (!preg_match('/^\d{2}:\d{2}$/', $horaInicio) || !preg_match('/^\d{2}:\d{2}$/', $horaFin)) {
    fail('Formato de hora inválido.', 422);
}
if ($horaInicio >= $horaFin) fail('La hora de inicio debe ser antes que la hora de fin.', 422);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO disponibilidad_asesorias (usuario_id, dia_semana, hora_inicio, hora_fin)
     VALUES (:uid, :dia, :inicio, :fin)'
);
$stmt->execute([
    ':uid' => $usuario['id'],
    ':dia' => $diaSemana,
    ':inicio' => $horaInicio,
    ':fin' => $horaFin,
]);

respond(['id' => (int)$pdo->lastInsertId()]);
