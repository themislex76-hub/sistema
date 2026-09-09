<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada varias horas -- NO se abre desde el navegador ni
// tiene sesión. Le manda a cada prospecto de interés en curso (ver
// registrar_interes_curso en ia_helpers.php) UN recordatorio, UNA sola
// vez, si pasaron 24 horas sin que compre -- nunca se repite (seguimiento_en
// se marca en cuanto se manda), para no hostigar a nadie. No hay forma
// automática de saber si de verdad compró (el pago pasa en una página
// aparte, fuera de este sistema), así que este recordatorio se manda
// igual aunque ya haya comprado -- es un costo aceptable frente al
// beneficio real de recuperar a los que no compraron.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia sugerida: cada 6 horas.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';

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
       AND p.actualizado_en <= NOW() - INTERVAL 24 HOUR
       AND NOT EXISTS (
         SELECT 1 FROM whatsapp_conversaciones w
         WHERE w.telefono = p.telefono AND w.creado_en > NOW() - INTERVAL 12 HOUR
       )"
);
$stmt->execute();
$prospectos = $stmt->fetchAll();

$enviados = 0;
foreach ($prospectos as $p) {
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

    if (whatsapp_enviar($p['telefono'], $texto)) {
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

echo count($prospectos) . " prospecto(s) de interés en curso listo(s) para seguimiento, " . $enviados . " mensaje(s) enviado(s).\n";
