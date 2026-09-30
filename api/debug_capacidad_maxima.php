<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta temporal: calcula el ingreso máximo teórico si se
// ocuparan TODOS los horarios de asesoría configurados en
// disponibilidad_asesorias, al precio actual (MERCADOPAGO_MONTO_ASESORIA).
// Es un techo teórico, no una proyección real -- asume 4.345 semanas
// por mes en promedio. Solo Administrador. Se puede borrar cuando ya no
// haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->query(
    "SELECT d.usuario_id, u.nombre, d.dia_semana, d.hora_inicio, d.hora_fin
     FROM disponibilidad_asesorias d
     JOIN usuarios u ON u.id = d.usuario_id
     WHERE u.activo = 1
     ORDER BY d.usuario_id, d.dia_semana, d.hora_inicio"
);
$filas = $stmt->fetchAll();

$DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$porUsuario = [];
$totalHorasSemana = 0;
foreach ($filas as $f) {
    $inicio = strtotime($f['hora_inicio']);
    $fin = strtotime($f['hora_fin']);
    $horas = max(0, ($fin - $inicio) / 3600);
    $totalHorasSemana += $horas;
    $porUsuario[$f['usuario_id']]['nombre'] = $f['nombre'];
    $porUsuario[$f['usuario_id']]['horas'] = ($porUsuario[$f['usuario_id']]['horas'] ?? 0) + $horas;
    $porUsuario[$f['usuario_id']]['bloques'][] = $DIAS[(int)$f['dia_semana']] . ' ' . substr($f['hora_inicio'], 0, 5) . '-' . substr($f['hora_fin'], 0, 5);
}

$precio = MERCADOPAGO_MONTO_ASESORIA;
$SEMANAS_POR_MES = 4.345; // promedio real (52 semanas / 12 meses)

echo "=== Capacidad máxima teórica de asesorías -- precio actual \${$precio} MXN c/u ===\n\n";
echo "(Cada bloque de disponibilidad es de 1 hora = 1 asesoría posible. Esto es un TECHO teórico -- asume ocupación del 100%, algo que nunca pasa en la práctica.)\n\n";

foreach ($porUsuario as $uid => $d) {
    $asesoriasSemana = $d['horas']; // 1 hora = 1 asesoria
    $ingresoMes = $asesoriasSemana * $SEMANAS_POR_MES * $precio;
    echo "--- {$d['nombre']} ---\n";
    echo "  {$asesoriasSemana} horario(s)/semana:\n";
    foreach ($d['bloques'] as $b) echo "    {$b}\n";
    echo "  Máximo teórico: " . round($asesoriasSemana * $SEMANAS_POR_MES) . " asesorías/mes = \$" . number_format($ingresoMes, 2) . " MXN/mes\n\n";
}

$totalMes = $totalHorasSemana * $SEMANAS_POR_MES;
$ingresoTotalMes = $totalMes * $precio;
echo "=== TOTAL (todos los socios) ===\n";
echo "{$totalHorasSemana} horario(s)/semana en total.\n";
echo "Máximo teórico: " . round($totalMes) . " asesorías/mes = \$" . number_format($ingresoTotalMes, 2) . " MXN/mes, si se ocuparan TODOS.\n";
