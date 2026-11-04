<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra TODAS las citas de asesoría agendadas
// para hoy (cualquier estado), con hora y abogado -- para confirmar a
// mano si un horario específico está realmente ocupado o libre, sin
// depender del filtro de anticipación mínima que usa
// citas_calcular_horarios_disponibles() al ofrecer horarios nuevos (ese
// filtro puede ocultar un horario que en realidad sí está libre). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT c.hora_inicio, c.hora_fin, c.estado, c.telefono, c.nombre_cliente, u.nombre AS abogado, c.creado_en
     FROM citas_asesoria c
     JOIN usuarios u ON u.id = c.usuario_id
     WHERE c.fecha = CURDATE()
     ORDER BY c.hora_inicio"
);
$stmt->execute();
$filas = $stmt->fetchAll();

echo "=== Citas de hoy (" . date('Y-m-d, N') . ") ===\n\n";
if (!$filas) {
    echo "No hay NINGUNA cita registrada para hoy (ningún estado) -- toda la agenda de hoy está libre en el sistema.\n";
    exit;
}

foreach ($filas as $f) {
    echo substr($f['hora_inicio'], 0, 5) . '-' . substr($f['hora_fin'], 0, 5)
        . " | " . $f['abogado']
        . " | estado=" . $f['estado']
        . " | " . ($f['nombre_cliente'] ?: '(sin nombre)') . " (" . $f['telefono'] . ")"
        . " | creada=" . $f['creado_en'] . "\n";
}
