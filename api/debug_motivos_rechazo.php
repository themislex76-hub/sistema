<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra el motivo real por el que fallan los
// intentos de pago de asesoría (status_detail que manda Mercado Pago --
// ver mercadopago_webhook.php y la migración 050). Solo tiene datos desde
// que se agregó ese registro -- los intentos fallidos de ANTES de subir
// ese cambio no aparecen aquí porque esa información nunca se guardó.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_motivos_rechazo.php?dias=14 (default 14)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 14);
if ($dias <= 0) $dias = 14;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT telefono, estado, ultimo_estado_pago, ultimo_motivo_rechazo, intentos_fallidos, creado_en
     FROM citas_asesoria
     WHERE creado_en >= NOW() - INTERVAL :dias DAY AND intentos_fallidos > 0
     ORDER BY telefono, creado_en"
);
$stmt->execute([':dias' => $dias]);
$filas = $stmt->fetchAll();

if (!$filas) {
    echo "No hay intentos fallidos con motivo registrado en los últimos {$dias} día(s).\n";
    echo "Si esto corrió justo después de subir el cambio, es normal -- solo se registra\n";
    echo "el motivo de los intentos que fallen DE AQUÍ EN ADELANTE, no los de antes.\n";
    exit;
}

$porMotivo = [];
foreach ($filas as $f) {
    $motivo = $f['ultimo_motivo_rechazo'] !== '' ? $f['ultimo_motivo_rechazo'] : '(' . $f['ultimo_estado_pago'] . ', sin detalle)';
    $porMotivo[$motivo][] = $f['telefono'];
}
arsort($porMotivo);

echo "=== Motivos de rechazo de pago -- últimos {$dias} día(s) ===\n";
echo "(solo cuenta intentos fallidos desde que se activó este registro)\n\n";

foreach ($porMotivo as $motivo => $telefonos) {
    echo $motivo . ': ' . count($telefonos) . " intento(s)\n";
    foreach (array_unique($telefonos) as $tel) echo "  {$tel}\n";
    echo "\n";
}

echo "=== Números que fallaron 2+ veces (mismo o distinto motivo) ===\n";
$porTelefono = [];
foreach ($filas as $f) $porTelefono[$f['telefono']] = ($porTelefono[$f['telefono']] ?? 0) + (int)$f['intentos_fallidos'];
arsort($porTelefono);
foreach ($porTelefono as $tel => $n) {
    if ($n >= 2) echo "  {$tel}: {$n} intento(s) fallido(s)\n";
}
