<?php
declare(strict_types=1);

// Cálculo real (no aritmética de la IA) de los salarios caídos/vencidos
// que le corresponden a un trabajador cuando el patrón no comprueba la
// causa de un despido en juicio (Art. 48 LFT):
//
// - Se computan desde la fecha del despido, con un TOPE de 365 días
//   (12 meses) — pasado ese tope, ya NO se sigue sumando salario día por
//   día.
// - A partir del día 366, en vez de seguir acumulando salario, se genera
//   un interés del 2% mensual, capitalizable (compuesto), sobre el
//   importe de 15 meses de salario (15 × 30 días de salario diario).
//
// El resultado después del tope es un ESTIMADO — el monto final real
// depende de cuándo concluya el juicio, que no se puede saber de
// antemano.

/**
 * $fechaDespido: 'Y-m-d'. $salarioDiario: pesos. $hoy: 'Y-m-d', por
 * defecto la fecha actual. Devuelve null si $fechaDespido no es válida.
 */
function calcular_salarios_caidos(string $fechaDespido, float $salarioDiario, ?string $hoy = null): ?array
{
    try {
        $despido = new DateTimeImmutable($fechaDespido);
    } catch (\Throwable $e) {
        return null;
    }
    $ahora = $hoy !== null ? new DateTimeImmutable($hoy) : new DateTimeImmutable();

    $diasTranscurridos = (int)$despido->diff($ahora)->format('%r%a');
    if ($diasTranscurridos < 0) {
        $diasTranscurridos = 0;
    }

    if ($diasTranscurridos <= 365) {
        return [
            'estado' => 'dentro_del_tope',
            'dias_transcurridos' => $diasTranscurridos,
            'salarios_caidos_estimados' => round($diasTranscurridos * $salarioDiario, 2),
            'nota' => 'Todavía dentro del tope de 365 días (12 meses) desde el despido — se sigue calculando día por día mientras no se pase de ahí.',
        ];
    }

    $baseTope365 = 365 * $salarioDiario;
    $diasExtras = $diasTranscurridos - 365;
    $mesesConInteres = (int)floor($diasExtras / 30);
    $importe15Meses = 15 * 30 * $salarioDiario;
    $interesAcumulado = $importe15Meses * ((1.02 ** $mesesConInteres) - 1);
    $totalEstimado = $baseTope365 + $interesAcumulado;

    return [
        'estado' => 'paso_el_tope',
        'dias_transcurridos' => $diasTranscurridos,
        'salarios_caidos_primeros_365_dias' => round($baseTope365, 2),
        'meses_con_interes' => $mesesConInteres,
        'interes_acumulado_estimado' => round($interesAcumulado, 2),
        'total_estimado' => round($totalEstimado, 2),
        'nota' => 'Después del día 365 ya no se suma salario día por día — cambia a un interés del 2% mensual compuesto sobre el importe de 15 meses de salario (Art. 48 LFT). Esto es un ESTIMADO: el monto final real depende de cuándo concluya el juicio.',
    ];
}
