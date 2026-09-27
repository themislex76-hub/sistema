<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: mueve una cita YA CONFIRMADA (pagada) a otro
// horario libre, SIN generar ningún cobro nuevo -- para cuando un
// cliente que ya pagó pide adelantar o atrasar su cita y el abogado
// acepta, en vez de hacerlo pagar de nuevo. El horario nuevo debe estar
// de verdad libre (no ocupado por otra cita); el horario viejo queda
// libre de inmediato para que alguien más lo pueda tomar. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_mover_cita.php?telefono=52XXXXXXXXXX&fecha_nueva=2026-09-28&hora_nueva=13:00
// (usa el teléfono para encontrar su cita confirmada más próxima -- si
// tiene más de una cita confirmada futura, dile el id exacto con &id=NNN)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
$fechaNueva = trim((string)($_GET['fecha_nueva'] ?? ''));
$horaNueva = trim((string)($_GET['hora_nueva'] ?? ''));
$idParam = trim((string)($_GET['id'] ?? ''));

if ($telefono === '' || $fechaNueva === '' || $horaNueva === '') {
    echo "Faltan datos. Uso: debug_mover_cita.php?telefono=52XXXXXXXXXX&fecha_nueva=2026-09-28&hora_nueva=13:00\n";
    exit;
}

$pdo = db();

if ($idParam !== '') {
    $stmt = $pdo->prepare("SELECT * FROM citas_asesoria WHERE id = :id AND telefono = :t AND estado = 'confirmada'");
    $stmt->execute([':id' => (int)$idParam, ':t' => $telefono]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM citas_asesoria WHERE telefono = :t AND estado = 'confirmada' AND fecha >= CURDATE()
         ORDER BY fecha, hora_inicio LIMIT 2"
    );
    $stmt->execute([':t' => $telefono]);
}
$citas = $stmt->fetchAll();

if (!$citas) {
    echo "No encontré ninguna cita confirmada futura para {$telefono}.\n";
    exit;
}
if (count($citas) > 1) {
    echo "Este número tiene más de una cita confirmada futura -- dime cuál mover agregando &id=NNN:\n";
    foreach ($citas as $c) {
        echo "  id={$c['id']} -- " . citas_formatear_fecha_hora($c['fecha'], substr($c['hora_inicio'], 0, 5)) . "\n";
    }
    exit;
}
$cita = $citas[0];

$horariosVigentes = citas_calcular_horarios_disponibles($pdo, 30, 200);
$esValido = false;
foreach ($horariosVigentes as $h) {
    if ($h['fecha'] === $fechaNueva && $h['hora_inicio'] === $horaNueva) {
        $esValido = true;
        break;
    }
}
if (!$esValido) {
    echo "Ese horario nuevo ({$fechaNueva} {$horaNueva}) no está libre en este momento -- revisa si ya se ocupó.\n";
    exit;
}

$horaFinNueva = date('H:i:00', strtotime($horaNueva) + 3600);
$viejoTexto = citas_formatear_fecha_hora($cita['fecha'], substr($cita['hora_inicio'], 0, 5));

$upd = $pdo->prepare(
    "UPDATE citas_asesoria SET fecha = :f, hora_inicio = :hi, hora_fin = :hf, recordatorio_enviado = 0
     WHERE id = :id"
);
$upd->execute([
    ':f' => $fechaNueva,
    ':hi' => $horaNueva . ':00',
    ':hf' => $horaFinNueva,
    ':id' => $cita['id'],
]);

echo "Listo -- cita #{$cita['id']} movida de {$viejoTexto} a " . citas_formatear_fecha_hora($fechaNueva, $horaNueva) . ".\n";
echo "El horario viejo ({$viejoTexto}) ya quedó libre para alguien más.\n";
echo "recordatorio_enviado se reinició a 0, así que el cron le va a mandar el aviso de \"1 hora antes\" para la NUEVA hora, normal.\n";
echo "Esto no le avisó nada al cliente por WhatsApp -- confírmaselo tú directo.\n";
