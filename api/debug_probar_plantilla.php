<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Herramienta temporal: manda la plantilla recordatorio_1 a un número dado,
// AHORA MISMO, para diagnosticar el error 132001 ("template name does not
// exist in es_MX") que salió en el cron de las 11:00 am del 22-sep sin
// tener que esperar a que caiga una cita real en la ventana de 50-70
// minutos. Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_probar_plantilla.php?telefono=5215512345678
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Falta el parámetro telefono. Uso: debug_probar_plantilla.php?telefono=5215512345678\n";
    exit;
}

echo "Mandando recordatorio_1 a {$telefono}...\n";
$ok = whatsapp_enviar_plantilla($telefono, 'recordatorio_1', ['Prueba', '12:00 pm']);
echo $ok ? "OK -- la API aceptó el envío.\n" : "FALLÓ -- revisa la última línea de whatsapp_send_debug.log para el detalle exacto.\n";
