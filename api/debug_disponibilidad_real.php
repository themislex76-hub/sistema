<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: hasta qué fecha está llena la agenda de
// asesorías -- usa la MISMA función que usa el bot para ofrecer horarios
// (citas_calcular_horarios_disponibles), solo que mirando más días
// adelante y sin el tope de 5 opciones, para ver el panorama completo en
// vez de nada más lo primero que vería un cliente. Solo Administrador.
// Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$diasAdelante = isset($_GET['dias']) ? max(1, (int)$_GET['dias']) : 45;

$pdo = db();
$opciones = citas_calcular_horarios_disponibles($pdo, $diasAdelante, 999999, 3);

if (!$opciones) {
    echo "No hay NINGÚN horario libre en los próximos {$diasAdelante} días.\n";
    echo "Esto pasa si no hay ningún bloque en disponibilidad_asesorias (de ningún abogado activo),\n";
    echo "o si todos los horarios configurados ya están ocupados en ese rango.\n";
    exit;
}

$porDia = [];
foreach ($opciones as $o) {
    $porDia[$o['fecha']][] = $o['hora_inicio'];
}
ksort($porDia);

$hoy = date('Y-m-d');
$primerLibre = array_key_first($porDia);

echo "Primer horario libre: {$primerLibre}\n";
echo "Total de horarios libres en los próximos {$diasAdelante} días: " . count($opciones) . "\n";
echo str_repeat('-', 70) . "\n\n";

$cursor = new DateTimeImmutable($hoy);
$fin = (new DateTimeImmutable($hoy))->modify("+{$diasAdelante} days");
while ($cursor < $fin) {
    $f = $cursor->format('Y-m-d');
    if (isset($porDia[$f])) {
        echo "{$f}: " . count($porDia[$f]) . " libre(s) -> " . implode(', ', $porDia[$f]) . "\n";
    } else {
        echo "{$f}: LLENO (o sin bloques de disponibilidad ese día de la semana)\n";
    }
    $cursor = $cursor->modify('+1 day');
}
