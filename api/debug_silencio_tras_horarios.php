<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: encuentra números a los que el bot les
// ofreció horarios de asesoría (mensaje con "horarios disponibles") y
// que NUNCA volvieron a escribir después de eso -- candidatos reales
// para que el despacho los recontacte (ya mostraron interés real, solo
// se quedaron sin responder, posiblemente porque ninguno de los
// horarios les quedó bien). Excluye a quien ya tiene una cita
// (confirmada o pendiente de pago), para no ofrecerle una asesoría que
// ya tiene. Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_silencio_tras_horarios.php?dias=30  (default 30)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = max(1, min(180, (int)($_GET['dias'] ?? 30)));
$pdo = db();

// Último mensaje de "horarios disponibles" que el bot le mandó a cada
// número (si le ofreció varias veces, solo nos importa la más reciente).
$stmt = $pdo->prepare(
    "SELECT telefono, MAX(creado_en) AS ultimo_ofrecimiento
     FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND respondido_por = 'ia'
       AND texto LIKE '%horarios disponibles%'
       AND creado_en >= :desde
     GROUP BY telefono"
);
$stmt->execute([':desde' => date('Y-m-d', strtotime("-{$dias} days"))]);
$ofrecidos = $stmt->fetchAll();

$stmtSiguiente = $pdo->prepare(
    "SELECT 1 FROM whatsapp_conversaciones
     WHERE telefono = :t AND direccion = 'entrante' AND creado_en > :desde LIMIT 1"
);
$stmtCita = $pdo->prepare(
    "SELECT estado FROM citas_asesoria WHERE telefono = :t ORDER BY id DESC LIMIT 1"
);

$candidatos = [];
foreach ($ofrecidos as $o) {
    $stmtSiguiente->execute([':t' => $o['telefono'], ':desde' => $o['ultimo_ofrecimiento']]);
    if ($stmtSiguiente->fetch()) {
        continue; // sí volvió a escribir después -- no es silencio
    }
    $stmtCita->execute([':t' => $o['telefono']]);
    $estadoCita = $stmtCita->fetchColumn();
    if (in_array($estadoCita, ['confirmada', 'pendiente_pago'], true)) {
        continue; // ya tiene cita, no hace falta recontactarlo
    }
    $candidatos[] = [
        'telefono' => $o['telefono'],
        'ultimo_ofrecimiento' => $o['ultimo_ofrecimiento'],
        'estado_cita' => $estadoCita ?: '(sin cita nunca)',
    ];
}

usort($candidatos, fn($a, $b) => strcmp($b['ultimo_ofrecimiento'], $a['ultimo_ofrecimiento']));

echo "=== Candidatos: se les ofrecieron horarios y nunca volvieron a escribir (últimos {$dias} días) ===\n";
echo count($candidatos) . " número(s) encontrado(s) de " . count($ofrecidos) . " a los que se les ofrecieron horarios en ese periodo.\n\n";
foreach ($candidatos as $c) {
    echo "{$c['telefono']} | último ofrecimiento: {$c['ultimo_ofrecimiento']} | cita más reciente: {$c['estado_cita']}\n";
}
if (!$candidatos) {
    echo "(ninguno -- o todos volvieron a escribir, o ya tienen cita)\n";
}
