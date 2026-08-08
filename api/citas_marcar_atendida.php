<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El abogado (o el Administrador) marca una asesoría como atendida una vez
// que ya dio la llamada — es lo que la saca de "Próximas asesorías
// agendadas". A propósito no se quita sola por fecha (ver citas_list.php):
// así ninguna cita pagada se pierde de vista si nadie confirma que se
// atendió.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id de la cita.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, usuario_id FROM citas_asesoria WHERE id = :id');
$stmt->execute([':id' => $id]);
$cita = $stmt->fetch();
if (!$cita) fail('Cita no encontrada.', 404);

$esAdmin = $user['rol'] === 'administrador';
if (!$esAdmin && (int)$cita['usuario_id'] !== (int)$user['id']) {
    fail('No tienes acceso a esta cita.', 403);
}

$upd = $pdo->prepare('UPDATE citas_asesoria SET atendida = 1 WHERE id = :id');
$upd->execute([':id' => $id]);

respond(['ok' => true]);
