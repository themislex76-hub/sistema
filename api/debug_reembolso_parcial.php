<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: reembolsa PARCIALMENTE un pago real ya aprobado,
// por el monto exacto que se pida -- directo con la API de reembolsos de
// Mercado Pago, para no depender de adivinar qué hace un botón del
// dashboard web cuando se trata de dinero real. Por seguridad, sin
// &confirmar=si solo MUESTRA qué haría, no ejecuta nada. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: primero busca el id real del pago -- si ya tienes el teléfono y
// hay una cita confirmada con ese pago, usa &telefono=52XXXXXXXXXX para
// que te diga el payment_id real sin tener que ir a buscarlo a mano.
// Uso 1 (buscar el payment_id): debug_reembolso_parcial.php?telefono=52XXXXXXXXXX
// Uso 2 (simular, sin ejecutar):  debug_reembolso_parcial.php?payment_id=NNNN&monto=200
// Uso 3 (ejecutar de verdad):     debug_reembolso_parcial.php?payment_id=NNNN&monto=200&confirmar=si
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$paymentId = trim((string)($_GET['payment_id'] ?? ''));
$monto = (float)($_GET['monto'] ?? 0);
$confirmar = trim((string)($_GET['confirmar'] ?? '')) === 'si';

$pdo = db();

if ($telefono !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, fecha, hora_inicio, monto, mp_payment_id FROM citas_asesoria
         WHERE telefono = :t AND estado = 'confirmada' AND mp_payment_id IS NOT NULL
         ORDER BY pagado_en DESC LIMIT 5"
    );
    $stmt->execute([':t' => $telefono]);
    $citas = $stmt->fetchAll();
    if (!$citas) {
        echo "No encontré ninguna cita confirmada con pago para {$telefono}.\n";
        exit;
    }
    echo "Citas pagadas más recientes de {$telefono}:\n\n";
    foreach ($citas as $c) {
        echo "  cita #{$c['id']} -- " . citas_formatear_fecha_hora($c['fecha'], substr($c['hora_inicio'], 0, 5))
            . " -- pagó \${$c['monto']} -- payment_id={$c['mp_payment_id']}\n";
    }
    echo "\nCopia el payment_id correcto y vuelve a entrar con:\n";
    echo "  debug_reembolso_parcial.php?payment_id=ESE_ID&monto=200\n";
    exit;
}

if ($paymentId === '' || $monto <= 0) {
    echo "Faltan datos. Uso:\n";
    echo "  debug_reembolso_parcial.php?telefono=52XXXXXXXXXX   (para buscar el payment_id)\n";
    echo "  debug_reembolso_parcial.php?payment_id=NNNN&monto=200   (simular)\n";
    echo "  debug_reembolso_parcial.php?payment_id=NNNN&monto=200&confirmar=si   (ejecutar de verdad)\n";
    exit;
}

if (!$confirmar) {
    echo "SIMULACIÓN -- todavía NO se ejecutó nada.\n\n";
    echo "Esto va a reembolsar \${$monto} MXN (PARCIAL, no el total) del pago {$paymentId}.\n\n";
    echo "Si el monto y el payment_id son correctos, vuelve a entrar agregando &confirmar=si al final de esta misma URL para ejecutarlo de verdad:\n\n";
    echo $_SERVER['REQUEST_URI'] . "&confirmar=si\n";
    exit;
}

echo "Ejecutando reembolso real de \${$monto} MXN del pago {$paymentId}...\n\n";
$resultado = mercadopago_reembolsar_parcial($paymentId, $monto);

if ($resultado === null) {
    echo "FALLÓ -- revisa mercadopago_debug.log para el detalle exacto del error.\n";
    exit;
}

echo "Listo. Respuesta real de Mercado Pago:\n\n";
print_r($resultado);
