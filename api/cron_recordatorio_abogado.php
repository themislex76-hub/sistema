<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) una vez al día, temprano en la mañana -- NO se abre desde
// el navegador ni tiene sesión. Le avisa a CADA abogado, por notificación
// push, lo que tiene agendado para MAÑANA (audiencia, pago de convenio,
// vencimiento de prescripción/amparo, pendiente o atraso) -- a diferencia
// de los demás cron_recordatorio_*.php, que le avisan al cliente o al
// prospecto, este es el único que le avisa al despacho mismo.
//
// Lee de agenda_avisos_cache, que el navegador llena solo (ver
// api/agenda_sync_avisos.php) cada vez que un abogado o el administrador
// abre el sistema -- así este cron nunca tiene que recalcular en PHP
// ningún plazo legal (días hábiles, suspensión por conciliación, etc.),
// evitando el riesgo de que una segunda implementación se desalinee de la
// del navegador en algo tan sensible como la prescripción.
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
];

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT c.*, e.actor, e.demandado, e.abogado_id
     FROM agenda_avisos_cache c
     JOIN expedientes e ON e.id = c.expediente_id
     WHERE c.fecha = CURDATE() + INTERVAL 1 DAY AND e.abogado_id IS NOT NULL
     ORDER BY e.abogado_id, c.hora IS NULL, c.hora"
);
$stmt->execute();
$filas = $stmt->fetchAll();

$porAbogado = [];
foreach ($filas as $f) {
    $porAbogado[(int)$f['abogado_id']][] = $f;
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
        return "• {$tipo}{$hora} — {$caso}: {$f['label']}";
    }, $items);

    $titulo = count($items) === 1 ? '📅 Mañana tienes 1 pendiente' : '📅 Mañana tienes ' . count($items) . ' pendientes';
    $cuerpo = implode("\n", $lineas);

    push_enviar_a_usuario($pdo, $abogadoId, $titulo, $cuerpo, '/sistema/');

    $ins = $pdo->prepare('INSERT INTO agenda_avisos_enviados (abogado_id, fecha) VALUES (:aid, CURDATE())');
    $ins->execute([':aid' => $abogadoId]);
    $avisados++;
}

echo count($filas) . " pendiente(s) para mañana, " . $avisados . " abogado(s) avisado(s).\n";
