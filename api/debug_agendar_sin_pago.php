<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: agenda una asesoría SIN cobrar -- para cuando el
// abogado acuerda una llamada sin pago (cortesía, referido, caso especial,
// etc.) y necesita que quede registrada en el sistema como cualquier otra
// cita confirmada, sin pasar por Mercado Pago. Usa el mismo
// citas_crear_pendiente que el flujo normal (mismo candado contra choques
// de horario) y luego la confirma directo con monto=0, sin
// mp_payment_id real. Solo Administrador. Se puede borrar cuando ya no
// haga falta.
//
// Uso: debug_agendar_sin_pago.php?telefono=52XXXXXXXXXX&fecha=2026-10-07&hora=12:00&nombre=Juan%20Perez
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$fecha = trim((string)($_GET['fecha'] ?? ''));
$hora = trim((string)($_GET['hora'] ?? ''));
$nombre = trim((string)($_GET['nombre'] ?? '')) ?: null;

if ($telefono === '' || $fecha === '' || $hora === '') {
    echo "Faltan datos. Uso: debug_agendar_sin_pago.php?telefono=52XXXXXXXXXX&fecha=2026-10-07&hora=12:00&nombre=Juan%20Perez\n";
    exit;
}

$pdo = db();

$citaId = citas_crear_pendiente($pdo, $telefono, $fecha, $hora, $nombre);
if ($citaId === null) {
    echo "Ese horario ({$fecha} {$hora}) no está libre en este momento -- revisa si ya se ocupó o si el horario/fecha están bien escritos (fecha=YYYY-MM-DD, hora=HH:MM).\n";
    exit;
}

$upd = $pdo->prepare(
    "UPDATE citas_asesoria SET estado = 'confirmada', mp_payment_id = 'SIN_PAGO_MANUAL', pagado_en = NOW(), monto = 0
     WHERE id = :id"
);
$upd->execute([':id' => $citaId]);

echo "Cita #{$citaId} agendada SIN COBRO para " . citas_formatear_fecha_hora($fecha, $hora) . ".\n\n";
echo "Mensaje sugerido para el cliente:\n";
echo "Quedó agendada tu asesoría con el Lic. Rubén Buerhend para "
    . citas_formatear_fecha_hora($fecha, $hora) . ". Te va a llamar a este mismo número de WhatsApp a esa hora.\n";
