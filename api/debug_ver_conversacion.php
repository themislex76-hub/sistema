<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: para un teléfono dado, muestra (1) los últimos
// mensajes entrantes/salientes en whatsapp_conversaciones (para saber
// cuándo fue la última vez que ese número le escribió al bot — clave
// para la teoría de la ventana de 24h de WhatsApp) y (2) sus citas de
// asesoría más recientes con el estado de recordatorio_enviado (para
// saber si el cron ya intentó procesarlas o no). Solo Administrador.
// Se puede borrar cuando ya no haga falta.
//
// Uso: debug_ver_conversacion.php?telefono=52XXXXXXXXXX
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Falta el teléfono. Usa: debug_ver_conversacion.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();

echo "=== Últimos mensajes en whatsapp_conversaciones para {$telefono} ===\n";
$stmt = $pdo->prepare(
    "SELECT direccion, texto, creado_en FROM whatsapp_conversaciones
     WHERE telefono = :t ORDER BY creado_en DESC LIMIT 10"
);
$stmt->execute([':t' => $telefono]);
$rows = $stmt->fetchAll();
if (!$rows) {
    echo "(sin mensajes registrados para este número)\n";
}
foreach ($rows as $r) {
    $texto = mb_strimwidth((string)$r['texto'], 0, 80, '...');
    echo "{$r['creado_en']} | {$r['direccion']} | {$texto}\n";
}

echo "\n=== Hora actual del servidor ===\n";
echo date('Y-m-d H:i:s') . "\n";

echo "\n=== Últimas citas_asesoria para {$telefono} ===\n";
$stmt2 = $pdo->prepare(
    "SELECT id, fecha, hora_inicio, estado, recordatorio_enviado, creado_en
     FROM citas_asesoria WHERE telefono = :t ORDER BY id DESC LIMIT 10"
);
$stmt2->execute([':t' => $telefono]);
$citas = $stmt2->fetchAll();
if (!$citas) {
    echo "(sin citas registradas para este número)\n";
}
foreach ($citas as $c) {
    echo "id={$c['id']} | fecha={$c['fecha']} {$c['hora_inicio']} | estado={$c['estado']} | recordatorio_enviado={$c['recordatorio_enviado']} | creado_en={$c['creado_en']}\n";
}
