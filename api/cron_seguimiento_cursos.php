<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada hora -- NO se abre desde el navegador ni tiene
// sesión. Le manda a cada prospecto de interés en curso (ver
// registrar_interes_curso en ia_helpers.php) UN recordatorio, UNA sola
// vez, entre 3 y 20 horas de silencio -- nunca se repite (seguimiento_en
// se marca en cuanto se manda), para no hostigar a nadie. No hay forma
// automática de saber si de verdad compró (el pago pasa en una página
// aparte, fuera de este sistema), así que este recordatorio se manda
// igual aunque ya haya comprado -- es un costo aceptable frente al
// beneficio real de recuperar a los que no compraron.
//
// Bug real detectado en producción: antes esto disparaba hasta las 24h de
// silencio -- justo cuando WhatsApp ya NO deja mandar un mensaje libre (la
// ventana de 24h ya se cerró), así que el recordatorio fallaba en
// silencio casi siempre. Ahora dispara ANTES de que se cierre esa ventana
// (entre 3 y 20 horas), igual que ya hace cron_seguimiento_calculadora.php.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia cada hora.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/push_helpers.php';

if (!dentro_de_horario_atencion()) {
    echo "Fuera del horario de atención (" . date('G') . "h) — no se manda nada en esta corrida, para no escribirle a un cliente fuera del horario que el bot mismo respeta.\n";
    exit;
}

const CURSOS_INFO = [
    'Nuevo Procedimiento Laboral Mexicano' => ['precio' => 499, 'link' => 'https://thriving-madeleine-5fe918.netlify.app/'],
    'El Juicio de Amparo en Materia del Trabajo' => ['precio' => 499, 'link' => 'https://silver-bubblegum-8c4a03.netlify.app/'],
    'Actas Administrativas Laborales' => ['precio' => 299, 'link' => 'https://regal-lollipop-90d889.netlify.app/'],
];

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT p.id, p.telefono, p.nombre, p.curso_interes
     FROM prospectos p
     WHERE p.tipo = 'interes_curso'
       AND p.estatus = 'nuevo'
       AND p.seguimiento_en IS NULL
       AND p.pausado_bot = 0
       AND p.actualizado_en <= NOW() - INTERVAL 3 HOUR
       AND p.actualizado_en >= NOW() - INTERVAL 20 HOUR
       AND NOT EXISTS (
         SELECT 1 FROM whatsapp_conversaciones w
         WHERE w.telefono = p.telefono AND w.creado_en > NOW() - INTERVAL 2 HOUR
       )"
);
$stmt->execute();
$prospectos = $stmt->fetchAll();

$enviados = 0;
$sinVentana = 0;
foreach ($prospectos as $p) {
    // Mismo criterio que cron_seguimiento_calculadora.php: si su último
    // mensaje ya suena a un rechazo ("no gracias", "no me interesa"), no
    // insistir con el recordatorio -- se marca seguimiento_en igual, para
    // no volver a evaluarlo después.
    $chkUltimo = $pdo->prepare(
        "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 1"
    );
    $chkUltimo->execute([':t' => $p['telefono']]);
    $ultimoTexto = $chkUltimo->fetchColumn();
    if ($ultimoTexto !== false && whatsapp_texto_parece_declinar((string)$ultimoTexto)) {
        $pdo->prepare('UPDATE prospectos SET seguimiento_en = NOW() WHERE id = :id')->execute([':id' => $p['id']]);
        continue;
    }

    $info = CURSOS_INFO[$p['curso_interes']] ?? null;
    $saludo = $p['nombre'] ? "Hola {$p['nombre']}" : 'Hola';

    if ($info) {
        $texto = "{$saludo}, vi que te interesó el curso *{$p['curso_interes']}* (\${$info['precio']} MXN, acceso de por vida). Sigue disponible -- aquí tienes el link directo para inscribirte: {$info['link']}\n\nCualquier duda antes de inscribirte, aquí ando. 🙂";
    } else {
        // No debería pasar (curso_interes viene de un enum cerrado), pero
        // por si acaso el dato quedó vacío, se manda un mensaje genérico
        // en vez de tronar o mandar "undefined".
        $texto = "{$saludo}, vi que te interesaron nuestros cursos en línea. Si quieres que te pase la información de nuevo, aquí ando. 🙂";
    }

    // Bug real detectado: este cron por diseño solo dispara después de 24h
    // de silencio -- justo cuando WhatsApp ya NO deja mandar un mensaje
    // libre (la ventana de 24h ya se cerró), así que este recordatorio
    // estaba fallando en silencio casi siempre. No hay plantilla aprobada
    // por Meta para este caso, así que si está fuera de ventana se avisa a
    // un humano para que lo contacte él mismo en vez de perder el lead sin
    // que nadie se entere.
    if (!whatsapp_dentro_ventana_24h($pdo, $p['telefono'])) {
        $sinVentana++;
        push_notificar_prospecto(
            $pdo, null,
            'Seguimiento de curso NO se pudo mandar',
            "No se le pudo mandar el recordatorio automático del curso \"{$p['curso_interes']}\" a {$p['telefono']} -- no ha escrito en las últimas 24h y WhatsApp no deja mandarle un mensaje libre. Contáctalo tú directo si quieres darle seguimiento.",
            '/sistema/?abrir=' . urlencode($p['telefono'])
        );
    } elseif (whatsapp_enviar($p['telefono'], $texto)) {
        $ins = $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
        );
        $ins->execute([':t' => $p['telefono'], ':texto' => $texto]);
        $enviados++;
    }

    // Se marca aquí SIEMPRE (se pudo enviar o no) -- si whatsapp_enviar
    // falló porque ya pasaron más de 24h desde su último mensaje (WhatsApp
    // ya no deja mandar mensaje libre), reintentarlo en la siguiente
    // corrida del cron tampoco va a funcionar, así que no tiene caso
    // dejarlo pendiente para siempre.
    $upd = $pdo->prepare('UPDATE prospectos SET seguimiento_en = NOW() WHERE id = :id');
    $upd->execute([':id' => $p['id']]);
}

echo count($prospectos) . " prospecto(s) de interés en curso listo(s) para seguimiento, " . $enviados . " mensaje(s) enviado(s), $sinVentana sin ventana de 24h (se avisó a un humano).\n";
