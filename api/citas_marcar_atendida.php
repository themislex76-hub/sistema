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
$stmt = $pdo->prepare('SELECT id, usuario_id, estado, telefono FROM citas_asesoria WHERE id = :id');
$stmt->execute([':id' => $id]);
$cita = $stmt->fetch();
if (!$cita) fail('Cita no encontrada.', 404);

$esAdmin = $user['rol'] === 'administrador';
if (!$esAdmin && (int)$cita['usuario_id'] !== (int)$user['id']) {
    fail('No tienes acceso a esta cita.', 403);
}

// Bug real detectado en producción (nov-2026): se marcó como atendida
// una cita todavía en 'pendiente_pago' (sin pagar) -- eso la sacó de
// "Próximas asesorías agendadas" SIN liberar el horario (el sistema
// seguía viéndolo ocupado) y además le quitó la pausa al bot para ese
// cliente, como si la llamada ya se hubiera dado. Solo una cita
// realmente pagada (estado='confirmada') puede marcarse como atendida.
if ($cita['estado'] !== 'confirmada') {
    fail('Esta cita todavía no está pagada (estado: ' . $cita['estado'] . ') -- no se puede marcar como atendida. Si el horario ya no debe seguir ocupado, cancélala en vez de marcarla como atendida.', 409);
}

$upd = $pdo->prepare('UPDATE citas_asesoria SET atendida = 1 WHERE id = :id');
$upd->execute([':id' => $id]);

// Bug real detectado en producción: al marcar la cita como atendida, el
// prospecto se quedaba con pausado_bot=1 para siempre -- la llamada ya se
// dio, ya no hay nada pendiente que un humano tenga que seguir, así que
// se reactiva el bot para que conteste solo si esa persona vuelve a
// escribir por otra cosa más adelante (igual que al marcar un prospecto
// como "Descartado" -- ver prospectos_update.php).
if ($cita['telefono']) {
    $pdo->prepare('UPDATE prospectos SET pausado_bot = 0 WHERE telefono = :t')->execute([':t' => $cita['telefono']]);
}

respond(['ok' => true]);
