<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: para responder directamente "¿conviene
// abrir más horarios?" -- mide, de cada mensaje donde el bot ofreció
// horarios, cuántos DÍAS faltaban hasta el primer horario ofrecido (el
// más próximo), y compara ese promedio entre:
//   A) números que SÍ terminaron con una cita confirmada o pendiente
//   B) números que NUNCA tuvieron ninguna cita
// Si el grupo B consistentemente recibió horarios ofrecidos más lejanos
// que el grupo A, es una señal fuerte de que la saturación (no otra
// cosa) es lo que está empujando a la gente a no agendar -- y que abrir
// más horarios sí debería traducirse en más ventas.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
//
// Uso: debug_brecha_horarios_ofrecidos.php?dias=30 (default 30)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$diasVentana = max(1, min(180, (int)($_GET['dias'] ?? 30)));
$pdo = db();

const MESES = [
    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
    'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
];

function primer_horario_ofrecido(string $texto, DateTimeImmutable $creado): ?DateTimeImmutable
{
    if (!preg_match('/(\d{1,2})\s+de\s+(' . implode('|', array_keys(MESES)) . ')/iu', $texto, $m)) {
        return null;
    }
    $dia = (int)$m[1];
    $mes = MESES[mb_strtolower($m[2])];
    $anio = (int)$creado->format('Y');
    try {
        $fecha = new DateTimeImmutable("{$anio}-{$mes}-{$dia}");
    } catch (\Throwable $e) {
        return null;
    }
    // Si la fecha ya quedó más de 60 días en el pasado respecto al mensaje,
    // probablemente el ofrecimiento cruzó fin de año -- se asume el año
    // siguiente.
    if ($fecha < $creado->modify('-60 days')) {
        $fecha = $fecha->modify('+1 year');
    }
    return $fecha;
}

$stmt = $pdo->prepare(
    "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones
     WHERE direccion = 'saliente' AND respondido_por = 'ia'
       AND texto LIKE '%horarios disponibles%'
       AND creado_en >= :desde"
);
$stmt->execute([':desde' => date('Y-m-d', strtotime("-{$diasVentana} days"))]);
$ofrecimientos = $stmt->fetchAll();

$stmtCita = $pdo->prepare("SELECT 1 FROM citas_asesoria WHERE telefono = :t AND estado IN ('confirmada', 'pendiente_pago') LIMIT 1");

$brechasConCita = [];
$brechasSinCita = [];
$sinFechaDetectada = 0;

foreach ($ofrecimientos as $o) {
    $creado = new DateTimeImmutable($o['creado_en']);
    $primerHorario = primer_horario_ofrecido((string)$o['texto'], $creado);
    if ($primerHorario === null) {
        $sinFechaDetectada++;
        continue;
    }
    $brechaDias = (int)$creado->setTime(0, 0)->diff($primerHorario)->days;

    $stmtCita->execute([':t' => $o['telefono']]);
    if ($stmtCita->fetch()) {
        $brechasConCita[] = $brechaDias;
    } else {
        $brechasSinCita[] = $brechaDias;
    }
}

function resumen(array $vals): string
{
    if (!$vals) return 'sin datos';
    sort($vals);
    $n = count($vals);
    $promedio = round(array_sum($vals) / $n, 1);
    $mediana = $n % 2 === 0 ? ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2 : $vals[(int)($n / 2)];
    return "promedio {$promedio} días | mediana {$mediana} días | n={$n}";
}

echo "=== Brecha (días hasta el primer horario ofrecido) -- últimos {$diasVentana} días ===\n\n";
echo "Números que SÍ terminaron con cita (confirmada o pendiente de pago):\n  " . resumen($brechasConCita) . "\n\n";
echo "Números que NUNCA tuvieron cita:\n  " . resumen($brechasSinCita) . "\n\n";
echo "({$sinFechaDetectada} mensaje(s) donde no se pudo detectar la fecha del primer horario, se excluyeron)\n";
