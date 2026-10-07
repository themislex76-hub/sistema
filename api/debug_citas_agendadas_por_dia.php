<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: cuántas citas de asesoría se CREARON cada día
// (no cuándo es la cita, sino cuándo se agendó/pagó) -- para ver si el
// ritmo de ventas de verdad bajó, en vez de confundirlo con la variación
// normal del volumen de mensajes de WhatsApp (que sube mucho en días de
// live y baja después, sin que eso signifique menos ventas). Separa
// confirmadas (pagadas) de expiradas/pendientes para no mezclar demanda
// real con gente que no completó el pago. Solo Administrador. Se puede
// borrar cuando ya no haga falta.
//
// Uso: debug_citas_agendadas_por_dia.php?dias=21 (default 21)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 21);
if ($dias <= 0) $dias = 21;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT DATE(creado_en) AS dia, estado, COUNT(*) AS n
     FROM citas_asesoria
     WHERE creado_en >= NOW() - INTERVAL :dias DAY
     GROUP BY dia, estado
     ORDER BY dia"
);
$stmt->execute([':dias' => $dias]);

$porDia = [];
foreach ($stmt->fetchAll() as $r) {
    $porDia[$r['dia']][$r['estado']] = (int)$r['n'];
}

$dow = ['dom','lun','mar','mié','jue','vie','sáb'];

echo "=== Citas de asesoría CREADAS por día -- últimos {$dias} día(s) ===\n";
echo "(agendadas ese día, no la fecha de la cita en sí; hoy puede verse bajo si el día no ha terminado)\n\n";
printf("%-12s %-4s | %-10s | %-10s | %-10s\n", "Fecha", "Día", "Confirmada", "Expirada", "Pendiente");
echo str_repeat('-', 60) . "\n";

for ($i = $dias - 1; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-{$i} day"));
    $d = $porDia[$fecha] ?? [];
    $diaSemana = $dow[(int)date('w', strtotime($fecha))];
    printf(
        "%-12s %-4s | %-10s | %-10s | %-10s\n",
        $fecha, $diaSemana,
        $d['confirmada'] ?? 0,
        $d['expirada'] ?? 0,
        $d['pendiente_pago'] ?? 0
    );
}
