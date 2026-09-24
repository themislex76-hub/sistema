<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Herramienta temporal: manda una plantilla aprobada a un número dado,
// AHORA MISMO -- útil para diagnosticar problemas con una plantilla
// específica (ver el error real en whatsapp_send_debug.log) o para
// mandarle a un cliente real, fuera de la ventana de 24h, un aviso
// mientras el bot todavía no tiene esto integrado a un botón del panel
// (por ejemplo, avisar que se le está llamando y no contesta). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_probar_plantilla.php?telefono=5215512345678&plantilla=recordatorio_1_hora&p1=Juan&p2=12:00%20pm
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Falta el parámetro telefono. Uso: debug_probar_plantilla.php?telefono=5215512345678&plantilla=NOMBRE&p1=...&p2=...\n";
    exit;
}

$plantilla = trim((string)($_GET['plantilla'] ?? 'recordatorio_1_hora'));
$parametros = [];
for ($i = 1; $i <= 5; $i++) {
    if (isset($_GET["p{$i}"]) && trim((string)$_GET["p{$i}"]) !== '') {
        $parametros[] = trim((string)$_GET["p{$i}"]);
    }
}
if (!$parametros) {
    $parametros = ['Prueba', '12:00 pm'];
}

echo "Mandando {$plantilla} a {$telefono} con parámetros: " . implode(' | ', $parametros) . "\n";
$ok = whatsapp_enviar_plantilla($telefono, $plantilla, $parametros);
echo $ok ? "OK -- la API aceptó el envío.\n" : "FALLÓ -- revisa la última línea de whatsapp_send_debug.log para el detalle exacto.\n";
