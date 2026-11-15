<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Sugerencias pendientes de confirmar (11 nov 2026) -- desde que el cron
// pasó a aplicar solo los matches claros (ver cron_leer_google_calendar.php),
// lo único que llega aquí como 'pendiente' es el caso de conflicto real:
// la etapa ya tenía otra fecha agendada distinta a la del evento. Un
// abogado solo ve las de sus propios expedientes; el Administrador las ve
// todas.
// Agregado 15 nov 2026, pedido explícito del usuario: también se incluyen
// las 'sin_match' (la IA no encontró con claridad a qué expediente/etapa
// corresponde el evento) -- antes quedaban guardadas sin que nadie las
// viera nunca. El abogado dueño del calendario es quien mejor puede
// reconocer de qué se trata (cita personal a ignorar, o un expediente que
// el match no alcanzó a identificar), por eso se le muestra a él mismo,
// no solo al Administrador.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$esAdmin = $user['rol'] === 'administrador';

$sql = "SELECT s.id, s.expediente_id, s.etapa_key, s.fecha, s.hora, s.titulo_evento, s.estado, s.creado_en,
               e.actor AS expediente_actor, e.demandado AS expediente_demandado, e.exp AS expediente_exp,
               v.nombre_calendario, au.nombre AS calendario_abogado_nombre,
               ee.fecha_programada AS fecha_actual_programada
        FROM google_calendario_sugerencias s
        LEFT JOIN expedientes e ON e.id = s.expediente_id
        LEFT JOIN google_calendarios_vigilados v ON v.calendar_id = s.calendar_id
        LEFT JOIN usuarios au ON au.id = v.abogado_id
        LEFT JOIN expediente_etapas ee ON ee.expediente_id = s.expediente_id AND ee.etapa_key = s.etapa_key
        WHERE s.estado IN ('pendiente', 'sin_match')";
if (!$esAdmin) {
    $sql .= ' AND v.abogado_id = :uid';
}
$sql .= ' ORDER BY s.fecha ASC';

$stmt = $pdo->prepare($sql);
if (!$esAdmin) $stmt->execute([':uid' => $user['id']]);
else $stmt->execute();

respond(['sugerencias' => $stmt->fetchAll()]);
