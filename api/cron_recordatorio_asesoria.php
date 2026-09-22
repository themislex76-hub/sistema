<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada 15-20 minutos — NO se abre desde el navegador ni
// tiene sesión. Busca citas de asesoría ya pagadas cuya llamada es en
// aproximadamente 1 hora y todavía no se les mandó el recordatorio, y se
// los manda.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia cada 15-20 minutos, todos los días.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/citas_helpers.php';
require_once __DIR__ . '/push_helpers.php';

$pdo = db();

// Ventana de 50 a 70 minutos antes de la hora de la cita — bastante ancha
// para no perderse ninguna aunque el cron no corra exactamente cada 15
// minutos, sin mandar el recordatorio ni demasiado antes ni ya casi a la
// hora. recordatorio_enviado evita que una misma cita, si cae dentro de la
// ventana en más de una corrida del cron, reciba el mensaje dos veces.
$stmt = $pdo->prepare(
    "SELECT * FROM citas_asesoria
     WHERE estado = 'confirmada' AND recordatorio_enviado = 0
       AND TIMESTAMP(fecha, hora_inicio) BETWEEN NOW() + INTERVAL 50 MINUTE AND NOW() + INTERVAL 70 MINUTE"
);
$stmt->execute();
$citas = $stmt->fetchAll();

$enviados = 0;
$fallidos = 0;
$sinVentana = 0;

foreach ($citas as $cita) {
    // WhatsApp no deja mandar un mensaje libre si el cliente no ha escrito
    // en las últimas 24h -- Meta "acepta" la petición al instante (por eso
    // whatsapp_enviar() de abajo devolvería true igual) pero la entrega
    // falla después, en silencio (error 131047), y el cliente nunca se
    // entera de que le van a llamar en 1 hora. Bug real detectado: este
    // cron no revisaba esto (a diferencia de un mensaje manual desde el
    // panel, que sí lo checa) -- con la política de "no hay devolución si
    // no se contesta la llamada", que el recordatorio se pierda es
    // particularmente grave. Fuera de la ventana, se manda la plantilla
    // aprobada por Meta "recordatorio_1" (esa SÍ llega fuera de las 24h,
    // es justo para eso). Solo si ni eso funciona se avisa a un humano
    // para que contacte al cliente por su cuenta antes de la llamada.
    $horaTxt = citas_formatear_hora(substr($cita['hora_inicio'], 0, 5));

    if (!whatsapp_dentro_ventana_24h($pdo, $cita['telefono'])) {
        $nombrePlantilla = trim((string)$cita['nombre_cliente']) !== '' ? $cita['nombre_cliente'] : 'estimado(a) cliente';
        $mensajePlantilla = "Hola {$nombrePlantilla}, tu asesoría con el Lic. Rubén Buerhend es en 1 hora, a las {$horaTxt} — te va a llamar del número 55 7991 3025 — guárdalo para que reconozcas la llamada. Ten a la mano cualquier documento o dato de tu caso que quieras comentarle.";

        if (whatsapp_enviar_plantilla($cita['telefono'], 'recordatorio_1_hora', [$nombrePlantilla, $horaTxt])) {
            $enviados++;
            $upd = $pdo->prepare('UPDATE citas_asesoria SET recordatorio_enviado = 1 WHERE id = :id');
            $upd->execute([':id' => $cita['id']]);

            $ins = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $ins->execute([':t' => $cita['telefono'], ':texto' => $mensajePlantilla]);
            continue;
        }

        $sinVentana++;
        $upd = $pdo->prepare('UPDATE citas_asesoria SET recordatorio_enviado = 1 WHERE id = :id');
        $upd->execute([':id' => $cita['id']]);
        push_notificar_prospecto(
            $pdo, null,
            'Recordatorio de asesoría NO se pudo mandar',
            "No se le pudo mandar el recordatorio automático a {$cita['telefono']} (su asesoría es hoy a las {$horaTxt}) -- no ha escrito en las últimas 24h y falló también el envío de la plantilla recordatorio_1. Contáctalo tú directo antes de la llamada.",
            '/sistema/?abrir=' . urlencode($cita['telefono'])
        );
        continue;
    }

    $saludo = $cita['nombre_cliente'] ? "¡Hola {$cita['nombre_cliente']}!" : '¡Hola!';
    $mensaje = "{$saludo} Tu asesoría con el abogado es en 1 hora, a las {$horaTxt} — te va a llamar a este mismo número de WhatsApp. Aprovecha para tener a la mano cualquier documento o dato de tu caso que quieras comentarle. ¡Nos vemos al rato!";

    if (whatsapp_enviar($cita['telefono'], $mensaje)) {
        $enviados++;
        $upd = $pdo->prepare('UPDATE citas_asesoria SET recordatorio_enviado = 1 WHERE id = :id');
        $upd->execute([':id' => $cita['id']]);

        $ins = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $ins->execute([':t' => $cita['telefono'], ':texto' => $mensaje]);
    } else {
        $fallidos++;
    }
}

echo count($citas) . " cita(s) encontrada(s), $enviados enviado(s), $fallidos fallido(s), $sinVentana sin ventana de 24h (se avisó a un humano).\n";
