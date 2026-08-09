<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada 30-60 minutos — NO se abre desde el navegador ni
// tiene sesión. Seguimiento proactivo: alguien que ya calculó su
// liquidación con el bot (dato real de que tiene un caso) pero se quedó
// callado sin agendar la asesoría — se le manda como máximo 2 recordatorios
// (uno a las ~3h de silencio, otro a las ~20h, antes de que se cierre la
// ventana de 24h de WhatsApp), en horario razonable, y nunca si ya lo tomó
// un humano o ya pagó.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia cada 30-60 minutos, todos los días.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';

$pdo = db();

$horaActual = (int)date('G');
if ($horaActual < 8 || $horaActual > 21) {
    echo "Fuera de horario razonable (son las {$horaActual}h) — no se manda nada en esta corrida.\n";
    exit;
}

// Solo el cálculo más reciente por teléfono, de las últimas 24h.
$stmt = $pdo->query(
    "SELECT cl.* FROM calculos_liquidacion cl
     INNER JOIN (
         SELECT telefono, MAX(id) AS ultimo_id FROM calculos_liquidacion
         WHERE creado_en >= NOW() - INTERVAL 24 HOUR
         GROUP BY telefono
     ) t ON t.ultimo_id = cl.id"
);
$calculos = $stmt->fetchAll();

$enviados1 = 0;
$enviados2 = 0;
$omitidos = 0;

foreach ($calculos as $c) {
    // Ya lo tomó un humano (prospecto pausado) — no interferir.
    $chk = $pdo->prepare('SELECT pausado_bot FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $c['telefono']]);
    $prospecto = $chk->fetch();
    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        $omitidos++;
        continue;
    }

    // Ya pagó/agendó una asesoría — ya convirtió, no hace falta insistir.
    $chkCita = $pdo->prepare("SELECT id FROM citas_asesoria WHERE telefono = :t AND estado = 'confirmada' LIMIT 1");
    $chkCita->execute([':t' => $c['telefono']]);
    if ($chkCita->fetch()) {
        $omitidos++;
        continue;
    }

    // Silencio medido desde el último mensaje del CLIENTE (no desde nuestros
    // propios seguimientos), para no reiniciar el conteo con nuestro propio mensaje.
    $chkUltimo = $pdo->prepare(
        "SELECT MAX(creado_en) AS ultimo FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante'"
    );
    $chkUltimo->execute([':t' => $c['telefono']]);
    $ultimoEntrante = $chkUltimo->fetch()['ultimo'] ?? null;
    if (!$ultimoEntrante) {
        $omitidos++;
        continue;
    }
    $minutosSilencio = (int)((time() - strtotime($ultimoEntrante)) / 60);

    $montoTxt = $c['monto_total'] ? '$' . number_format((float)$c['monto_total'], 0) : null;

    if ($c['primer_seguimiento_en'] === null && $minutosSilencio >= 180 && $minutosSilencio < 1200) {
        $mensaje = $montoTxt
            ? "¡Hola! No quiero que se te vaya a pasar — según lo que me contaste, podrías recuperar cerca de {$montoTxt}. ¿Quieres que te agende la asesoría telefónica con el abogado para ver cómo proceder con tu caso? Es sin compromiso."
            : "¡Hola! No quiero que se te vaya a pasar tu caso — ¿te gustaría que te agende la asesoría telefónica con el abogado para revisarlo a fondo? Es sin compromiso.";
        if (whatsapp_enviar($c['telefono'], $mensaje)) {
            $enviados1++;
            $pdo->prepare('UPDATE calculos_liquidacion SET primer_seguimiento_en = NOW() WHERE id = :id')->execute([':id' => $c['id']]);
            $ins = $pdo->prepare("INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')");
            $ins->execute([':t' => $c['telefono'], ':texto' => $mensaje]);
        }
    } elseif ($c['segundo_seguimiento_en'] === null && $minutosSilencio >= 1200 && $minutosSilencio < 1440) {
        $mensaje = $montoTxt
            ? "Última oportunidad de platicarlo hoy: sigues a tiempo de que el abogado revise tu caso y cómo recuperar los {$montoTxt} que calculamos — ¿lo agendamos?"
            : "Última oportunidad de platicarlo hoy: ¿agendamos tu asesoría con el abogado para revisar tu caso a fondo?";
        if (whatsapp_enviar($c['telefono'], $mensaje)) {
            $enviados2++;
            $pdo->prepare('UPDATE calculos_liquidacion SET segundo_seguimiento_en = NOW() WHERE id = :id')->execute([':id' => $c['id']]);
            $ins = $pdo->prepare("INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')");
            $ins->execute([':t' => $c['telefono'], ':texto' => $mensaje]);
        }
    } else {
        $omitidos++;
    }
}

echo count($calculos) . " candidato(s) revisado(s), $enviados1 primer(os) seguimiento(s), $enviados2 segundo(s) seguimiento(s), $omitidos omitido(s).\n";
