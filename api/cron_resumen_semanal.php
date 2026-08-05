<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) una vez por semana — NO se abre desde el navegador ni
// tiene sesión. Manda un resumen de los últimos 7 días por WhatsApp al
// número del despacho.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez por semana (por ejemplo lunes 8:00 am).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Número al que se manda el resumen — formato con código de país (52) y el
// "1" de celular que usa WhatsApp Business API para México, igual que
// cualquier otro teléfono guardado en el sistema.
const RESUMEN_SEMANAL_TELEFONO = '5215579913025';

$pdo = db();

$despidosNuevos = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM prospectos WHERE tipo = 'despido' AND creado_en >= NOW() - INTERVAL 7 DAY"
)->fetch()['n'];

$asesoriasVendidas = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en >= NOW() - INTERVAL 7 DAY"
)->fetch()['n'];

$facturado = (float)$pdo->query(
    "SELECT COALESCE(SUM(monto), 0) AS s FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en >= NOW() - INTERVAL 7 DAY"
)->fetch()['s'];

$conversacionesTotales = (int)$pdo->query(
    "SELECT COUNT(DISTINCT telefono) AS n FROM whatsapp_conversaciones WHERE creado_en >= NOW() - INTERVAL 7 DAY"
)->fetch()['n'];

$prospectosTotales = (int)$pdo->query(
    "SELECT COUNT(*) AS n FROM prospectos WHERE creado_en >= NOW() - INTERVAL 7 DAY"
)->fetch()['n'];

$tasaConversion = $conversacionesTotales > 0 ? round($prospectosTotales / $conversacionesTotales * 100, 1) : 0;

$mensaje = "📊 *Resumen de la semana (últimos 7 días)*\n\n"
    . "• {$despidosNuevos} lead(s) nuevo(s) de despido\n"
    . "• {$asesoriasVendidas} asesoría(s) vendida(s) — \$" . number_format($facturado, 0) . " MXN\n"
    . "• {$conversacionesTotales} conversación(es) de WhatsApp, {$tasaConversion}% calificó como prospecto\n\n"
    . "¡Buena semana!";

whatsapp_enviar(RESUMEN_SEMANAL_TELEFONO, $mensaje);

echo "Resumen semanal enviado.\n";
