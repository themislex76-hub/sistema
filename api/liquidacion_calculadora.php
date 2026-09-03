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

// Máximo de días de vacaciones de años anteriores que NO están
// prescritos, según el criterio de la Segunda Sala SCJN (2a./J. 1/97):
// el año de prescripción del Art. 516 LFT corre a partir de que vence el
// periodo de 6 meses del Art. 81 para tomarlas — en la práctica, cada
// año de vacaciones no disfrutado deja de poder reclamarse 18 meses
// después de su aniversario correspondiente. Recorre los aniversarios
// del más reciente al más antiguo y se detiene en el primero que ya
// pasó esa ventana de 18 meses (los anteriores a ese ya están todos
// prescritos también). Misma lógica que maxVacNoPrescrito() en la
// calculadora del sitio (assets del sitio web).
function max_vacaciones_anteriores_no_prescritas_lft(DateTimeImmutable $ing, DateTimeImmutable $baj): float
{
    $cy = completed_years_lft($ing, $baj);
    $tot = 0.0;
    for ($i = $cy; $i >= 1; $i--) {
        $aniversario = $ing->setDate((int)$ing->format('Y') + $i, (int)$ing->format('n'), (int)$ing->format('j'));
        $limite = $aniversario->modify('+18 months');
        if ($baj >= $limite) {
            break;
        }
        $tot += vac_dias_lft($i);
    }
    return $tot;
}

// Texto legible de antigüedad ("1 año, 6 meses, 17 días") -- usa el
// desglose real de calendario (DateInterval), no una aproximación de
// ÷365/÷30: esa aproximación se detectó en producción dando fechas mal
// (ej. "10 años, 10 meses, 20 días" en vez de "10 años, 10 meses, 12
// días" para el mismo ingreso/baja, porque ÷365 y ÷30 no respetan meses
// de 28-31 días ni años bisiestos). Los montos en pesos no se ven
// afectados por este bug -- usan $ing/$baj directamente (completed_years_lft,
// last_aniversario_lft) -- solo el texto mostrado al cliente/abogado.
function antiguedad_texto_lft(DateTimeImmutable $ing, DateTimeImmutable $baj): string
{
    $diff = $ing->diff($baj);
    $anios = $diff->y;
    $meses = $diff->m;
    $dias = $diff->d;

    $partes = [];
    if ($anios > 0) $partes[] = $anios . ' ' . ($anios === 1 ? 'año' : 'años');
    if ($meses > 0) $partes[] = $meses . ' ' . ($meses === 1 ? 'mes' : 'meses');
    if ($dias > 0 || empty($partes)) $partes[] = $dias . ' ' . ($dias === 1 ? 'día' : 'días');

    return implode(', ', $partes);
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
 * Salario diario integrado cuando hay percepciones variables (bonos,
 * comisiones) de por medio (Art. 289 LFT) -- bug real detectado en
 * producción: el bot estaba haciendo este promedio "a mano" (aritmética de
 * la IA, con la fórmula descrita en texto plano en la herramienta de
 * liquidación) para un caso con un bono anual de casi $113,000, y su
 * propio mensaje admitió que el resultado "a mano" salió distinto al de
 * la herramienta -- exactamente el tipo de cálculo legal que este sistema
 * nunca debe dejarle a la IA.
 *
 * Criterio (jurisprudencia SCJN sobre integración de salario variable):
 * percepciones variables de periodicidad ANUAL o esporádica (bonos
 * anuales, reparto de utilidades, etc.) se promedian sobre los últimos
 * 365 días -- no sobre 30 días, que es el criterio que aplica solo a
 * percepciones variables de periodicidad corta y regular (comisiones
 * semanales/quincenales, por ejemplo). Esta función es para el caso
 * ANUAL; si el caso es de comisiones frecuentes, no la uses -- eso
 * requiere revisión directa del abogado.
 *
 * $salarioDiarioFijo: la parte fija del salario, ya convertida a diario
 * (sueldo + prestaciones fijas regulares -- fondo de ahorro, vales de
 * despensa, etc. -- entre 30 si es mensual o entre 15 si es quincenal).
 * $percepcionesVariables: arreglo de ['monto' => float, 'fecha' => 'Y-m-d']
 * -- cada pago variable que la persona haya recibido, con su fecha real.
 * $fechaReferencia: 'Y-m-d', normalmente la fecha de baja (o la de hoy si
 * el caso todavía no ha pasado) -- se cuentan los pagos de los 365 días
 * anteriores a esta fecha (inclusive).
 */
function calcular_sdi_con_variable(float $salarioDiarioFijo, array $percepcionesVariables, string $fechaReferencia): ?array
{
    try {
        $referencia = new DateTimeImmutable($fechaReferencia);
    } catch (\Throwable $e) {
        return null;
    }
    $desde = $referencia->modify('-365 days');

    $totalConsiderado = 0.0;
    $pagosConsiderados = 0;
    $pagosFueraDeRango = 0;
    foreach ($percepcionesVariables as $p) {
        $monto = (float)($p['monto'] ?? 0);
        $fechaStr = (string)($p['fecha'] ?? '');
        if ($monto <= 0 || $fechaStr === '') continue;
        try {
            $fecha = new DateTimeImmutable($fechaStr);
        } catch (\Throwable $e) {
            continue;
        }
        if ($fecha >= $desde && $fecha <= $referencia) {
            $totalConsiderado += $monto;
            $pagosConsiderados++;
        } else {
            $pagosFueraDeRango++;
        }
    }

    $promedioDiarioVariable = $totalConsiderado / 365;
    $salarioDiarioIntegrado = $salarioDiarioFijo + $promedioDiarioVariable;

    return [
        'salario_diario_fijo' => round($salarioDiarioFijo, 2),
        'total_variable_considerado' => round($totalConsiderado, 2),
        'pagos_considerados' => $pagosConsiderados,
        'pagos_fuera_de_rango_365_dias' => $pagosFueraDeRango,
        'promedio_diario_variable' => round($promedioDiarioVariable, 2),
        'salario_diario_a_usar' => round($salarioDiarioIntegrado, 2),
        'nota' => 'Este es el salario diario (fijo + variable promediado a 365 días, Art. 289 LFT) que hay que pasar '
            . 'como salario_diario a calcular_estimado_liquidacion -- esa herramienta ya le aplica encima, aparte, '
            . 'el factor de integración de aguinaldo/prima vacacional mínimos de ley, no lo agregues tú dos veces.',
    ];
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
    float $diasSalariosDevengados = 0,
    string $modo = 'despido'
): ?array {
    if (!in_array($modo, ['despido', 'renuncia', 'rescision'], true)) {
        $modo = 'despido';
    }
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
    $maxVacAnterioresNoPrescritas = max_vacaciones_anteriores_no_prescritas_lft($ing, $baj);
    $vacAntDiasReconocidos = min(max(0, $diasVacacionesAnteriores), $maxVacAnterioresNoPrescritas);
    $vacAntDiasPrescritos = max(0, $diasVacacionesAnteriores - $vacAntDiasReconocidos);
    $vacacionesDias = $vacacionesDiasProp + $vacAntDiasReconocidos;
    $vacacionesMonto = $vacacionesDias * $salarioDiario;

    // Prima vacacional — mínimo 25% (Art. 80 LFT).
    $primaVacacionalMonto = $vacacionesMonto * 0.25;

    // Prima de antigüedad — 12 días por año, con base topada a 2x el
    // salario mínimo diario vigente (Art. 162 LFT). Siempre procede en un
    // despido o rescisión, sin importar si el despido fue justificado o
    // injustificado. En renuncia voluntaria SOLO procede con 15 años o más
    // de servicio (Art. 162-III LFT).
    $salarioMinimo = salario_minimo_diario_actual($pdo);
    $topePrima = 2 * $salarioMinimo;
    $basePrima = min($salarioDiario, $topePrima);
    $primaAntiguedadProcede = $modo !== 'renuncia' || $cy >= 15;
    $primaAntiguedad = $primaAntiguedadProcede ? 12 * $aniosDec * $basePrima : 0.0;

    // Salarios devengados y no pagados (Art. 82 LFT) — días ya trabajados
    // que la persona reporta que no se le pagaron antes de la baja.
    $salariosDevengadosMonto = max(0, $diasSalariosDevengados) * $salarioDiario;

    $totalFiniquito = $aguinaldoMonto + $vacacionesMonto + $primaVacacionalMonto + $primaAntiguedad + $salariosDevengadosMonto;

    // Salario Diario Integrado (SDI) — con los mismos mínimos de ley usados
    // arriba (15 días de aguinaldo, 25% de prima vacacional), igual que
    // recalcSdiSuggested() en la calculadora del sitio. La indemnización
    // constitucional de 90 días (Art. 48 LFT) se paga con salario
    // integrado, no con el salario diario simple.
    $factorIntegracion = (365 + 15 + $vacEnt * 0.25) / 365;
    $sdi = $salarioDiario * $factorIntegracion;

    // Indemnización — depende del modo:
    // - despido injustificado (Art. 48 LFT): 90 días de SDI.
    // - renuncia voluntaria: no hay indemnización.
    // - rescisión por causa imputable al patrón, ejercida dentro de los 30
    //   días (Arts. 51/52 LFT): 20 días por año de servicio (Art. 50-II)
    //   más 90 días de SDI (Art. 50-III), igual que un despido injustificado.
    $indemnizacion20 = 0.0;
    $indemnizacion90 = 0.0;
    if ($modo === 'despido' && $tipo === 'injustificado') {
        $indemnizacion90 = 90 * $sdi;
    } elseif ($modo === 'rescision') {
        $indemnizacion20 = 20 * $aniosDec * $sdi;
        $indemnizacion90 = 90 * $sdi;
    }
    $total = $totalFiniquito + $indemnizacion90 + $indemnizacion20;

    return [
        'modo' => $modo,
        'antiguedad_anios' => round($aniosDec, 2),
        'antiguedad_anios_completos' => $cy,
        'antiguedad_texto' => antiguedad_texto_lft($ing, $baj),
        'sdi_usado' => round($sdi, 2),
        'prima_antiguedad_procede' => $primaAntiguedadProcede,
        'aguinaldo_dias' => round($aguinaldoDias, 1),
        'aguinaldo_monto' => round($aguinaldoMonto, 2),
        'vacaciones_dias' => round($vacacionesDias, 1),
        'vacaciones_monto' => round($vacacionesMonto, 2),
        'vacaciones_anteriores_dias_prescritos' => round($vacAntDiasPrescritos, 1),
        'prima_vacacional_monto' => round($primaVacacionalMonto, 2),
        'prima_antiguedad_monto' => round($primaAntiguedad, 2),
        'salarios_devengados_monto' => round($salariosDevengadosMonto, 2),
        'total_finiquito' => round($totalFiniquito, 2),
        'indemnizacion_20_dias_monto' => round($indemnizacion20, 2),
        'indemnizacion_90_dias_monto' => round($indemnizacion90, 2),
        'total_estimado' => round($total, 2),
        'tipo_despido' => $tipo,
        'nota' => 'Estimado con mínimos de ley (aguinaldo 15 días, prima vacacional 25%) y con los días de vacaciones anteriores/salarios devengados que la persona reportó — sin contar posibles pactos contractuales mayores. Si reportó más días de vacaciones anteriores de los que ya no están prescritos (ver vacaciones_anteriores_dias_prescritos), solo se contaron los no prescritos, según el criterio SCJN 2a./J. 1/97 (18 meses desde cada aniversario). El monto real solo se confirma revisando el caso a detalle.',
    ];
}
