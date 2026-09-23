<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: para decidir si conviene abrir una hora más
// temprano de asesorías, junta dos señales:
// 1) Qué tan lleno está el calendario de los próximos días (si casi
//    siempre está lleno cerca de hoy, agregar una hora ayuda de verdad;
//    si sobran huecos cerca, el problema no es de horario).
// 2) Conversaciones reales donde el cliente se quejó de que la fecha
//    ofrecida estaba muy lejos, para ver si de verdad se estaban yendo
//    por eso. Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== 1) Disponibilidad semanal configurada ===\n";
$stmt = $pdo->query(
    "SELECT u.nombre, d.dia_semana, d.hora_inicio, d.hora_fin
     FROM disponibilidad_asesorias d JOIN usuarios u ON u.id = d.usuario_id
     WHERE u.activo = 1 ORDER BY u.nombre, d.dia_semana, d.hora_inicio"
);
foreach ($stmt->fetchAll() as $r) {
    echo "  {$r['nombre']} — " . CITAS_DIA_SEMANA_ES[(int)$r['dia_semana']] . ": {$r['hora_inicio']} a {$r['hora_fin']}\n";
}

echo "\n=== 2) Ocupación de los próximos 7 días (slots de 1 hora) ===\n";
$stmt = $pdo->query(
    "SELECT d.dia_semana, d.hora_inicio, d.hora_fin, d.usuario_id
     FROM disponibilidad_asesorias d JOIN usuarios u ON u.id = d.usuario_id WHERE u.activo = 1"
);
$bloquesPorDia = [];
foreach ($stmt->fetchAll() as $r) {
    $bloquesPorDia[(int)$r['dia_semana']][] = $r;
}
$stmtOcup = $pdo->prepare(
    "SELECT COUNT(*) FROM citas_asesoria
     WHERE estado IN ('confirmada', 'pendiente_pago') AND fecha = :f"
);
for ($i = 0; $i < 7; $i++) {
    $fecha = date('Y-m-d', strtotime("+{$i} day"));
    $diaSemana = (int)date('N', strtotime($fecha));
    $totalSlots = 0;
    foreach (($bloquesPorDia[$diaSemana] ?? []) as $b) {
        $inicio = strtotime($b['hora_inicio']);
        $fin = strtotime($b['hora_fin']);
        $totalSlots += max(0, (int)(($fin - $inicio) / 3600));
    }
    $stmtOcup->execute([':f' => $fecha]);
    $ocupados = (int)$stmtOcup->fetchColumn();
    $pct = $totalSlots > 0 ? round($ocupados / $totalSlots * 100) : 0;
    echo "  {$fecha} (" . CITAS_DIA_SEMANA_ES[$diaSemana] . "): {$ocupados}/{$totalSlots} ocupados ({$pct}%)\n";
}

echo "\n=== 3) Mensajes reales quejándose de que la fecha ofrecida está lejos (últimos 30 días) ===\n";
$patron = '/(mucho\s+tiempo|muy\s+lejos|tan\s+lejos|no\s+hay\s+antes|m[aá]s\s+pronto|no\s+puedo\s+esperar|es\s+mucho|antes\s+no\s+ten|hoy\s+no\s+ten|algo\s+antes|urge\b|es\s+urgente)/iu';
$stmt = $pdo->prepare(
    "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= :desde ORDER BY telefono, id"
);
$stmt->execute([':desde' => date('Y-m-d', strtotime('-30 days'))]);
$candidatos = [];
foreach ($stmt->fetchAll() as $r) {
    if (preg_match($patron, (string)$r['texto']) === 1) {
        $candidatos[] = $r;
    }
}
if (!$candidatos) {
    echo "  No se encontró ninguno con ese patrón en los últimos 30 días.\n";
}
$stmtCita = $pdo->prepare("SELECT 1 FROM citas_asesoria WHERE telefono = :t AND estado = 'confirmada' LIMIT 1");
foreach ($candidatos as $c) {
    $stmtCita->execute([':t' => $c['telefono']]);
    $sicontrato = $stmtCita->fetch() ? 'SÍ tiene cita confirmada' : 'NO tiene cita confirmada';
    echo "  [{$c['creado_en']}] {$c['telefono']} ({$sicontrato}): " . mb_strimwidth((string)$c['texto'], 0, 150, '…') . "\n";
}
