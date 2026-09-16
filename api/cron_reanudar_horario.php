<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada 15-30 minutos, todos los días — NO se abre desde el
// navegador ni tiene sesión.
//
// Contesta automáticamente a quien escribió fuera de horario y solo
// recibió el aviso automático (ver WHATSAPP_MENSAJE_FUERA_HORARIO en
// whatsapp_procesar.php) — en cuanto abre el horario de atención, le
// contesta la pregunta real que se quedó pendiente, sin esperar a que el
// cliente vuelva a escribir. Fuera de horario no hace nada (se
// auto-limita con dentro_de_horario_atencion()).
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia cada 15-30 minutos, todos los días.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/whatsapp_procesar.php';

// Bug real detectado en producción (revisando TODAS las respuestas del
// 15-sep): decenas de números distintos recibieron dos "Buenos días..."
// casi idénticos, a 0-3 segundos uno del otro, todos entre las 8:15 y
// las 8:17 am -- justo cuando abre el horario de atención y hay un
// backlog grande acumulado de toda la noche. Este script llama a la IA
// completa UNA VEZ POR CADA número pendiente, en fila -- si el backlog es
// grande, la corrida puede tardar más que el intervalo del Cron Job
// (15-30 min), y la SIGUIENTE corrida arranca antes de que la anterior
// termine, encuentra los mismos números todavía "pendientes" (la
// anterior no ha alcanzado a contestarles) y los vuelve a contestar por
// su cuenta -- dos respuestas de IA distintas para el mismo mensaje,
// multiplicado por todo el backlog. Un candado de archivo (flock) evita
// que dos corridas de este script se traslapen: si ya hay una en curso,
// esta simplemente no hace nada y el siguiente Cron Job (15-30 min
// después) retoma lo que haya quedado pendiente.
$lockHandle = fopen(__DIR__ . '/cron_reanudar_horario.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Ya hay una corrida de este cron en curso -- se omite esta para no duplicar respuestas.\n";
    exit;
}

if (!dentro_de_horario_atencion()) {
    echo "Fuera de horario de atención — no se hace nada en esta corrida.\n";
    exit;
}

$pdo = db();

// Números donde el mensaje entrante más reciente es más nuevo que la
// última respuesta REAL (cualquier "saliente" que no sea el aviso
// automático) — o sea, tienen algo pendiente sin contestar de verdad. No
// se puede usar solo "el último mensaje es el aviso": si el cliente
// siguió escribiendo después del aviso (pasa seguido — sigue platicando
// aunque nadie le conteste), el último mensaje ya sería suyo, no el
// aviso, y se perdería su pregunta. En cuanto se le conteste de verdad,
// este número deja de aparecer aquí solo.
$stmt = $pdo->prepare(
    "SELECT telefono FROM whatsapp_conversaciones
     GROUP BY telefono
     HAVING MAX(CASE WHEN direccion = 'entrante' THEN id ELSE 0 END) >
            MAX(CASE WHEN direccion = 'saliente' AND texto <> :texto THEN id ELSE 0 END)"
);
$stmt->execute([':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
$telefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$contestados = 0;
$omitidos = 0;

foreach ($telefonos as $telefono) {
    $r = reanudar_conversacion_fuera_horario($pdo, (string)$telefono);
    if ($r['ok']) {
        $contestados++;
        echo "$telefono | CONTESTADO\n";
    } else {
        $omitidos++;
        echo "$telefono | omitido | " . $r['motivo'] . "\n";
    }
}

echo "\n" . count($telefonos) . " candidato(s), $contestados contestado(s), $omitidos omitido(s).\n";
