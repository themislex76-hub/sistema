<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de un solo uso: reactiva el bot (pausado_bot=0) para todos
// los prospectos que se quedaron pausados para siempre por un bug real
// detectado en producción -- al marcar una cita como atendida, o (antes
// de que se corrigiera) al marcar un prospecto como "Descartado", el bot
// se quedaba pausado sin que nadie lo notara, aunque el caso ya estuviera
// resuelto. Corre en dos pasos: GET muestra la vista previa (no cambia
// nada), POST con confirmar=1 aplica el cambio de verdad. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();

$pdo = db();

// Candidatos: prospectos pausados cuyo caso ya se considera resuelto --
// o bien están marcados "Descartado", o bien tienen una cita de asesoría
// ya marcada como atendida.
$sql = "SELECT DISTINCT p.id, p.telefono, p.nombre, p.estatus,
        EXISTS(SELECT 1 FROM citas_asesoria c WHERE c.telefono = p.telefono AND c.atendida = 1) AS tiene_cita_atendida
        FROM prospectos p
        WHERE p.pausado_bot = 1
          AND (p.estatus = 'descartado'
               OR EXISTS(SELECT 1 FROM citas_asesoria c WHERE c.telefono = p.telefono AND c.atendida = 1))
        ORDER BY p.id DESC";
$candidatos = $pdo->query($sql)->fetchAll();

$confirmar = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:system-ui;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.5} .num{border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px} button{padding:10px 18px;font-size:15px;cursor:pointer}</style>';

if ($confirmar) {
    $ids = array_column($candidatos, 'id');
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE prospectos SET pausado_bot = 0 WHERE id IN ($in)")->execute($ids);
    }
    echo '<h2>Listo</h2><p><strong>' . count($ids) . '</strong> prospecto(s) reactivado(s) -- el bot ya les puede contestar solo si escriben de nuevo.</p>';
    echo '<p><a href="debug_despausar_atendidos.php">Volver a la vista previa</a></p>';
    exit;
}

echo '<h2>Prospectos pausados para siempre por error (' . count($candidatos) . ')</h2>';
echo '<p>Casos "Descartado" o con una cita de asesoría ya atendida, que se quedaron con el bot pausado sin que nadie lo notara. Si le das clic a "Reactivar", el bot vuelve a poder contestarles solo si escriben de nuevo (no les manda nada ahora mismo, solo deja de estar pausado).</p>';

if (!$candidatos) {
    echo '<p>Ninguno -- no hay nada que corregir.</p>';
} else {
    foreach ($candidatos as $c) {
        echo '<div class="num">' . htmlspecialchars($c['telefono']) . ' -- ' . htmlspecialchars($c['nombre'] ?: '(sin nombre)')
            . ' -- estatus: ' . htmlspecialchars($c['estatus']) . ($c['tiene_cita_atendida'] ? ' -- tiene cita atendida' : '') . '</div>';
    }
    echo '<form method="post"><input type="hidden" name="confirmar" value="1">'
        . '<button type="submit" onclick="return confirm(\'¿Reactivar el bot para estos ' . count($candidatos) . ' prospectos?\')">Reactivar estos ' . count($candidatos) . '</button></form>';
}
