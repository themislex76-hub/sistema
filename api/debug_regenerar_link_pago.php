<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta temporal: genera un link de pago NUEVO para una cita que
// YA está apartada (pendiente_pago) pero cuyo link original no le
// funcionó al cliente (ej. Mercado Pago le dio un error genérico al
// intentar pagar) -- no crea una cita nueva ni toca el horario, solo
// reemplaza mp_preference_id/link_pago con una preferencia nueva. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_regenerar_link_pago.php?telefono=52XXXXXXXXXX
// (si el teléfono tiene más de una cita pendiente, usa &id=123 para
// apuntar a una en concreto -- ver debug_citas.php para encontrar el id)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$idParam = trim((string)($_GET['id'] ?? ''));

if ($telefono === '' && $idParam === '') {
    echo "Falta el teléfono o el id. Uso: debug_regenerar_link_pago.php?telefono=52XXXXXXXXXX\n";
    exit;
}

$pdo = db();

if ($idParam !== '') {
    $stmt = $pdo->prepare("SELECT * FROM citas_asesoria WHERE id = :id AND estado = 'pendiente_pago'");
    $stmt->execute([':id' => (int)$idParam]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM citas_asesoria WHERE telefono = :t AND estado = 'pendiente_pago' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':t' => $telefono]);
}
$cita = $stmt->fetch();

if (!$cita) {
    echo "No se encontró ninguna cita pendiente de pago con esos datos (puede que ya se haya pagado, cancelado, o que se le haya vencido el tiempo de espera).\n";
    exit;
}

$monto = mercadopago_monto_asesoria_a_respetar($pdo, $cita['telefono']);
$pref = mercadopago_crear_preferencia_asesoria((int)$cita['id'], $cita['telefono'], MERCADOPAGO_WEBHOOK_URL, $monto);
if ($pref === null) {
    echo "No se pudo generar el nuevo link -- revisa mercadopago_debug.log en el servidor.\n";
    exit;
}

$upd = $pdo->prepare("UPDATE citas_asesoria SET mp_preference_id = :pref, link_pago = :link WHERE id = :id");
$upd->execute([':pref' => $pref['id'], ':link' => $pref['init_point'], ':id' => $cita['id']]);

$horaTxt = substr((string)$cita['hora_inicio'], 0, 5);
echo "Cita #{$cita['id']} -- " . citas_formatear_fecha_hora((string)$cita['fecha'], $horaTxt) . " (\${$monto} MXN).\n\n";
echo "Nuevo link de pago -- mándaselo al cliente:\n{$pref['init_point']}\n";
