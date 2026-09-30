<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca la primera vez que se creó una cita a las
// 11:00 am -- pista indirecta de cuándo ese horario empezó a estar
// disponible en disponibilidad_asesorias (esa tabla no guarda
// historial de cambios). Solo Administrador. Se puede borrar cuando ya
// no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$stmt = $pdo->query(
    "SELECT id, telefono, fecha, estado, creado_en
     FROM citas_asesoria
     WHERE hora_inicio = '11:00:00'
     ORDER BY creado_en ASC
     LIMIT 5"
);
$primeras = $stmt->fetchAll();

echo "=== Primeras citas creadas a las 11:00 am (cualquier estado) ===\n\n";
if (!$primeras) {
    echo "Nunca se ha creado ninguna cita a las 11:00 am.\n";
} else {
    foreach ($primeras as $r) {
        echo "id={$r['id']} | creada el {$r['creado_en']} | para el {$r['fecha']} 11:00 am | estado={$r['estado']} | tel={$r['telefono']}\n";
    }
}

echo "\n=== Conteo de citas por hora_inicio, por semana de creación (últimas 6 semanas) ===\n";
echo "(Para ver si alguna hora aparece de repente a partir de cierta semana)\n\n";
$stmt = $pdo->query(
    "SELECT YEARWEEK(creado_en, 3) AS semana, hora_inicio, COUNT(*) AS n
     FROM citas_asesoria
     WHERE creado_en >= NOW() - INTERVAL 6 WEEK
     GROUP BY semana, hora_inicio
     ORDER BY semana, hora_inicio"
);
$actual = null;
foreach ($stmt->fetchAll() as $r) {
    if ($r['semana'] !== $actual) {
        echo "\nSemana {$r['semana']}:\n";
        $actual = $r['semana'];
    }
    echo "  " . substr($r['hora_inicio'], 0, 5) . " -> {$r['n']} cita(s)\n";
}
