<?php
declare(strict_types=1);

// Lógica para calcular horarios disponibles de asesoría (cruzando la
// disponibilidad semanal de cada abogado con lo que ya está apartado) y
// para crear/confirmar la cita una vez que el cliente elige un horario y
// paga. Ver disponibilidad_*.php (lo que cada abogado configura) y
// mercadopago_helpers.php (el link de pago).

// Minutos que se le da a un cliente para pagar antes de que el horario
// que apartó se libere de nuevo — evita que un horario quede "atorado"
// para siempre si alguien pide el link y nunca paga.
const CITAS_HOLD_MINUTOS = 30;

const CITAS_DIA_SEMANA_ES = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
const CITAS_MES_ES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

function citas_formatear_fecha_hora(string $fecha, string $horaInicio): string
{
    $f = new DateTimeImmutable($fecha);
    $diaSemana = CITAS_DIA_SEMANA_ES[(int)$f->format('N')];
    $mes = CITAS_MES_ES[(int)$f->format('n')];
    [$h, $m] = explode(':', $horaInicio);
    $h = (int)$h;
    $ampm = $h >= 12 ? 'pm' : 'am';
    $h12 = $h % 12;
    if ($h12 === 0) $h12 = 12;
    $horaTxt = $m === '00' ? "{$h12}:00" : "{$h12}:{$m}";
    return "{$diaSemana} {$f->format('j')} de {$mes}, {$horaTxt} {$ampm}";
}

/**
 * Marca como "expirada" cualquier cita pendiente de pago cuyo tiempo de
 * espera (CITAS_HOLD_MINUTOS) ya pasó — así el horario vuelve a estar
 * libre y no queda una cita fantasma visible como "pendiente" para
 * siempre. Se llama de paso cada vez que se calculan horarios u ocupación.
 */
function citas_expirar_pendientes_vencidas(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "UPDATE citas_asesoria SET estado = 'expirada'
         WHERE estado = 'pendiente_pago' AND creado_en <= :limite"
    );
    $stmt->execute([':limite' => date('Y-m-d H:i:s', time() - CITAS_HOLD_MINUTOS * 60)]);
}

/**
 * Calcula hasta $maxOpciones horarios de 1 hora disponibles en los
 * próximos $diasAdelante días, cruzando la disponibilidad semanal de
 * todos los abogados activos con lo que ya está confirmado o apartado
 * (pendiente de pago y aún dentro de la ventana de espera). No le importa
 * al cliente qué abogado le toca — el sistema asigna el primero libre al
 * momento de confirmar (ver citas_crear_pendiente).
 * Devuelve un arreglo de ['fecha'=>'Y-m-d', 'hora_inicio'=>'H:i', 'hora_fin'=>'H:i', 'texto'=>'lunes 10 de agosto, 9:00 am'].
 */
function citas_calcular_horarios_disponibles(PDO $pdo, int $diasAdelante = 12, int $maxOpciones = 5, int $anticipacionMinimaHoras = 3): array
{
    citas_expirar_pendientes_vencidas($pdo);

    $stmt = $pdo->query(
        "SELECT d.usuario_id, d.dia_semana, d.hora_inicio, d.hora_fin
         FROM disponibilidad_asesorias d
         JOIN usuarios u ON u.id = d.usuario_id
         WHERE u.activo = 1
         ORDER BY d.dia_semana, d.hora_inicio"
    );
    $bloquesPorDia = [];
    foreach ($stmt->fetchAll() as $r) {
        $bloquesPorDia[(int)$r['dia_semana']][] = $r;
    }
    if (!$bloquesPorDia) return [];

    $stmt = $pdo->prepare(
        "SELECT usuario_id, fecha, hora_inicio FROM citas_asesoria
         WHERE estado IN ('confirmada', 'pendiente_pago')"
    );
    $stmt->execute();
    $ocupados = [];
    foreach ($stmt->fetchAll() as $r) {
        $ocupados[$r['usuario_id'] . '|' . $r['fecha'] . '|' . substr($r['hora_inicio'], 0, 5)] = true;
    }

    $ahora = new DateTimeImmutable();
    $limiteMinimo = $ahora->modify("+{$anticipacionMinimaHoras} hours");
    $hoy = new DateTimeImmutable('today');
    $opciones = [];

    for ($i = 0; $i < $diasAdelante; $i++) {
        $fechaObj = $hoy->modify("+{$i} day");
        $diaSemana = (int)$fechaObj->format('N');
        if (empty($bloquesPorDia[$diaSemana])) continue;
        $fechaStr = $fechaObj->format('Y-m-d');
        $vistosEsteDia = [];

        foreach ($bloquesPorDia[$diaSemana] as $bloque) {
            $cursor = DateTimeImmutable::createFromFormat('H:i:s', $bloque['hora_inicio']);
            $finBloque = DateTimeImmutable::createFromFormat('H:i:s', $bloque['hora_fin']);
            while (true) {
                $finSlot = $cursor->modify('+1 hour');
                if ($finSlot > $finBloque) break;
                $horaSlot = $cursor->format('H:i');
                if (!isset($vistosEsteDia[$horaSlot])) {
                    $slotDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$fechaStr $horaSlot");
                    $libre = !isset($ocupados[$bloque['usuario_id'] . '|' . $fechaStr . '|' . $horaSlot])
                        && $slotDateTime >= $limiteMinimo;
                    if ($libre) {
                        $vistosEsteDia[$horaSlot] = true;
                        $opciones[] = [
                            'fecha' => $fechaStr,
                            'hora_inicio' => $horaSlot,
                            'hora_fin' => $finSlot->format('H:i'),
                            'texto' => citas_formatear_fecha_hora($fechaStr, $horaSlot),
                        ];
                        if (count($opciones) >= $maxOpciones) return $opciones;
                    }
                }
                $cursor = $cursor->modify('+1 hour');
            }
        }
    }

    return $opciones;
}

/**
 * Aparta un horario (fecha + hora_inicio, ya validados contra lo que se le
 * ofreció al cliente) asignando el primer abogado que de verdad siga
 * libre en ese momento — se vuelve a checar aquí por si dos clientes
 * eligen el mismo horario casi al mismo tiempo. Crea la cita en
 * 'pendiente_pago' y regresa su id, o null si ya nadie está libre en ese
 * horario (hay que ofrecer otros).
 */
function citas_crear_pendiente(PDO $pdo, string $telefono, string $fecha, string $horaInicio, ?string $nombreCliente): ?int
{
    citas_expirar_pendientes_vencidas($pdo);

    $fechaObj = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$fechaObj) return null;
    $diaSemana = (int)$fechaObj->format('N');

    // :hora_a y :hora_b llevan el mismo valor — con
    // PDO::ATTR_EMULATE_PREPARES en false (como usa este sistema, ver
    // db.php) no se puede reutilizar el mismo parámetro con nombre dos
    // veces en la misma consulta.
    $stmt = $pdo->prepare(
        "SELECT d.usuario_id, d.hora_fin
         FROM disponibilidad_asesorias d
         JOIN usuarios u ON u.id = d.usuario_id
         WHERE u.activo = 1 AND d.dia_semana = :dia
           AND d.hora_inicio <= :hora_a AND d.hora_fin >= ADDTIME(:hora_b, '01:00:00')
         ORDER BY d.usuario_id"
    );
    $stmt->execute([':dia' => $diaSemana, ':hora_a' => $horaInicio . ':00', ':hora_b' => $horaInicio . ':00']);
    $candidatos = $stmt->fetchAll();
    if (!$candidatos) return null;

    $stmt = $pdo->prepare(
        "SELECT usuario_id FROM citas_asesoria
         WHERE fecha = :fecha AND hora_inicio = :hora AND estado IN ('confirmada', 'pendiente_pago')"
    );
    $stmt->execute([':fecha' => $fecha, ':hora' => $horaInicio . ':00']);
    $ocupadosEnEsteSlot = array_column($stmt->fetchAll(), 'usuario_id');

    $usuarioLibre = null;
    foreach ($candidatos as $c) {
        if (!in_array($c['usuario_id'], $ocupadosEnEsteSlot)) {
            $usuarioLibre = $c;
            break;
        }
    }
    if (!$usuarioLibre) return null;

    $horaFin = (new DateTimeImmutable($horaInicio))->modify('+1 hour')->format('H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO citas_asesoria (telefono, usuario_id, fecha, hora_inicio, hora_fin, nombre_cliente, estado)
         VALUES (:telefono, :usuario_id, :fecha, :hora_inicio, :hora_fin, :nombre, 'pendiente_pago')"
    );
    $stmt->execute([
        ':telefono' => $telefono,
        ':usuario_id' => $usuarioLibre['usuario_id'],
        ':fecha' => $fecha,
        ':hora_inicio' => $horaInicio . ':00',
        ':hora_fin' => $horaFin,
        ':nombre' => $nombreCliente,
    ]);

    return (int)$pdo->lastInsertId();
}
