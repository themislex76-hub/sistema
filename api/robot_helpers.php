<?php
declare(strict_types=1);

// Compartido entre api/robot_heartbeats_list.php (panel en "Equipo") y
// api/cron_verificar_robots.php (alerta diaria por push). Qué tan seguido
// se espera que corra cada robot local (ver CLAUDE.md, sección "Robots
// locales") -- el margen en horas es generoso a propósito, para no
// disparar una falsa alarma por una corrida que tardó un poco más o varió
// de horario un día.
const ROBOTS_DEF = [
    'federal' => ['nombre' => 'Robot Federal (Portal PJF)', 'frecuencia_horas' => 30],
    'cdmx' => ['nombre' => 'Robot CDMX (Boletín Judicial)', 'frecuencia_horas' => 30],
    'cdmx_buzon' => ['nombre' => 'Robot Buzón Electrónico CDMX', 'frecuencia_horas' => 30],
    'edomex_correo' => ['nombre' => 'Robot Edomex (correo de notificaciones)', 'frecuencia_horas' => 30],
    'jurisprudencia' => ['nombre' => 'Robot Jurisprudencia (SCJN)', 'frecuencia_horas' => 192],
];

function robots_estado(PDO $pdo): array
{
    $filas = $pdo->query('SELECT robot, ultima_corrida_en, ultimo_ok, ultimo_resumen FROM robot_heartbeats')->fetchAll();
    $porRobot = [];
    foreach ($filas as $f) $porRobot[$f['robot']] = $f;

    $out = [];
    foreach (ROBOTS_DEF as $key => $def) {
        $fila = $porRobot[$key] ?? null;
        $vencido = true; // sin ningún heartbeat todavía -- se trata como atrasado
        if ($fila && !empty($fila['ultima_corrida_en'])) {
            $horasDesde = (time() - strtotime($fila['ultima_corrida_en'])) / 3600;
            $vencido = $horasDesde > $def['frecuencia_horas'];
        }
        $out[] = [
            'robot' => $key,
            'nombre' => $def['nombre'],
            'ultima_corrida_en' => $fila['ultima_corrida_en'] ?? null,
            'ultimo_ok' => $fila ? (bool)$fila['ultimo_ok'] : null,
            'ultimo_resumen' => $fila['ultimo_resumen'] ?? null,
            'vencido' => $vencido,
        ];
    }
    return $out;
}
