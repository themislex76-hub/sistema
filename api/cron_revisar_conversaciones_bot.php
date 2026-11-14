<?php
declare(strict_types=1);

// Revisión automática diaria de las conversaciones del bot de WhatsApp
// (14 nov 2026, pieza 5 del plan de precisión pedido por el usuario: "el
// bot tiene que dar la asesoría completa, pero más precisa"). Una IA
// revisora lee cada conversación del día anterior en la que contestó el
// bot y la compara contra la LFT oficial (lft_articulos) y los criterios
// del despacho; lo que marque con algún error aparece en Equipo →
// "Conversaciones del bot a revisar". NUNCA le escribe nada al cliente ni
// corrige nada sola.
//
// Cron Job (cPanel): una vez al día, en la madrugada:
//   php /ruta/completa/a/api/cron_revisar_conversaciones_bot.php
// También se puede abrir la URL directo (mismo patrón que los demás
// crons). Parámetro opcional ?fecha=AAAA-MM-DD (o 1er argumento en
// línea de comandos) para revisar otro día; por defecto, ayer.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/push_helpers.php';

const REVISION_BOT_LOTE = 40;          // conversaciones por corrida (costo acotado)
const REVISION_BOT_CONTEXTO_PREVIO = 12; // mensajes de días anteriores como contexto

$fecha = $argv[1] ?? ($_GET['fecha'] ?? null);
if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha)) {
    $fecha = date('Y-m-d', strtotime('-1 day'));
}

$pdo = db();

// Solo conversaciones donde el CLIENTE escribió ese día y el BOT le
// contestó (excluye recordatorios/plantillas sueltas sin conversación).
$stmt = $pdo->prepare(
    "SELECT c.telefono
     FROM whatsapp_conversaciones c
     WHERE c.creado_en >= :ini AND c.creado_en < DATE_ADD(:ini2, INTERVAL 1 DAY)
     GROUP BY c.telefono
     HAVING SUM(c.direccion = 'entrante') > 0
        AND SUM(c.direccion = 'saliente' AND c.respondido_por = 'ia') > 0
     ORDER BY MAX(c.creado_en) DESC"
);
$stmt->execute([':ini' => $fecha, ':ini2' => $fecha]);
$telefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

$yaRevisado = $pdo->prepare('SELECT 1 FROM bot_revisiones WHERE telefono = :t AND fecha = :f');
$msgsDia = $pdo->prepare(
    "SELECT direccion, texto, respondido_por, creado_en FROM whatsapp_conversaciones
     WHERE telefono = :t AND creado_en >= :ini AND creado_en < DATE_ADD(:ini2, INTERVAL 1 DAY)
     ORDER BY creado_en, id"
);
$msgsPrevios = $pdo->prepare(
    'SELECT direccion, texto, respondido_por, creado_en FROM whatsapp_conversaciones
     WHERE telefono = :t AND creado_en < :ini
     ORDER BY creado_en DESC, id DESC LIMIT ' . REVISION_BOT_CONTEXTO_PREVIO
);
$guardar = $pdo->prepare(
    'INSERT INTO bot_revisiones (telefono, fecha, veredicto, gravedad, resumen, hallazgos)
     VALUES (:t, :f, :v, :g, :r, :h)'
);

$revisadas = 0; $conProblema = 0; $fallidas = 0; $saltadas = 0; $altas = [];
foreach ($telefonos as $tel) {
    if ($revisadas + $fallidas >= REVISION_BOT_LOTE) break;
    $yaRevisado->execute([':t' => $tel, ':f' => $fecha]);
    if ($yaRevisado->fetchColumn()) { $saltadas++; continue; }

    $msgsDia->execute([':t' => $tel, ':ini' => $fecha, ':ini2' => $fecha]);
    $mensajes = $msgsDia->fetchAll();
    $msgsPrevios->execute([':t' => $tel, ':ini' => $fecha]);
    $previos = array_reverse($msgsPrevios->fetchAll());

    $res = ia_revisar_conversacion_bot($pdo, $mensajes, $previos);
    if ($res === null) { $fallidas++; continue; }

    $guardar->execute([
        ':t' => $tel, ':f' => $fecha, ':v' => $res['veredicto'], ':g' => $res['gravedad'],
        ':r' => $res['resumen'] ?: null,
        ':h' => $res['hallazgos'] ? json_encode($res['hallazgos'], JSON_UNESCAPED_UNICODE) : null,
    ]);
    $revisadas++;
    if ($res['veredicto'] === 'revisar') {
        $conProblema++;
        if ($res['gravedad'] === 'alta') $altas[] = $tel . ': ' . ($res['resumen'] ?: 'error grave');
    }
}

// Aviso al Administrador solo si hay errores graves (los demás se ven en el panel).
if ($altas) {
    $admins = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        push_enviar_a_usuario(
            $pdo, (int)$adminId, '🤖 Bot: ' . count($altas) . ' conversación(es) con error grave',
            push_armar_cuerpo($altas), '/sistema/?revision_bot=1'
        );
    }
}

$msg = "Revisión del bot ($fecha): " . count($telefonos) . " conversación(es) del día, $revisadas revisada(s) con IA, "
    . "$conProblema con algo que revisar (" . count($altas) . " grave(s)), $saltadas ya revisada(s) antes, $fallidas fallida(s).";
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
echo $msg . "\n";
