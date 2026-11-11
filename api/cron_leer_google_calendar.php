<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (DonWeb/cPanel) una o
// dos veces al día -- NO se abre desde el navegador ni tiene sesión.
// Google Calendar → sistema (11 nov 2026, pedido explícito del usuario):
// cada abogado agenda sus audiencias/citatorios de conciliación directo
// en su propio sub-calendario de Google (ej. "Audiencias Lindsay"), en
// vez de capturarlas en "Etapas del juicio" -- antes esos compromisos
// eran invisibles para el sistema (no salían en Agenda, no mandaban
// recordatorio). Esto lee esos calendarios (ver
// google_calendarios_vigilados, configurado por el Administrador desde
// "Equipo") y, para cada evento nuevo, le pide a la IA que diga a qué
// expediente y etapa corresponde -- NUNCA lo aplica solo (el texto de un
// evento es libre, puede haber tocayos/errores de dedo): solo lo deja
// como sugerencia pendiente de un tap de confirmación (ver
// google_calendario_sugerencia_resolver.php).
//
// Candado contra duplicados: la clave única (calendar_id, google_event_id)
// en google_calendario_sugerencias -- un evento ya visto antes (en
// cualquier estado) nunca se vuelve a insertar ni a re-sugerir.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: 1-2 veces al día.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/google_calendar_helpers.php';
require_once __DIR__ . '/push_helpers.php';

$pdo = db();

$calendarios = $pdo->query(
    'SELECT id, calendar_id, nombre_calendario, abogado_id, usuario_lector_id
     FROM google_calendarios_vigilados WHERE activo = 1'
)->fetchAll();

if (!$calendarios) {
    echo "Sin calendarios de Google vigilados todavía (configúralos desde Equipo).\n";
    exit;
}

$tokensPorLector = []; // cache -- varios calendarios suelen compartir el mismo lector
$eventosRevisados = 0;
$sugerenciasNuevas = 0;
$sinMatch = 0;
$fallidos = 0;

foreach ($calendarios as $cal) {
    $lectorId = (int)$cal['usuario_lector_id'];
    if (!isset($tokensPorLector[$lectorId])) {
        $tokensPorLector[$lectorId] = google_obtener_access_token($pdo, $lectorId);
    }
    $accessToken = $tokensPorLector[$lectorId];
    if ($accessToken === null) continue; // ese lector ya no tiene Google conectado -- se omite, no truena el lote

    $eventos = google_listar_eventos($accessToken, $cal['calendar_id']);
    if (!$eventos) continue;

    // Expedientes activos de ESTE abogado -- mismo criterio de "activo"
    // que ya usa cron_auditoria_expediente_ia.php (concluido_manual=0 y
    // status no indica cierre).
    $expStmt = $pdo->prepare(
        "SELECT id, actor, demandado, exp, status FROM expedientes
         WHERE concluido_manual = 0 AND (abogado_id = :aid OR abogado_id_2 = :aid)"
    );
    $expStmt->execute([':aid' => $cal['abogado_id']]);
    $expedientes = array_values(array_filter($expStmt->fetchAll(), function ($r) {
        $s = strtolower((string)($r['status'] ?? ''));
        return strpos($s, 'pagado') === false && strpos($s, 'ya no quiso') === false;
    }));

    foreach ($eventos as $ev) {
        $yaVisto = $pdo->prepare('SELECT 1 FROM google_calendario_sugerencias WHERE calendar_id = :cal AND google_event_id = :evid');
        $yaVisto->execute([':cal' => $cal['calendar_id'], ':evid' => $ev['google_event_id']]);
        if ($yaVisto->fetch()) continue; // candado contra duplicados -- ya se procesó antes, en cualquier estado

        $eventosRevisados++;

        if (!$expedientes) {
            $estado = 'sin_match';
            $expedienteId = null;
            $etapaKey = null;
            $sinMatch++;
        } else {
            $resultado = ia_matchear_evento_calendario($ev['titulo'], $ev['descripcion'], $ev['fecha'], $expedientes);
            if ($resultado === null) {
                $fallidos++;
                continue; // se reintenta en la siguiente corrida (no se inserta nada)
            }
            $expedienteId = $resultado['expediente_id'];
            $etapaKey = $resultado['etapa_key'];
            if ($expedienteId && $etapaKey) {
                $estado = 'pendiente';
                $sugerenciasNuevas++;
            } else {
                $estado = 'sin_match';
                $sinMatch++;
            }
        }

        $pdo->prepare(
            'INSERT INTO google_calendario_sugerencias
             (calendar_id, google_event_id, expediente_id, etapa_key, fecha, hora, titulo_evento, estado, google_updated)
             VALUES (:cal, :evid, :eid, :etapa, :fecha, :hora, :titulo, :estado, :updated)'
        )->execute([
            ':cal' => $cal['calendar_id'],
            ':evid' => $ev['google_event_id'],
            ':eid' => $expedienteId,
            ':etapa' => $etapaKey,
            ':fecha' => $ev['fecha'],
            ':hora' => $ev['hora'],
            ':titulo' => $ev['titulo'],
            ':estado' => $estado,
            ':updated' => date('Y-m-d H:i:s', strtotime($ev['updated'])),
        ]);

        if ($estado === 'pendiente') {
            push_enviar_a_usuario(
                $pdo, (int)$cal['abogado_id'], '📅 Evento de tu calendario por confirmar',
                "\"{$ev['titulo']}\" ({$ev['fecha']}) -- entra a Preguntas al abogado para confirmar a qué expediente corresponde.",
                '/sistema/?pregunta_calendario=1'
            );
        }
    }
}

echo "$eventosRevisados evento(s) revisado(s), $sugerenciasNuevas sugerencia(s) nueva(s) con match, "
    . "$sinMatch sin match claro (quedan registrados, no se vuelven a procesar), $fallidos fallido(s) (se reintentan después).\n";
