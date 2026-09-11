<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: "Cancelar" una asesoría solo cambia su estado a
// 'cancelada' (ver citas_cancelar.php) -- la fila sigue completa en la
// base de datos, así que si se canceló por error se puede regresar a
// 'confirmada' sin perder nada (teléfono, monto, nombre, todo se
// conserva). Muestra las citas canceladas recientes para elegir cuál
// restaurar. GET = vista previa, POST con confirmar=1 y id=... restaura
// de verdad. Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();

$pdo = db();

$confirmar = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:system-ui;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.5} .num{border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px;} button{padding:8px 16px;font-size:14px;cursor:pointer}</style>';

if ($confirmar) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo 'Falta el id.'; exit; }
    $stmt = $pdo->prepare("UPDATE citas_asesoria SET estado = 'confirmada' WHERE id = :id AND estado = 'cancelada'");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() > 0) {
        echo '<h2>Listo</h2><p>La cita #' . $id . ' se restauró a "confirmada" -- ya debería volver a aparecer en "Próximas asesorías agendadas".</p>';
    } else {
        echo '<h2>No se hizo nada</h2><p>Esa cita ya no está cancelada (o no existe) -- revisa la lista de nuevo.</p>';
    }
    echo '<p><a href="debug_restaurar_cita.php">Volver a la vista previa</a></p>';
    exit;
}

$stmt = $pdo->query(
    "SELECT c.id, c.telefono, c.nombre_cliente, c.fecha, c.hora_inicio, c.monto, u.nombre AS abogado
     FROM citas_asesoria c LEFT JOIN usuarios u ON u.id = c.usuario_id
     WHERE c.estado = 'cancelada'
     ORDER BY c.id DESC LIMIT 30"
);
$filas = $stmt->fetchAll();

echo '<h2>Citas canceladas recientes</h2>';
echo '<p>Elige la que se canceló por error y dale clic a "Restaurar" -- vuelve a quedar como "confirmada", con el mismo teléfono, monto y horario de antes.</p>';

if (!$filas) {
    echo '<p>No hay ninguna cita cancelada.</p>';
} else {
    foreach ($filas as $c) {
        echo '<div class="num"><div>#' . $c['id'] . ' -- ' . htmlspecialchars($c['fecha']) . ' ' . substr($c['hora_inicio'], 0, 5)
            . ' -- ' . htmlspecialchars($c['nombre_cliente'] ?: $c['telefono']) . ' (' . htmlspecialchars($c['telefono']) . ') -- $' . $c['monto'] . ' MXN -- ' . htmlspecialchars($c['abogado'] ?: '') . '</div>'
            . '<form method="post" style="margin:0;"><input type="hidden" name="confirmar" value="1"><input type="hidden" name="id" value="' . $c['id'] . '">'
            . '<button type="submit" onclick="return confirm(\'¿Restaurar esta cita?\')">Restaurar</button></form></div>';
    }
}
