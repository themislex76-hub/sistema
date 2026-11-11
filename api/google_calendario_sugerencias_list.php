<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Sugerencias pendientes de confirmar (11 nov 2026) -- un abogado solo ve
// las de sus propios expedientes (o sin match, ligadas a un calendario que
// le pertenece a él); el Administrador las ve todas. Mismo patrón de
// "sugiere, nunca aplica sola" que supervisor_preguntas_list.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$esAdmin = $user['rol'] === 'administrador';

$sql = "SELECT s.id, s.expediente_id, s.etapa_key, s.fecha, s.hora, s.titulo_evento, s.estado, s.creado_en,
               e.actor AS expediente_actor, e.demandado AS expediente_demandado, e.exp AS expediente_exp,
               v.nombre_calendario
        FROM google_calendario_sugerencias s
        LEFT JOIN expedientes e ON e.id = s.expediente_id
        LEFT JOIN google_calendarios_vigilados v ON v.calendar_id = s.calendar_id
        WHERE s.estado = 'pendiente'";
if (!$esAdmin) {
    $sql .= ' AND v.abogado_id = :uid';
}
$sql .= ' ORDER BY s.fecha ASC';

$stmt = $pdo->prepare($sql);
if (!$esAdmin) $stmt->execute([':uid' => $user['id']]);
else $stmt->execute();

respond(['sugerencias' => $stmt->fetchAll()]);
