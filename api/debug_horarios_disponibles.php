<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta de solo lectura: muestra los mismos horarios reales que el
// bot le ofrecería ahora mismo a un cliente nuevo (citas_calcular_horarios_disponibles),
// para poder elegir uno a mano al reagendar una cita con pago tardío (ver
// mercadopago_webhook.php) sin tener que adivinar ni entrar por
// phpMyAdmin a revisar disponibilidad_asesorias/citas_asesoria a mano.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();

$pdo = db();
$horarios = citas_calcular_horarios_disponibles($pdo, 12, 15);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:system-ui;max-width:600px;margin:24px auto;padding:0 16px;line-height:1.5} .num{border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px; display:flex; justify-content:space-between;} code{background:#f3f3f3;padding:2px 6px;border-radius:4px;}</style>';
echo '<h2>Horarios disponibles ahora mismo (' . count($horarios) . ')</h2>';
echo '<p>Los mismos que vería un cliente nuevo si el bot le ofreciera horarios en este momento. La columna de la derecha ya trae los valores listos para un UPDATE manual en citas_asesoria.</p>';

if (!$horarios) {
    echo '<p>No hay ningún horario disponible en los próximos 12 días.</p>';
} else {
    foreach ($horarios as $h) {
        echo '<div class="num"><span>' . htmlspecialchars($h['texto']) . '</span>'
            . '<code>fecha=\'' . htmlspecialchars($h['fecha']) . '\', hora_inicio=\'' . htmlspecialchars($h['hora_inicio']) . ':00\', hora_fin=\'' . htmlspecialchars($h['hora_fin']) . ':00\'</code></div>';
    }
}
