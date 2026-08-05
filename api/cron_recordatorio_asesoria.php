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

foreach ($citas as $cita) {
    $saludo = $cita['nombre_cliente'] ? "¡Hola {$cita['nombre_cliente']}!" : '¡Hola!';
    $horaTxt = citas_formatear_hora(substr($cita['hora_inicio'], 0, 5));
    $mensaje = "{$saludo} Tu asesoría con el abogado es en 1 hora, a las {$horaTxt} — te va a llamar a este mismo número de WhatsApp. Aprovecha para tener a la mano cualquier documento o dato de tu caso que quieras comentarle. ¡Nos vemos al rato!";

    if (whatsapp_enviar($cita['telefono'], $mensaje)) {
        $upd = $pdo->prepare('UPDATE citas_asesoria SET recordatorio_enviado = 1 WHERE id = :id');
        $upd->execute([':id' => $cita['id']]);

        $ins = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $ins->execute([':t' => $cita['telefono'], ':texto' => $mensaje]);
    }
}

echo count($citas) . " recordatorio(s) procesado(s).\n";
