<?php
declare(strict_types=1);

// Cálculo real (no aritmética de la IA) de cuánto tiempo le queda a una
// persona para presentar su demanda de despido antes de que prescriba su
// derecho (Art. 518 LFT: 2 meses desde el despido).
//
// El trámite de conciliación prejudicial SUSPENDE ese plazo (no lo
// reinicia a cero): se pausa el día que se presenta la solicitud de
// conciliación, y se reanuda al día siguiente de que el Centro de
// Conciliación emite la Constancia de No Conciliación (o el trámite se da
// por concluido) — contando los días que quedaban, no un plazo nuevo de 2
// meses completo (Art. 684-E y 519 LFT).

/**
 * $fechaDespido, $fechaSolicitudConciliacion, $fechaFinConciliacion: 'Y-m-d'
 * o null si no aplica. $hoy: 'Y-m-d', por defecto la fecha actual.
 * Devuelve null si $fechaDespido no es una fecha válida.
 */
function calcular_plazo_demanda(string $fechaDespido, ?string $fechaSolicitudConciliacion, ?string $fechaFinConciliacion, ?string $hoy = null): ?array
{
    try {
        $despido = new DateTimeImmutable($fechaDespido);
    } catch (\Throwable $e) {
        return null;
    }
    $ahora = $hoy !== null ? new DateTimeImmutable($hoy) : new DateTimeImmutable();

    // El plazo de "2 meses" (Art. 518 LFT) puede interpretarse como 60 días
    // naturales fijos, o como 2 meses de fecha a fecha (Art. 1180 Código
    // Civil Federal, supletorio) — y estas dos formas NO siempre coinciden:
    // según qué meses abarquen, el conteo de fecha a fecha puede dar de 59
    // a 62 días, así que ninguna de las dos por sí sola es siempre la más
    // corta. Para quedar SIEMPRE del lado conservador (nunca decirle a
    // alguien que le quedan más días de los que en realidad tiene bajo
    // cualquiera de las dos lecturas), se calculan ambas fechas límite y se
    // usa la que venza PRIMERO.
    $limite60Dias = $despido->modify('+60 days');
    $limiteDosMeses = $despido->modify('+2 months');
    $limiteOriginal = min($limite60Dias, $limiteDosMeses);

    // No inició trámite de conciliación todavía — el plazo corre normal
    // desde el despido.
    if ($fechaSolicitudConciliacion === null || $fechaSolicitudConciliacion === '') {
        $diasRestantes = (int)$ahora->diff($limiteOriginal)->format('%r%a');
        return [
            'estado' => $diasRestantes < 0 ? 'vencido' : 'vigente',
            'dias_restantes' => $diasRestantes,
            'fecha_limite' => $limiteOriginal->format('Y-m-d'),
            'nota' => 'Todavía no inició trámite de conciliación — el plazo corre normal.',
        ];
    }

    try {
        $solicitud = new DateTimeImmutable($fechaSolicitudConciliacion);
    } catch (\Throwable $e) {
        return null;
    }

    // Días que ya habían transcurrido del plazo original al momento de
    // presentar la solicitud (lo que se "guarda" mientras dura la pausa).
    $diasRestantesAlPausar = (int)$solicitud->diff($limiteOriginal)->format('%r%a');

    // Trámite de conciliación en curso, todavía sin Constancia — el plazo
    // sigue pausado, no corre ahora mismo.
    if ($fechaFinConciliacion === null || $fechaFinConciliacion === '') {
        return [
            'estado' => 'pausado',
            'dias_restantes' => null,
            'dias_restantes_al_reanudar' => $diasRestantesAlPausar,
            'fecha_limite' => null,
            'nota' => 'El plazo está pausado mientras dura la conciliación. Al concluir (Constancia de No Conciliación), '
                . 'le quedarán ' . max(0, $diasRestantesAlPausar) . ' día(s) para demandar.',
        ];
    }

    try {
        $finConciliacion = new DateTimeImmutable($fechaFinConciliacion);
    } catch (\Throwable $e) {
        return null;
    }

    // El plazo se reanuda al día siguiente de la Constancia, con los días
    // que quedaban (no un plazo nuevo completo).
    $reanuda = $finConciliacion->modify('+1 day');
    $limiteFinal = $reanuda->modify('+' . max(0, $diasRestantesAlPausar) . ' days');
    $diasRestantes = (int)$ahora->diff($limiteFinal)->format('%r%a');

    return [
        'estado' => $diasRestantes < 0 ? 'vencido' : 'vigente',
        'dias_restantes' => $diasRestantes,
        'fecha_limite' => $limiteFinal->format('Y-m-d'),
        'nota' => 'Plazo reanudado desde el día siguiente a la Constancia de No Conciliación, con los días que quedaban.',
    ];
}

// Cálculo real (no aritmética de la IA) del plazo para reclamar
// prestaciones/finiquito (aguinaldo, vacaciones, prima vacacional, salarios
// no pagados, etc.) cuando NO se trata de un despido ni de una rescisión
// del Art. 51 LFT -- por ejemplo una renuncia voluntaria donde el patrón se
// quedó debiendo el finiquito. Bug real detectado en producción: el bot le
// aplicó a un caso así el plazo de 2 meses del despido (Art. 518 LFT),
// dándole al cliente una falsa urgencia de "solo te quedan 7 días" cuando
// en realidad, al ser una reclamación de prestaciones, el plazo real es de
// UN AÑO (Art. 516 LFT, regla general de prescripción).
//
// $fechaBaja: 'Y-m-d', fecha en que terminó la relación laboral (renuncia,
// término del contrato, etc. -- NO un despido/rescisión, esos usan
// calcular_plazo_demanda). $hoy: 'Y-m-d', por defecto la fecha actual.
function calcular_plazo_prestaciones(string $fechaBaja, ?string $fechaSolicitudConciliacion, ?string $fechaFinConciliacion, ?string $hoy = null): ?array
{
    try {
        $baja = new DateTimeImmutable($fechaBaja);
    } catch (\Throwable $e) {
        return null;
    }
    $ahora = $hoy !== null ? new DateTimeImmutable($hoy) : new DateTimeImmutable();
    $limiteOriginal = $baja->modify('+365 days');

    if ($fechaSolicitudConciliacion === null || $fechaSolicitudConciliacion === '') {
        $diasRestantes = (int)$ahora->diff($limiteOriginal)->format('%r%a');
        return [
            'estado' => $diasRestantes < 0 ? 'vencido' : 'vigente',
            'dias_restantes' => $diasRestantes,
            'fecha_limite' => $limiteOriginal->format('Y-m-d'),
            'nota' => 'Plazo general de prestaciones (Art. 516 LFT, 1 año), no el de despido -- todavía no inició trámite de conciliación, el plazo corre normal.',
        ];
    }

    try {
        $solicitud = new DateTimeImmutable($fechaSolicitudConciliacion);
    } catch (\Throwable $e) {
        return null;
    }
    $diasRestantesAlPausar = (int)$solicitud->diff($limiteOriginal)->format('%r%a');

    if ($fechaFinConciliacion === null || $fechaFinConciliacion === '') {
        return [
            'estado' => 'pausado',
            'dias_restantes' => null,
            'dias_restantes_al_reanudar' => $diasRestantesAlPausar,
            'fecha_limite' => null,
            'nota' => 'Plazo de prestaciones (Art. 516 LFT, 1 año) pausado mientras dura la conciliación. Al concluir '
                . '(Constancia de No Conciliación), le quedarán ' . max(0, $diasRestantesAlPausar) . ' día(s) para demandar.',
        ];
    }

    try {
        $finConciliacion = new DateTimeImmutable($fechaFinConciliacion);
    } catch (\Throwable $e) {
        return null;
    }
    $reanuda = $finConciliacion->modify('+1 day');
    $limiteFinal = $reanuda->modify('+' . max(0, $diasRestantesAlPausar) . ' days');
    $diasRestantes = (int)$ahora->diff($limiteFinal)->format('%r%a');

    return [
        'estado' => $diasRestantes < 0 ? 'vencido' : 'vigente',
        'dias_restantes' => $diasRestantes,
        'fecha_limite' => $limiteFinal->format('Y-m-d'),
        'nota' => 'Plazo de prestaciones (Art. 516 LFT, 1 año) reanudado desde el día siguiente a la Constancia de No Conciliación, con los días que quedaban.',
    ];
}
