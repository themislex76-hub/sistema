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

    // Se usa 60 días fijos (no "de fecha a fecha" mes calendario, que a
    // veces da 61-62 días según qué meses abarque) — a propósito, para
    // quedar siempre del lado conservador y nunca decirle a alguien que le
    // quedan más días de los que en realidad tiene.
    $limiteOriginal = $despido->modify('+60 days');

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
