<?php
declare(strict_types=1);

// Cálculo estimado de liquidación para el bot de WhatsApp — usa las
// MISMAS fórmulas que computeLiquidacion() en assets/app.js (la
// calculadora del sistema), para que el estimado que da el bot sea
// consistente con lo que calcula el sistema para un expediente real.
// Simplificado a los 3 datos que el bot puede obtener por chat: fecha de
// ingreso, fecha de baja, salario diario, y si el despido es/sería
// justificado o injustificado. No incluye pactos contractuales (aguinaldo
// o prima vacacional mayores al mínimo de ley) ni vacaciones de años
// anteriores no disfrutadas — esos requieren revisar el caso a detalle,
// por eso el bot siempre aclara que es un estimado y ofrece la asesoría o
// el contacto con el abogado.

function completed_years_lft(DateTimeImmutable $ing, DateTimeImmutable $baj): int
{
    $y = (int)$baj->format('Y') - (int)$ing->format('Y');
    $aniversario = $ing->setDate((int)$baj->format('Y'), (int)$ing->format('n'), (int)$ing->format('j'));
    if ($baj < $aniversario) $y--;
    return max(0, $y);
}

function last_aniversario_lft(DateTimeImmutable $ing, DateTimeImmutable $baj): DateTimeImmutable
{
    $cy = completed_years_lft($ing, $baj);
    return $ing->setDate((int)$ing->format('Y') + $cy, (int)$ing->format('n'), (int)$ing->format('j'));
}

// Tabla de vacaciones del Art. 76 LFT (reforma DOF 27-12-2022, vigente
// desde el 1 de enero de 2023): 12, 14, 16, 18, 20 días y +2 por cada
// quinquenio adicional a partir del 6° año.
function vac_dias_lft(int $y): float
{
    if ($y <= 0) return 0;
    return $y <= 5 ? 10 + 2 * $y : 20 + 2 * (int)ceil(($y - 5) / 5);
}

function salario_minimo_diario_actual(PDO $pdo): float
{
    $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'salario_minimo_diario'");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    $f = $v !== false ? (float)$v : 0;
    return $f > 0 ? $f : 315.04;
}

/**
 * Devuelve null si las fechas/salario no son válidos.
 * $diasVacacionesAnteriores: días de vacaciones de años anteriores que la
 * persona dice que no disfrutó (0 si no aplica o no se sabe).
 * $diasSalariosDevengados: días ya trabajados y no pagados antes de la
 * baja, Art. 82 LFT (0 si no aplica o no se sabe).
 */
function calcular_estimado_liquidacion(
    PDO $pdo,
    string $fechaIngreso,
    string $fechaBaja,
    float $salarioDiario,
    string $tipo,
    float $diasVacacionesAnteriores = 0,
    float $diasSalariosDevengados = 0
): ?array {
    try {
        $ing = (new DateTimeImmutable($fechaIngreso))->setTime(0, 0);
        $baj = (new DateTimeImmutable($fechaBaja))->setTime(0, 0);
    } catch (Exception $e) {
        return null;
    }
    if ($baj < $ing || $salarioDiario <= 0) return null;

    $antigDias = (int)$ing->diff($baj)->days + 1;
    $aniosDec = $antigDias / 365;
    $cy = completed_years_lft($ing, $baj);

    // Aguinaldo proporcional (mínimo 15 días, Art. 87 LFT).
    $yearStart = $baj->setDate((int)$baj->format('Y'), 1, 1);
    $aguStart = $ing > $yearStart ? $ing : $yearStart;
    $aguDias = (int)$aguStart->diff($baj)->days + 1;
    $aguinaldoDias = 15 * ($aguDias / 365);
    $aguinaldoMonto = $aguinaldoDias * $salarioDiario;

    // Vacaciones proporcionales del periodo en curso (Art. 76 y 79 LFT),
    // más las de años anteriores no disfrutadas que la persona reporte.
    $anniv = last_aniversario_lft($ing, $baj);
    $diasCurso = (int)$anniv->diff($baj)->days;
    $vacEnt = vac_dias_lft($cy + 1);
    $vacacionesDiasProp = $vacEnt * ($diasCurso / 365);
    $vacacionesDias = $vacacionesDiasProp + max(0, $diasVacacionesAnteriores);
    $vacacionesMonto = $vacacionesDias * $salarioDiario;

    // Prima vacacional — mínimo 25% (Art. 80 LFT).
    $primaVacacionalMonto = $vacacionesMonto * 0.25;

    // Prima de antigüedad — 12 días por año, con base topada a 2x el
    // salario mínimo diario vigente (Art. 162 LFT). Siempre procede en un
    // despido, sin importar si fue justificado o injustificado.
    $salarioMinimo = salario_minimo_diario_actual($pdo);
    $topePrima = 2 * $salarioMinimo;
    $basePrima = min($salarioDiario, $topePrima);
    $primaAntiguedad = 12 * $aniosDec * $basePrima;

    // Salarios devengados y no pagados (Art. 82 LFT) — días ya trabajados
    // que la persona reporta que no se le pagaron antes de la baja.
    $salariosDevengadosMonto = max(0, $diasSalariosDevengados) * $salarioDiario;

    $totalFiniquito = $aguinaldoMonto + $vacacionesMonto + $primaVacacionalMonto + $primaAntiguedad + $salariosDevengadosMonto;

    // Indemnización constitucional — 90 días de salario (Art. 48 LFT). Solo
    // procede si el despido fue injustificado.
    $indemnizacion90 = $tipo === 'injustificado' ? 90 * $salarioDiario : 0;
    $total = $totalFiniquito + $indemnizacion90;

    return [
        'antiguedad_anios' => round($aniosDec, 2),
        'aguinaldo_dias' => round($aguinaldoDias, 1),
        'aguinaldo_monto' => round($aguinaldoMonto, 2),
        'vacaciones_dias' => round($vacacionesDias, 1),
        'vacaciones_monto' => round($vacacionesMonto, 2),
        'prima_vacacional_monto' => round($primaVacacionalMonto, 2),
        'prima_antiguedad_monto' => round($primaAntiguedad, 2),
        'salarios_devengados_monto' => round($salariosDevengadosMonto, 2),
        'total_finiquito' => round($totalFiniquito, 2),
        'indemnizacion_90_dias_monto' => round($indemnizacion90, 2),
        'total_estimado' => round($total, 2),
        'tipo_despido' => $tipo,
        'nota' => 'Estimado con mínimos de ley (aguinaldo 15 días, prima vacacional 25%) y con los días de vacaciones anteriores/salarios devengados que la persona reportó — sin contar posibles pactos contractuales mayores. El monto real solo se confirma revisando el caso a detalle.',
    ];
}
