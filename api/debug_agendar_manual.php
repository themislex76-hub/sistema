<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';
require_once __DIR__ . '/mercadopago_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

// Herramienta temporal: hace a mano el mismo proceso que hace el bot
// cuando alguien elige un horario (citas_crear_pendiente + generar el
// link real de Mercado Pago) -- para cuando el abogado ya acordó una
// cita con alguien FUERA del flujo normal del bot (por ejemplo, fuera de
// horario laboral, o por teléfono directo) y necesita mandarle el link
// de pago real él mismo. El horario debe ser uno de verdad disponible
// (no ocupado) -- si no, regresa el motivo y no crea nada. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_agendar_manual.php?telefono=52XXXXXXXXXX&fecha=2026-09-28&hora=13:00&nombre=Juan%20Perez
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$fecha = trim((string)($_GET['fecha'] ?? ''));
$hora = trim((string)($_GET['hora'] ?? ''));
$nombre = trim((string)($_GET['nombre'] ?? '')) ?: null;

if ($telefono === '' || $fecha === '' || $hora === '') {
    echo "Faltan datos. Uso: debug_agendar_manual.php?telefono=52XXXXXXXXXX&fecha=2026-09-28&hora=13:00&nombre=Juan%20Perez\n";
    exit;
}

$pdo = db();

$horariosVigentes = citas_calcular_horarios_disponibles($pdo, 30, 200);
$esValido = false;
foreach ($horariosVigentes as $h) {
    if ($h['fecha'] === $fecha && $h['hora_inicio'] === $hora) {
        $esValido = true;
        break;
    }
}
if (!$esValido) {
    echo "Ese horario ({$fecha} {$hora}) no está libre en este momento -- revisa si ya se ocupó o si el horario/fecha están bien escritos (fecha=YYYY-MM-DD, hora=HH:MM).\n";
    exit;
}

$citaId = citas_crear_pendiente($pdo, $telefono, $fecha, $hora, $nombre);
if ($citaId === null) {
    echo "Justo se ocupó ese horario al momento de apartarlo -- intenta con otro.\n";
    exit;
}

$monto = mercadopago_monto_asesoria_a_respetar($pdo, $telefono);
$pref = mercadopago_crear_preferencia_asesoria($citaId, $telefono, MERCADOPAGO_WEBHOOK_URL, $monto);
if ($pref === null) {
    $upd = $pdo->prepare("UPDATE citas_asesoria SET estado = 'cancelada' WHERE id = :id");
    $upd->execute([':id' => $citaId]);
    echo "Se apartó el horario pero falló la generación del link de pago -- la cita se canceló para no dejar el horario atorado. Intenta de nuevo.\n";
    exit;
}

$upd = $pdo->prepare("UPDATE citas_asesoria SET mp_preference_id = :pref, link_pago = :link WHERE id = :id");
$upd->execute([':pref' => $pref['id'], ':link' => $pref['init_point'], ':id' => $citaId]);

echo "Cita #{$citaId} apartada para " . citas_formatear_fecha_hora($fecha, $hora) . " (\${$monto} MXN).\n";
echo "Tiene " . CITAS_HOLD_MINUTOS . " minutos para pagar antes de que el horario se libere de nuevo.\n\n";
echo "Mándale este link al cliente:\n{$pref['init_point']}\n";
