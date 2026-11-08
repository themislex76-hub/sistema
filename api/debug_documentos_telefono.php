<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra TODAS las compras del documento
// membretado ($49) guardadas para un teléfono específico, en cualquier
// estado (confirmada, pendiente, cancelada) -- para diagnosticar casos
// donde un cliente dice que pagó (a veces más de una vez, con tarjetas
// distintas) pero el documento no le llegó. Mismo patrón que
// debug_citas_telefono.php, pero para compras_documento_calculo. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_documentos_telefono.php?telefono=52XXXXXXXXXX
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Uso: debug_documentos_telefono.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, telefono, nombre_cliente, salario_diario, monto, estado,
            mp_preference_id, mp_payment_id, link_pago, creado_en, pagado_en
     FROM compras_documento_calculo WHERE telefono = :t ORDER BY creado_en DESC"
);
$stmt->execute([':t' => $telefono]);
$compras = $stmt->fetchAll();

if (!$compras) {
    echo "No hay NINGUNA compra de documento guardada (en ningún estado) para {$telefono}.\n";
    echo "Si el cliente dice que pagó, el pago pudo haberse confirmado para OTRO teléfono -- revisa si dio un número distinto en algún momento de la conversación.\n";
    exit;
}

echo "Compras de documento guardadas para {$telefono} (" . count($compras) . "):\n\n";
foreach ($compras as $c) {
    foreach ($c as $k => $v) {
        if ($k === 'calculo_json') continue;
        echo str_pad($k, 18) . ": " . ($v ?? '(null)') . "\n";
    }
    echo str_repeat('-', 70) . "\n";
}

// Si hay mp_preference_id/mp_payment_id guardados aquí, también busca si
// esos mismos valores quedaron asociados a la compra de algún OTRO
// teléfono -- eso confirmaría un cruce real entre dos compras distintas.
$paymentIds = array_filter(array_column($compras, 'mp_payment_id'));
if ($paymentIds) {
    $ph = implode(',', array_fill(0, count($paymentIds), '?'));
    $stmt2 = $pdo->prepare(
        "SELECT id, telefono, estado, mp_payment_id, pagado_en FROM compras_documento_calculo
         WHERE mp_payment_id IN ($ph) AND telefono != ?"
    );
    $stmt2->execute([...$paymentIds, $telefono]);
    $cruzadas = $stmt2->fetchAll();
    if ($cruzadas) {
        echo "\n⚠️ OJO -- el mismo mp_payment_id aparece en OTRO teléfono también:\n";
        foreach ($cruzadas as $c) {
            echo "  compra #{$c['id']} -- telefono={$c['telefono']} -- estado={$c['estado']} -- payment_id={$c['mp_payment_id']} -- pagado_en={$c['pagado_en']}\n";
        }
    }
}
