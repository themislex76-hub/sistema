<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Próximas asesorías agendadas — un abogado ve solo las suyas (las que le
// tocó atender); el administrador las ve todas.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$usuario = require_login();

citas_expirar_pendientes_vencidas(db());

$pdo = db();
// No se filtra por fecha: una cita pagada se queda visible hasta que
// alguien la marque como atendida (ver citas_marcar_atendida.php), para
// que ninguna se pierda de vista si el abogado no llegó a atenderla a
// tiempo. El frontend resalta las que ya vencieron.
$sql = "SELECT c.id, c.telefono, c.usuario_id, u.nombre AS usuario_nombre, c.fecha, c.hora_inicio, c.hora_fin,
               c.estado, c.atendida, c.monto, c.nombre_cliente, c.creado_en, c.pagado_en
        FROM citas_asesoria c
        JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.atendida = 0 AND c.estado IN ('confirmada', 'pendiente_pago')";
$params = [];
if ($usuario['rol'] !== 'administrador') {
    $sql .= ' AND c.usuario_id = :uid';
    $params[':uid'] = $usuario['id'];
}
$sql .= ' ORDER BY c.fecha ASC, c.hora_inicio ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$citas = [];
foreach ($stmt->fetchAll() as $r) {
    $citas[] = [
        'id' => (int)$r['id'],
        'telefono' => $r['telefono'],
        'usuario_nombre' => $r['usuario_nombre'],
        'fecha' => $r['fecha'],
        'hora_inicio' => substr($r['hora_inicio'], 0, 5),
        'hora_fin' => substr($r['hora_fin'], 0, 5),
        'estado' => $r['estado'],
        'atendida' => (bool)$r['atendida'],
        'monto' => (float)$r['monto'],
        'nombre_cliente' => $r['nombre_cliente'],
        'texto' => citas_formatear_fecha_hora($r['fecha'], substr($r['hora_inicio'], 0, 5)),
        'creado_en' => $r['creado_en'],
        'pagado_en' => $r['pagado_en'],
    ];
}

respond(['citas' => $citas]);
