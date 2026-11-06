<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

// Herramienta temporal: reenvía el documento membretado ($49) a un
// teléfono que YA LO PAGÓ de verdad, reutilizando exactamente el mismo
// mecanismo que usa la IA (ia_resultado_consultar_compra_documento_calculo)
// -- a diferencia de debug_reenviar_documento_cortesia.php, esto NO
// marca nada como cortesía ni toca el estado de pago real, solo reenvía
// el PDF si ya está confirmado. Pensado para cuando el envío automático
// falló (ej. por falta de api/vendor/) y hay que reenviarlo a mano una
// vez resuelto el problema. Solo Administrador. Se puede borrar cuando
// ya no haga falta.
//
// Uso: debug_reenviar_documento_pagado.php?telefono=52XXXXXXXXXX
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Uso: debug_reenviar_documento_pagado.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();
$resultado = ia_resultado_consultar_compra_documento_calculo($pdo, $telefono);
echo $resultado . "\n";
