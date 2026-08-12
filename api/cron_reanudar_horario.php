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

if (!dentro_de_horario_atencion()) {
    echo "Fuera de horario de atención — no se hace nada en esta corrida.\n";
    exit;
}

$pdo = db();

// Solo números cuyo ÚLTIMO mensaje registrado es el aviso automático de
// fuera de horario — o sea, preguntaron algo y se quedaron sin respuesta
// real. En cuanto se les conteste, este número deja de aparecer aquí solo
// (el nuevo último mensaje ya no es el aviso).
$stmt = $pdo->prepare(
    "SELECT wc.telefono FROM whatsapp_conversaciones wc
     INNER JOIN (
         SELECT telefono, MAX(id) AS ultimo_id FROM whatsapp_conversaciones GROUP BY telefono
     ) t ON t.ultimo_id = wc.id
     WHERE wc.direccion = 'saliente' AND wc.texto = :texto"
);
$stmt->execute([':texto' => WHATSAPP_MENSAJE_FUERA_HORARIO]);
$telefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$contestados = 0;
$omitidos = 0;

foreach ($telefonos as $telefono) {
    $r = reanudar_conversacion_fuera_horario($pdo, (string)$telefono);
    if ($r['ok']) {
        $contestados++;
    } else {
        $omitidos++;
    }
}

echo count($telefonos) . " candidato(s), $contestados contestado(s), $omitidos omitido(s).\n";
