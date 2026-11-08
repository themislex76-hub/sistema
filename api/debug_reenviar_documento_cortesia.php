<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

// Herramienta temporal: manda el documento membretado ($49) GRATIS, como
// cortesía, reusando el cálculo que ya se guardó cuando se le ofreció
// (sin que el cliente lo haya pagado aparte) -- para el caso de alguien
// que ya pagó la asesoría de $499 y el abogado decide darle el documento
// sin cobrárselo también. Busca la compra MÁS RECIENTE de ese teléfono en
// compras_documento_calculo, sin importar su estado (normalmente
// 'pendiente', porque nunca lo pagó). Sin &confirmar=si es solo vista
// previa (muestra qué cálculo se mandaría, no manda nada). Con
// &confirmar=si lo manda de verdad y marca esa compra como 'confirmada'
// con mp_payment_id = 'CORTESIA_ASESORIA_PAGADA', para no dejarla como
// pendiente para siempre ni que se le vuelva a ofrecer cobrado. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_reenviar_documento_cortesia.php?telefono=52XXXXXXXXXX
//        (vista previa)
//      debug_reenviar_documento_cortesia.php?telefono=52XXXXXXXXXX&confirmar=si
//        (lo manda de verdad)
require_admin();

$telefono = trim((string)($_GET['telefono'] ?? ''));
$confirmar = ($_GET['confirmar'] ?? '') === 'si';

header('Content-Type: text/plain; charset=utf-8');

if ($telefono === '') {
    echo "Falta el teléfono. Uso: debug_reenviar_documento_cortesia.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT * FROM compras_documento_calculo WHERE telefono = :t ORDER BY creado_en DESC LIMIT 1"
);
$stmt->execute([':t' => $telefono]);
$compra = $stmt->fetch();

if (!$compra) {
    echo "No hay ningún cálculo de documento guardado para {$telefono} -- no se le ofreció nunca el documento, así que no hay nada que reenviar. Usa debug_enviar_pdf_calculo.php si quieres generarle uno nuevo desde cero.\n";
    exit;
}

if ($compra['estado'] === 'confirmada') {
    echo "Ojo: esta compra (#{$compra['id']}) ya está marcada como 'confirmada' (pagada el {$compra['pagado_en']}) -- probablemente ya se le mandó antes. Si de todos modos quieres reenviarlo, usa debug_enviar_pdf_calculo.php con los mismos datos.\n";
    exit;
}

$calc = json_decode((string)$compra['calculo_json'], true);
if (!is_array($calc)) {
    echo "El cálculo guardado (compra #{$compra['id']}) está corrupto o vacío -- no se puede reenviar así, hay que generarlo de nuevo con debug_enviar_pdf_calculo.php.\n";
    exit;
}

echo "Compra #{$compra['id']} encontrada -- estado actual: {$compra['estado']}, creada el {$compra['creado_en']}.\n";
echo "Nombre guardado: " . ($compra['nombre_cliente'] ?: '(sin nombre)') . "\n";
echo "Salario diario guardado: \${$compra['salario_diario']}\n";
echo "Total del cálculo: \$" . ($calc['total_estimado'] ?? '?') . "\n\n";

if (!$confirmar) {
    echo "Vista previa -- no se mandó nada todavía. Para mandarlo de verdad como cortesía (sin cobrar los \$49), agrega &confirmar=si a esta misma URL.\n";
    exit;
}

$enviado = whatsapp_enviar_pdf_calculo(
    $telefono, $calc, (float)$compra['salario_diario'],
    (string)($compra['nombre_cliente'] ?? ''),
    'Como ya tomaste tu asesoría con nosotros, aquí tienes tu documento sin costo adicional.'
);

if ($enviado) {
    $upd = $pdo->prepare(
        "UPDATE compras_documento_calculo SET estado = 'confirmada', mp_payment_id = 'CORTESIA_ASESORIA_PAGADA', pagado_en = NOW() WHERE id = :id"
    );
    $upd->execute([':id' => $compra['id']]);
    echo "Documento enviado como cortesía a {$telefono}, y la compra #{$compra['id']} quedó marcada como 'confirmada' (sin cobro).\n";
} else {
    echo "NO se pudo enviar el PDF -- revisa whatsapp_send_debug.log en el servidor.\n";
}
