<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra TODAS las citas de asesoría guardadas
// para un teléfono específico, en cualquier estado (confirmada,
// pendiente_pago, cancelada, expirada) -- para diagnosticar casos donde
// un cliente dice que su pago se confirmó pero la cita no aparece en la
// Agenda. Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_citas_telefono.php?telefono=52XXXXXXXXXX
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Uso: debug_citas_telefono.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, telefono, fecha, hora_inicio, hora_fin, estado, monto, nombre_cliente,
            mp_preference_id, mp_payment_id, creado_en, pagado_en
     FROM citas_asesoria WHERE telefono = :t ORDER BY creado_en DESC"
);
$stmt->execute([':t' => $telefono]);
$citas = $stmt->fetchAll();

if (!$citas) {
    echo "No hay NINGUNA cita guardada (en ningún estado) para {$telefono}.\n";
    echo "Si el cliente dice que pagó, el pago pudo haberse confirmado para OTRO teléfono -- revisa si dio un número distinto en algún momento de la conversación.\n";
    exit;
}

echo "Citas guardadas para {$telefono} (" . count($citas) . "):\n\n";
foreach ($citas as $c) {
    foreach ($c as $k => $v) {
        echo str_pad($k, 18) . ": " . ($v ?? '(null)') . "\n";
    }
    echo str_repeat('-', 70) . "\n";
}

// Si hay un mp_payment_id guardado aquí, también busca si ESE mismo
// payment_id quedó asociado a la cita de algún OTRO teléfono -- eso
// confirmaría un cruce real entre dos reservaciones distintas.
$paymentIds = array_filter(array_column($citas, 'mp_payment_id'));
if ($paymentIds) {
    $ph = implode(',', array_fill(0, count($paymentIds), '?'));
    $stmt2 = $pdo->prepare(
        "SELECT id, telefono, fecha, hora_inicio, estado, mp_payment_id FROM citas_asesoria
         WHERE mp_payment_id IN ($ph) AND telefono != ?"
    );
    $stmt2->execute([...$paymentIds, $telefono]);
    $cruzadas = $stmt2->fetchAll();
    if ($cruzadas) {
        echo "\n⚠️ OJO -- el mismo mp_payment_id aparece en OTRO teléfono también:\n";
        foreach ($cruzadas as $c) {
            echo "  cita #{$c['id']} -- telefono={$c['telefono']} -- " . $c['fecha'] . ' ' . substr($c['hora_inicio'],0,5) . " -- estado={$c['estado']} -- payment_id={$c['mp_payment_id']}\n";
        }
    }
}
