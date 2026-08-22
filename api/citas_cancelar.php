<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Cancela una asesoría agendada (ej. el cliente faltó al respeto, ya no
// quiere, etc.) -- el horario se libera solo: citas_calcular_horarios_disponibles
// y citas_list.php ya filtran por estado IN ('confirmada','pendiente_pago'),
// así que en cuanto queda 'cancelada' deja de contar como ocupada y deja de
// aparecer en "Próximas asesorías agendadas". No manda ningún aviso al
// cliente -- eso, si hace falta, se hace a mano desde Conversaciones.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id de la cita.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, usuario_id, estado FROM citas_asesoria WHERE id = :id');
$stmt->execute([':id' => $id]);
$cita = $stmt->fetch();
if (!$cita) fail('Cita no encontrada.', 404);

$esAdmin = $user['rol'] === 'administrador';
if (!$esAdmin && (int)$cita['usuario_id'] !== (int)$user['id']) {
    fail('No tienes acceso a esta cita.', 403);
}

if ($cita['estado'] === 'cancelada') respond(['ok' => true]);

$upd = $pdo->prepare("UPDATE citas_asesoria SET estado = 'cancelada' WHERE id = :id");
$upd->execute([':id' => $id]);

respond(['ok' => true]);
