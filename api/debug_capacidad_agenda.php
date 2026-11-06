<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: calcula la capacidad semanal/mensual de la agenda
// de asesorías (horas abiertas en disponibilidad_asesorias por abogado
// activo) y la compara contra el ritmo real de ventas de los últimos N
// días, para saber si al ritmo actual la agenda se satura. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_capacidad_agenda.php?dias=14
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = max(1, (int)($_GET['dias'] ?? 14));
$pdo = db();

echo "=== Disponibilidad configurada (abogados activos) ===\n";
$stmt = $pdo->query(
    "SELECT u.id, u.nombre, d.dia_semana, d.hora_inicio, d.hora_fin
     FROM disponibilidad_asesorias d
     JOIN usuarios u ON u.id = d.usuario_id
     WHERE u.activo = 1
     ORDER BY u.nombre, d.dia_semana, d.hora_inicio"
);
$dias_es = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
$filas = $stmt->fetchAll();
if (!$filas) {
    echo "(sin disponibilidad configurada -- la agenda no ofrecería ningún horario)\n";
    exit;
}

$horasSemanaTotal = 0.0;
$porAbogado = [];
foreach ($filas as $f) {
    $ini = strtotime($f['hora_inicio']);
    $fin = strtotime($f['hora_fin']);
    $horasBloque = max(0, ($fin - $ini) / 3600);
    $horasSlots = floor($horasBloque); // slots de 1 hora, igual que citas_calcular_horarios_disponibles
    $horasSemanaTotal += $horasSlots;
    $porAbogado[$f['nombre']] = ($porAbogado[$f['nombre']] ?? 0) + $horasSlots;
    echo "  {$f['nombre']} -- {$dias_es[(int)$f['dia_semana']]} {$f['hora_inicio']}-{$f['hora_fin']} ({$horasSlots} slots de 1h)\n";
}

echo "\n=== Capacidad total ===\n";
foreach ($porAbogado as $nombre => $h) {
    echo "  {$nombre}: {$h} citas/semana posibles\n";
}
$capacidadMes = $horasSemanaTotal * 4.345; // semanas promedio por mes
echo "  TOTAL: {$horasSemanaTotal} citas/semana  ->  ~" . round($capacidadMes, 1) . " citas/mes de capacidad máxima\n";

echo "\n=== Ritmo real de ventas (últimos {$dias} días, por fecha de pago) ===\n";
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n FROM citas_asesoria
     WHERE pagado_en IS NOT NULL AND pagado_en >= :desde"
);
$stmt->execute([':desde' => date('Y-m-d H:i:s', strtotime("-{$dias} days"))]);
$ventas = (int)$stmt->fetchColumn();
$ritmoDia = $dias > 0 ? $ventas / $dias : 0;
$proyeccionMes = $ritmoDia * 30;
echo "  {$ventas} asesorías vendidas en {$dias} días -> " . round($ritmoDia, 2) . "/día -> proyección ~" . round($proyeccionMes, 1) . "/mes\n";

echo "\n=== Comparación ===\n";
if ($capacidadMes <= 0) {
    echo "  No se puede comparar (capacidad mensual calculada en 0).\n";
} else {
    $pctUso = ($proyeccionMes / $capacidadMes) * 100;
    echo "  Uso proyectado de la agenda: " . round($pctUso, 1) . "% de la capacidad máxima mensual\n";
    if ($pctUso >= 90) {
        echo "  -> ALERTA: a este ritmo la agenda se satura casi por completo o se excede.\n";
    } elseif ($pctUso >= 70) {
        echo "  -> OJO: la agenda se está acercando a su límite, vale la pena vigilarlo.\n";
    } else {
        echo "  -> Hay margen todavía.\n";
    }
}
