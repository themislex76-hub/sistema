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
// expediente y etapa corresponde.
//
// Autónomo (11 nov 2026, cambio pedido por el usuario tras la primera
// prueba real: "no quiero que tenga que estar confirmando... que la IA
// actualice el sistema"): si la IA da un match claro (nunca adivina entre
// candidatos ambiguos -- eso lo decide ia_matchear_evento_calendario())
// Y esa etapa todavía no tenía una fecha agendada distinta, se llena sola
// -- mismo principio que ya usan cron_analizar_acuerdos_ia.php,
// cron_analizar_documentos_ia.php y cron_auditoria_expediente_ia.php:
// queda en log_historial (auditable/corregible a mano) y pasa por el
// mismo candado de orden cronológico (etapa_fecha_es_consistente()). Solo
// se deja como 'pendiente' (requiere tap en "Preguntas al abogado") el
// único caso donde SÍ hace falta criterio humano: la etapa ya tenía una
// fecha agendada DISTINTA -- ahí no se sabe si es un reagendamiento real
// o un match equivocado, y sobrescribir en silencio sería arriesgado.
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
$aplicadosSolos = 0;
$pendientesConflicto = 0;
$rechazadosPorOrden = 0;
$sinMatch = 0;
$fallidos = 0;
$usuarioIA = ['id' => null, 'nombre' => 'IA (Google Calendar)'];

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
         WHERE concluido_manual = 0 AND (abogado_id = :aid1 OR abogado_id_2 = :aid2)"
    );
    $expStmt->execute([':aid1' => $cal['abogado_id'], ':aid2' => $cal['abogado_id']]);
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

            if (!$expedienteId || !$etapaKey) {
                $estado = 'sin_match';
                $sinMatch++;
            } else {
                $etapas = $pdo->prepare('SELECT etapa_key, fecha, fecha_programada FROM expediente_etapas WHERE expediente_id = :eid');
                $etapas->execute([':eid' => $expedienteId]);
                $etapasFilas = $etapas->fetchAll();

                $yaProgramada = null;
                foreach ($etapasFilas as $et) {
                    if ($et['etapa_key'] === $etapaKey && !empty($et['fecha_programada']) && $et['fecha_programada'] !== '0000-00-00') {
                        $yaProgramada = $et['fecha_programada'];
                        break;
                    }
                }

                if ($yaProgramada !== null && $yaProgramada !== $ev['fecha']) {
                    // Conflicto real -- la etapa ya tenía una fecha agendada
                    // distinta. No se sabe si es un reagendamiento o un match
                    // equivocado -- único caso que sí requiere un tap humano.
                    $estado = 'pendiente';
                    $pendientesConflicto++;
                } elseif (!etapa_fecha_es_consistente($etapaKey, $ev['fecha'], $etapasFilas)) {
                    $estado = 'rechazada';
                    $rechazadosPorOrden++;
                    log_historial(
                        $pdo, $expedienteId, $usuarioIA,
                        'calendario_ia_rechazo_' . $etapaKey, null,
                        "fecha agendada {$ev['fecha']} rechazada por romper el orden cronológico con otras etapas ya registradas (evento: \"{$ev['titulo']}\")"
                    );
                } else {
                    $pdo->prepare(
                        'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha_programada, hora)
                         VALUES (:eid, :key, :fecha, :hora)
                         ON DUPLICATE KEY UPDATE fecha_programada = VALUES(fecha_programada), hora = VALUES(hora)'
                    )->execute([
                        ':eid' => $expedienteId,
                        ':key' => $etapaKey,
                        ':fecha' => $ev['fecha'],
                        ':hora' => $ev['hora'],
                    ]);
                    log_historial(
                        $pdo, $expedienteId, $usuarioIA,
                        'etapa_' . $etapaKey, null,
                        "fecha agendada: {$ev['fecha']} (llenado automático desde Google Calendar: \"{$ev['titulo']}\")"
                    );
                    $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $expedienteId]);
                    $estado = 'aplicada';
                    $aplicadosSolos++;
                }
            }
        }

        $pdo->prepare(
            'INSERT INTO google_calendario_sugerencias
             (calendar_id, google_event_id, expediente_id, etapa_key, fecha, hora, titulo_evento, estado, google_updated, resuelto_en)
             VALUES (:cal, :evid, :eid, :etapa, :fecha, :hora, :titulo, :estado, :updated, :resuelto)'
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
            ':resuelto' => $estado === 'pendiente' ? null : date('Y-m-d H:i:s'),
        ]);

        if ($estado === 'pendiente') {
            push_enviar_a_usuario(
                $pdo, (int)$cal['abogado_id'], '📅 Conflicto de fecha en tu calendario',
                "\"{$ev['titulo']}\" ({$ev['fecha']}) -- esa etapa ya tenía otra fecha agendada, entra a Preguntas al abogado para decidir cuál vale.",
                '/sistema/?pregunta_calendario=1'
            );
        }
    }
}

echo "$eventosRevisados evento(s) revisado(s), $aplicadosSolos etapa(s) llenada(s) sola(s), "
    . "$pendientesConflicto conflicto(s) de fecha (requieren tap), $rechazadosPorOrden rechazada(s) por orden cronológico, "
    . "$sinMatch sin match claro, $fallidos fallido(s) (se reintentan después).\n";
