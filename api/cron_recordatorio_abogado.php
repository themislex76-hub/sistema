<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) una vez al día, temprano en la mañana -- NO se abre desde
// el navegador ni tiene sesión. Le avisa a CADA abogado, por notificación
// push, lo que tiene agendado para MAÑANA (audiencia, pago de convenio,
// vencimiento de prescripción/amparo, pendiente) -- a diferencia de los
// demás cron_recordatorio_*.php, que le avisan al cliente o al prospecto,
// este es el único que le avisa al despacho mismo.
//
// Lee de agenda_avisos_cache, que el navegador llena solo (ver
// api/agenda_sync_avisos.php) cada vez que un abogado o el administrador
// abre el sistema -- así este cron nunca tiene que recalcular en PHP
// ningún plazo legal (días hábiles, suspensión por conciliación, etc.),
// evitando el riesgo de que una segunda implementación se desalinee de la
// del navegador en algo tan sensible como la prescripción.
//
// AGREGADO (9 nov 2026): "atraso" (asunto estancado) y "accion_ia"
// (próxima acción sugerida por el informe ejecutivo de IA) se tratan
// distinto al resto -- su fecha es cuándo YA DEBIÓ pasar algo, no una
// cita futura, así que en vez de exigir "fecha = mañana" se avisan TODOS
// LOS DÍAS mientras sigan sin resolverse (fecha <= hoy). Si un abogado
// lleva varios días ignorando uno de estos, también se le avisa al
// Administrador -- ver $UMBRAL_ESCALAR_DIAS más abajo. Antes de este
// cambio estos dos tipos se calculaban bien pero NUNCA se empujaban por
// push (bug real confirmado: 40 asuntos atorados sin avisar a nadie,
// porque "fecha = mañana" nunca coincide con una fecha que ya quedó en
// el pasado).
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez al día (por ejemplo 7:00 am).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_helpers.php';

const AGENDA_TIPO_LABEL = [
    'audiencia' => 'Audiencia',
    'pago' => 'Pago de convenio',
    'prescripcion' => 'Vence prescripción',
    'amparo' => 'Vence plazo de amparo',
    'pendiente' => 'Pendiente',
    'atraso' => 'Asunto atorado',
    'accion_ia' => 'Sugerido por IA',
];
const TIPOS_ABIERTOS = ['atraso', 'accion_ia'];
// Si un abogado lleva esto o más días ignorando un "atraso"/"accion_ia",
// también se le avisa al Administrador -- un asunto estancado no debe
// quedarse sin que nadie más lo note solo porque el abogado no entró al
// sistema.
const UMBRAL_ESCALAR_DIAS = 7;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT c.*, e.actor, e.demandado, e.abogado_id
     FROM agenda_avisos_cache c
     JOIN expedientes e ON e.id = c.expediente_id
     WHERE e.abogado_id IS NOT NULL
       AND (
         (c.tipo NOT IN ('atraso', 'accion_ia') AND c.fecha = CURDATE() + INTERVAL 1 DAY)
         OR (c.tipo IN ('atraso', 'accion_ia') AND c.fecha <= CURDATE())
       )
     ORDER BY e.abogado_id, c.hora IS NULL, c.hora"
);
$stmt->execute();
$filas = $stmt->fetchAll();

$porAbogado = [];
$paraEscalar = [];
foreach ($filas as $f) {
    $porAbogado[(int)$f['abogado_id']][] = $f;
    if (in_array($f['tipo'], TIPOS_ABIERTOS, true) && (int)$f['dias_atraso'] >= UMBRAL_ESCALAR_DIAS) {
        $paraEscalar[] = $f;
    }
}

$avisados = 0;
foreach ($porAbogado as $abogadoId => $items) {
    // No mandar el mismo aviso diario dos veces si el cron se llega a
    // correr más de una vez (o se reintenta a mano tras una falla).
    $ya = $pdo->prepare('SELECT 1 FROM agenda_avisos_enviados WHERE abogado_id = :aid AND fecha = CURDATE()');
    $ya->execute([':aid' => $abogadoId]);
    if ($ya->fetch()) continue;

    $lineas = array_map(function ($f) {
        $tipo = AGENDA_TIPO_LABEL[$f['tipo']] ?? $f['tipo'];
        $hora = $f['hora'] ? ' ' . substr($f['hora'], 0, 5) : '';
        $caso = trim(($f['actor'] ?? '') . ' vs ' . ($f['demandado'] ?? ''), ' vs');
        $sufijoAtraso = in_array($f['tipo'], TIPOS_ABIERTOS, true) && (int)$f['dias_atraso'] > 0
            ? " ({$f['dias_atraso']} día(s) sin resolverse)" : '';
        return "• {$tipo}{$hora} — {$caso}: {$f['label']}{$sufijoAtraso}";
    }, $items);

    $titulo = count($items) === 1 ? '📅 Tienes 1 pendiente' : '📅 Tienes ' . count($items) . ' pendientes';
    // Confirmado real (10 nov 2026): el protocolo de push tiene un límite
    // de tamaño de payload -- con muchos pendientes juntos se rebasaba y
    // el mensaje nunca llegaba, sin error visible para nadie.
    $cuerpo = push_armar_cuerpo($lineas);

    push_enviar_a_usuario($pdo, $abogadoId, $titulo, $cuerpo, '/sistema/');

    $ins = $pdo->prepare('INSERT INTO agenda_avisos_enviados (abogado_id, fecha) VALUES (:aid, CURDATE())');
    $ins->execute([':aid' => $abogadoId]);
    $avisados++;
}

// Escalación al Administrador -- tabla de dedupe APARTE de
// agenda_avisos_enviados a propósito: si el Administrador también lleva
// sus propios asuntos, ya recibió su push de arriba (misma persona, dos
// roles) y no debe bloquear este segundo aviso, distinto en contenido.
$escalados = 0;
if ($paraEscalar) {
    $admins = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN);
    $lineasEscalar = array_map(function ($f) {
        $tipo = AGENDA_TIPO_LABEL[$f['tipo']] ?? $f['tipo'];
        $caso = trim(($f['actor'] ?? '') . ' vs ' . ($f['demandado'] ?? ''), ' vs');
        return "• {$caso} ({$tipo}, {$f['dias_atraso']} día(s)): {$f['label']}";
    }, $paraEscalar);
    $tituloEscalar = count($paraEscalar) === 1
        ? '⚠️ 1 asunto lleva días sin resolverse'
        : '⚠️ ' . count($paraEscalar) . ' asuntos llevan días sin resolverse';
    // Confirmado real (10 nov 2026): con muchos asuntos escalados juntos
    // (59 en la primera corrida real) esto rebasaba el límite de tamaño
    // del protocolo de push y el mensaje nunca llegaba, sin error visible.
    $cuerpoEscalar = push_armar_cuerpo($lineasEscalar);

    foreach ($admins as $adminId) {
        $ya = $pdo->prepare('SELECT 1 FROM agenda_escalaciones_enviadas WHERE admin_id = :aid AND fecha = CURDATE()');
        $ya->execute([':aid' => $adminId]);
        if ($ya->fetch()) continue;

        push_enviar_a_usuario($pdo, (int)$adminId, $tituloEscalar, $cuerpoEscalar, '/sistema/');

        $ins = $pdo->prepare('INSERT INTO agenda_escalaciones_enviadas (admin_id, fecha) VALUES (:aid, CURDATE())');
        $ins->execute([':aid' => $adminId]);
        $escalados++;
    }
}

echo count($filas) . " pendiente(s), " . $avisados . " abogado(s) avisado(s), "
    . count($paraEscalar) . " asunto(s) escalado(s) a " . $escalados . " administrador(es).\n";
