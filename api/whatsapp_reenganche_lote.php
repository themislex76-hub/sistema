<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Herramienta para reenganchar en lote a números que escribieron y se
// quedaron SIN NINGUNA respuesta (ver debug_whatsapp_sin_responder.php --
// esto pasó real el 9-sep-2026 por la tabla numeros_bloqueados faltante,
// que tronaba el proceso antes de contestar nada). Manda un mensaje de
// disculpa genérico e invita a repetir su pregunta -- así el bot la
// retoma normal en la siguiente respuesta, en vez de intentar contestar
// aquí cada caso distinto con un mensaje fijo.
//
// Solo manda a los que SIGUEN dentro de la ventana de 24h de WhatsApp
// (desde su último mensaje) -- fuera de esa ventana, Meta exige una
// plantilla aprobada para que la empresa pueda escribir primero, así que
// esos se listan aparte para que el abogado los contacte él mismo
// (llamada, o plantilla si ya tiene una aprobada).
//
// GET = vista previa (no manda nada). POST con confirmar=1 = manda de
// verdad. Solo Administrador.
require_admin();

$pdo = db();
$horas = isset($_GET['horas']) ? max(1, (int)$_GET['horas']) : 48;

$stmt = $pdo->prepare(
    "SELECT telefono,
            MAX(CASE WHEN direccion = 'entrante' THEN creado_en END) AS ultimo_entrante,
            MAX(CASE WHEN direccion = 'saliente' THEN creado_en END) AS ultimo_saliente,
            TIMESTAMPDIFF(HOUR, MAX(CASE WHEN direccion = 'entrante' THEN creado_en END), NOW()) AS horas_desde_ultimo
     FROM whatsapp_conversaciones
     WHERE creado_en >= NOW() - INTERVAL :horas HOUR
     GROUP BY telefono
     HAVING ultimo_entrante IS NOT NULL
        AND (ultimo_saliente IS NULL OR ultimo_saliente < ultimo_entrante)
     ORDER BY ultimo_entrante DESC"
);
$stmt->bindValue(':horas', $horas, PDO::PARAM_INT);
$stmt->execute();
$candidatos = $stmt->fetchAll();

// Nunca reenganchar a alguien que el abogado bloqueó a propósito.
$bloqueados = $pdo->query("SELECT telefono FROM numeros_bloqueados")->fetchAll(PDO::FETCH_COLUMN);
$bloqueados = array_flip($bloqueados);

$dentroVentana = [];
$fueraVentana = [];
foreach ($candidatos as $c) {
    if (isset($bloqueados[$c['telefono']])) continue;
    if ((int)$c['horas_desde_ultimo'] < 24) {
        $dentroVentana[] = $c;
    } else {
        $fueraVentana[] = $c;
    }
}

$mensaje = "Hola, disculpa la tardanza -- tuvimos una falla técnica y tu mensaje no llegó a tiempo a nuestro sistema. Ya está resuelto. ¿Me puedes repetir en qué te puedo ayudar? Le damos seguimiento de inmediato.";

$confirmar = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:system-ui;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.5} .num{border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px} .fuera{background:#fdf3e7} .ok{background:#eefaf0} button{padding:10px 18px;font-size:15px;cursor:pointer}</style>';

if ($confirmar) {
    echo '<h2>Enviando...</h2>';
    $enviados = 0;
    $fallidos = [];
    foreach ($dentroVentana as $c) {
        $ok = whatsapp_enviar($c['telefono'], $mensaje);
        if ($ok) {
            $ins = $pdo->prepare(
                "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'ia')"
            );
            $ins->execute([':t' => $c['telefono'], ':texto' => $mensaje]);
            $enviados++;
        } else {
            $fallidos[] = $c['telefono'];
        }
    }
    echo "<p><strong>{$enviados}</strong> mensaje(s) mandado(s) bien.</p>";
    if ($fallidos) {
        echo '<p>Fallaron: ' . implode(', ', array_map('htmlspecialchars', $fallidos)) . '</p>';
    }
    echo '<p><a href="whatsapp_reenganche_lote.php">Volver a la vista previa</a></p>';
    exit;
}

echo '<h2>Reenganche en lote -- WhatsApps sin responder (últimas ' . $horas . 'h)</h2>';
echo '<p>Mensaje que se va a mandar a los que siguen dentro de la ventana de 24h:</p>';
echo '<p style="background:#f5f5f5;padding:12px;border-radius:8px;"><em>' . htmlspecialchars($mensaje) . '</em></p>';

echo '<h3>Se les manda ahora (' . count($dentroVentana) . '):</h3>';
if (!$dentroVentana) {
    echo '<p>Ninguno sigue dentro de la ventana de 24h.</p>';
} else {
    foreach ($dentroVentana as $c) {
        echo '<div class="num ok">' . htmlspecialchars($c['telefono']) . ' -- escribió ' . htmlspecialchars($c['ultimo_entrante'])
            . ' (' . (int)$c['horas_desde_ultimo'] . 'h)</div>';
    }
    echo '<form method="post"><input type="hidden" name="confirmar" value="1">'
        . '<button type="submit" onclick="return confirm(\'¿Mandar el mensaje a los ' . count($dentroVentana) . ' números de arriba?\')">Mandar mensaje a estos ' . count($dentroVentana) . '</button></form>';
}

echo '<h3>Fuera de la ventana de 24h -- contactar manual (' . count($fueraVentana) . '):</h3>';
if (!$fueraVentana) {
    echo '<p>Ninguno.</p>';
} else {
    foreach ($fueraVentana as $c) {
        echo '<div class="num fuera">' . htmlspecialchars($c['telefono']) . ' -- escribió ' . htmlspecialchars($c['ultimo_entrante'])
            . ' (hace ' . (int)$c['horas_desde_ultimo'] . 'h, ya no se les puede escribir sin plantilla)</div>';
    }
}
