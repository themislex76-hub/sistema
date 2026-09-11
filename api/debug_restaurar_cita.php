<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: cubre las dos formas de "desaparecer" una cita de
// "Próximas asesorías agendadas" por error -- "Cancelar" (estado pasa a
// 'cancelada', ver citas_cancelar.php) y "Marcar atendida" (atendida pasa
// a 1, ver citas_marcar_atendida.php). Ninguna de las dos borra la fila,
// así que ambas se pueden deshacer sin perder nada (teléfono, monto,
// nombre, todo se conserva). GET = vista previa, POST con confirmar=1,
// id=... y tipo=cancelada|atendida restaura de verdad. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();

$pdo = db();

$confirmar = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:system-ui;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.5} .num{border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px;} button{padding:8px 16px;font-size:14px;cursor:pointer}</style>';

if ($confirmar) {
    $id = (int)($_POST['id'] ?? 0);
    $tipo = (string)($_POST['tipo'] ?? '');
    if ($id <= 0 || !in_array($tipo, ['cancelada', 'atendida'], true)) { echo 'Faltan datos.'; exit; }

    if ($tipo === 'cancelada') {
        $stmt = $pdo->prepare("UPDATE citas_asesoria SET estado = 'confirmada' WHERE id = :id AND estado = 'cancelada'");
    } else {
        $stmt = $pdo->prepare("UPDATE citas_asesoria SET atendida = 0 WHERE id = :id AND atendida = 1");
    }
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() > 0) {
        echo '<h2>Listo</h2><p>La cita #' . $id . ' se restauró -- ya debería volver a aparecer en "Próximas asesorías agendadas".</p>';
    } else {
        echo '<h2>No se hizo nada</h2><p>Esa cita ya no está en ese estado (o no existe) -- revisa la lista de nuevo.</p>';
    }
    echo '<p><a href="debug_restaurar_cita.php">Volver a la vista previa</a></p>';
    exit;
}

function listaHTML(PDO $pdo, string $where, string $tipo): string
{
    $stmt = $pdo->query(
        "SELECT c.id, c.telefono, c.nombre_cliente, c.fecha, c.hora_inicio, c.monto, u.nombre AS abogado
         FROM citas_asesoria c LEFT JOIN usuarios u ON u.id = c.usuario_id
         WHERE $where
         ORDER BY c.id DESC LIMIT 30"
    );
    $filas = $stmt->fetchAll();
    if (!$filas) return '<p>Ninguna.</p>';
    $html = '';
    foreach ($filas as $c) {
        $html .= '<div class="num"><div>#' . $c['id'] . ' -- ' . htmlspecialchars($c['fecha']) . ' ' . substr($c['hora_inicio'], 0, 5)
            . ' -- ' . htmlspecialchars($c['nombre_cliente'] ?: $c['telefono']) . ' (' . htmlspecialchars($c['telefono']) . ') -- $' . $c['monto'] . ' MXN -- ' . htmlspecialchars($c['abogado'] ?: '') . '</div>'
            . '<form method="post" style="margin:0;"><input type="hidden" name="confirmar" value="1"><input type="hidden" name="id" value="' . $c['id'] . '"><input type="hidden" name="tipo" value="' . $tipo . '">'
            . '<button type="submit" onclick="return confirm(\'¿Restaurar esta cita?\')">Restaurar</button></form></div>';
    }
    return $html;
}

echo '<h2>Citas canceladas recientes</h2>';
echo '<p>Se cancelaron con el botón "Cancelar" -- al restaurar, vuelven a "confirmada".</p>';
echo listaHTML($pdo, "c.estado = 'cancelada'", 'cancelada');

echo '<h2>Citas marcadas como atendidas recientemente</h2>';
echo '<p>Se marcaron con el botón "Marcar atendida" -- si fue por error (la llamada no se dio), al restaurar vuelven a aparecer en "Próximas asesorías agendadas".</p>';
echo listaHTML($pdo, "c.atendida = 1 AND c.estado = 'confirmada'", 'atendida');
