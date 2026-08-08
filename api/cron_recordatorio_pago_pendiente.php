<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada 5-10 minutos — NO se abre desde el navegador ni
// tiene sesión. Muchos clientes piden el link de pago de la asesoría y
// nunca lo completan dentro de los 30 minutos que tienen (CITAS_HOLD_MINUTOS
// en citas_helpers.php) antes de que el horario se libere — este script les
// manda un recordatorio cuando les quedan ~10 minutos, para bajar esa tasa
// de abandono.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia cada 5-10 minutos, todos los días.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/citas_helpers.php';

$pdo = db();

// CITAS_HOLD_MINUTOS son 30 — se manda el aviso cuando ya pasaron 20
// minutos desde que se creó la cita pendiente (o sea, quedan ~10 minutos).
// El límite superior (35 min) es solo por seguridad, para no mandar un
// recordatorio de un horario que técnicamente ya expiró si el cron se
// atrasó un poco.
$minutosAviso = CITAS_HOLD_MINUTOS - 10;
$stmt = $pdo->prepare(
    "SELECT * FROM citas_asesoria
     WHERE estado = 'pendiente_pago' AND recordatorio_pago_enviado = 0 AND link_pago IS NOT NULL
       AND creado_en <= NOW() - INTERVAL :minutos MINUTE
       AND creado_en >= NOW() - INTERVAL " . (CITAS_HOLD_MINUTOS + 5) . " MINUTE"
);
$stmt->execute([':minutos' => $minutosAviso]);
$citas = $stmt->fetchAll();

$enviados = 0;
$fallidos = 0;
$minutosRestantes = 10;

foreach ($citas as $cita) {
    $mensaje = "¡No se te vaya a ir tu horario! Te quedan {$minutosRestantes} minutos para completar el pago de tu asesoría antes de que se libere. Aquí está tu link de nuevo:\n{$cita['link_pago']}\n\nSolo acepta tarjeta de crédito/débito o saldo de Mercado Pago.";

    if (whatsapp_enviar($cita['telefono'], $mensaje)) {
        $enviados++;
        $upd = $pdo->prepare('UPDATE citas_asesoria SET recordatorio_pago_enviado = 1 WHERE id = :id');
        $upd->execute([':id' => $cita['id']]);

        $ins = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $ins->execute([':t' => $cita['telefono'], ':texto' => $mensaje]);
    } else {
        $fallidos++;
    }
}

echo count($citas) . " cita(s) encontrada(s), $enviados enviado(s), $fallidos fallido(s).\n";
