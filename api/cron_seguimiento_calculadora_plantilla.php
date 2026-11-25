<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job, 1 VEZ AL DÍA en
// horario de atención -- NO se abre desde el navegador ni tiene sesión.
//
// Tercer y ÚLTIMO recordatorio de venta (24 nov 2026, pedido explícito
// del usuario: "todo esto es para vender, revisa qué más podemos
// hacer"). Hasta hoy, cron_seguimiento_calculadora.php mandaba como
// máximo 2 recordatorios dentro de las primeras 24h de silencio -- una
// vez que se cierra la ventana de WhatsApp (24h sin que el cliente
// escriba), NADIE volvía a contactar a esa persona nunca, sin importar
// cuántos días pasaran -- una venta perdida real para alguien que sí
// tiene un caso real (ya calculó su liquidación con el bot) y pudo
// simplemente necesitar más tiempo para decidir.
//
// Este script manda UN tercer recordatorio, entre 1 y 7 días después del
// cálculo, usando una PLANTILLA aprobada de Meta (las plantillas sí
// pueden mandarse fuera de la ventana de 24h, a diferencia de un mensaje
// normal) -- mismos candados de siempre: nunca si ya lo tomó un humano,
// ya pagó/agendó, o ya declinó.
//
// *** Plantilla ya APROBADA en Meta el 25-nov-2026 con el nombre técnico
// "despues_de_24_h" (Marketing, Spanish (MEX), "Activa: calidad
// pendiente" -- ya se puede usar, "calidad pendiente" solo significa que
// Meta aún no acumula suficientes envíos para calificarla). ***
// Texto aprobado (24/25-nov-2026, tono natural/humano pedido explícito
// por el usuario -- categoría Marketing, no Utilidad -- es un
// reenganche de venta, no una confirmación de algo ya agendado; ver
// nota de costo en CLAUDE.md):
//   "Hola, ¿cómo estás? Quería darte seguimiento a tu caso laboral --
//   según lo que platicamos, tu estimado ronda los ${{1}}. Si en algún
//   momento quieres que el Lic. Rubén Buerhend lo revise contigo a
//   fondo por teléfono, nada más escríbeme y con gusto te paso los
//   horarios que tiene disponibles."
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia 1 vez al día (ej. a media mañana).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';

const PLANTILLA_SEGUIMIENTO_CALCULO = 'despues_de_24_h';

$pdo = db();

if (!dentro_de_horario_atencion()) {
    echo "Fuera del horario de atención (" . date('G') . "h) — no se manda nada en esta corrida, para no escribirle a un cliente fuera del horario que el bot mismo respeta.\n";
    exit;
}

// Candidatos: calcularon su liquidación hace entre 1 y 7 días, nunca
// recibieron este tercer recordatorio todavía. No se exige que ya hayan
// recibido los 2 recordatorios normales (api/cron_seguimiento_calculadora.php)
// -- si por algún motivo no les llegaron (ej. fuera de horario toda la
// ventana de 24h), este sigue siendo su único contacto de seguimiento.
$stmt = $pdo->query(
    "SELECT * FROM calculos_liquidacion
     WHERE tercer_seguimiento_en IS NULL
       AND creado_en < NOW() - INTERVAL 24 HOUR
       AND creado_en >= NOW() - INTERVAL 7 DAY"
);
$calculos = $stmt->fetchAll();

$enviados = 0;
$omitidos = 0;

foreach ($calculos as $c) {
    // Mismos candados que cron_seguimiento_calculadora.php: nunca
    // interferir si ya lo tomó un humano, ya pagó/agendó, ya declinó, o
    // el bot ya le dijo que no se le puede atender.
    $chk = $pdo->prepare('SELECT pausado_bot FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $c['telefono']]);
    $prospecto = $chk->fetch();
    if ($prospecto && (int)$prospecto['pausado_bot'] === 1) {
        $omitidos++;
        continue;
    }

    $chkCita = $pdo->prepare(
        "SELECT id FROM citas_asesoria
         WHERE telefono = :t AND (estado = 'confirmada' OR creado_en >= :calc)
         LIMIT 1"
    );
    $chkCita->execute([':t' => $c['telefono'], ':calc' => $c['creado_en']]);
    if ($chkCita->fetch()) {
        $omitidos++;
        continue;
    }

    $chkUltimo = $pdo->prepare(
        "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'entrante' ORDER BY id DESC LIMIT 1"
    );
    $chkUltimo->execute([':t' => $c['telefono']]);
    $ultimoTexto = (string)($chkUltimo->fetchColumn() ?: '');
    if ($ultimoTexto !== '' && whatsapp_texto_parece_declinar($ultimoTexto)) {
        $omitidos++;
        continue;
    }

    $chkBot = $pdo->prepare(
        "SELECT texto FROM whatsapp_conversaciones WHERE telefono = :t AND direccion = 'saliente' ORDER BY id DESC LIMIT 1"
    );
    $chkBot->execute([':t' => $c['telefono']]);
    $ultimoBot = (string)($chkBot->fetchColumn() ?: '');
    if (preg_match('/no\s+(maneja|atiende|podemos\s+(ofrecerte|atender|ayudarte)|puedo\s+ofrecerte)|apartado\s+b|buscar?\s+(a\s+)?un\s+abogado\s+especializado|no\s+es\s+(mi|nuestra)\s+especialidad|fuera\s+de\s+(nuestra|la)\s+materia/iu', $ultimoBot)) {
        $omitidos++;
        continue;
    }

    $montoFmt = number_format((float)$c['monto_total'], 0, '.', ',');
    $enviado = whatsapp_enviar_plantilla($c['telefono'], PLANTILLA_SEGUIMIENTO_CALCULO, [$montoFmt]);
    // Se marca como ya enviado (no reintentar) tanto si salió bien como
    // si falló por un rechazo de Meta (ej. plantilla no aprobada aún) --
    // reintentar lo mismo cada día sin arreglar la causa no sirve de
    // nada. Un error real de red/token sí va al log de siempre dentro de
    // whatsapp_enviar_plantilla().
    $pdo->prepare('UPDATE calculos_liquidacion SET tercer_seguimiento_en = NOW() WHERE id = :id')->execute([':id' => $c['id']]);
    if ($enviado) {
        $enviados++;
        $ins = $pdo->prepare("INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')");
        $ins->execute([':t' => $c['telefono'], ':texto' => "Hola, ¿cómo estás? Quería darte seguimiento a tu caso laboral -- según lo que platicamos, tu estimado ronda los \$$montoFmt. Si en algún momento quieres que el Lic. Rubén Buerhend lo revise contigo a fondo por teléfono, nada más escríbeme y con gusto te paso los horarios que tiene disponibles."]);
    } else {
        $omitidos++;
    }
}

echo count($calculos) . " candidato(s) revisado(s), $enviados plantilla(s) enviada(s), $omitidos omitido(s).\n";
