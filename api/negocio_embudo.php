<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Embudo completo del negocio para un periodo (solo Administrador):
//   1. Espectadores del live de TikTok (capturado a mano, tabla tiktok_lives)
//   2. Conversaciones NUEVAS de WhatsApp (números que escribieron por
//      primera vez en la historia dentro del periodo)
//   3. Pagaron asesoría (citas_asesoria confirmadas, por fecha de pago) --
//      más documentos ($49) y cursos como ingreso adicional
//   4. Se volvieron juicio: de las personas que pagaron asesoría en el
//      periodo, cuántas tienen un expediente dado de alta DESPUÉS de su
//      pago con el mismo teléfono (últimos 10 dígitos). expedientes.telefono
//      es texto libre y puede traer varios números, así que se cruza en PHP;
//      también cuenta prospectos.expediente_id (prospecto convertido).
// Todos los que escriben vienen del live (confirmado por el usuario, 16 nov
// 2026), por eso no se filtra ni se pregunta el origen.
//
// "Jornada del live": el live empieza ~8 pm; la gente escribe durante el
// live y al día siguiente (el bot contesta fuera de horario a partir de las
// 8 am) y paga al día siguiente. Por eso TODO lo de WhatsApp y los pagos se
// asigna a la jornada que va de las 20:00 del día D a las 19:59 del día D+1,
// y se compara contra el live del día D (pedido del usuario, 16 nov 2026).
// La hora del servidor ya es la de México (db.php).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();

$hoy = date('Y-m-d');
$desde = (string)($_GET['desde'] ?? '');
$hasta = (string)($_GET['hasta'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = $hoy;
if ($desde < '2020-01-01') $desde = '2020-01-01';
if ($hasta > $hoy) $hasta = $hoy;
if ($hasta < $desde) $hasta = $desde;
$hastaExclusiva = date('Y-m-d', strtotime($hasta . ' +1 day'));
$rango = [':desde' => $desde, ':hasta' => $hastaExclusiva];
const NEGOCIO_HORA_CORTE_LIVE = 20;
$corte = sprintf(' %02d:00:00', NEGOCIO_HORA_CORTE_LIVE);
$rangoLive = [':desde' => $desde . $corte, ':hasta' => $hastaExclusiva . $corte];

// Día del live al que pertenece un momento (jornada de 20:00 a 19:59 del día siguiente).
function negocio_jornada(string $ts): string
{
    return date('Y-m-d', strtotime($ts) - NEGOCIO_HORA_CORTE_LIVE * 3600);
}

// Clave de cruce: 't' + últimos 10 dígitos. La 't' es a propósito: una clave
// de arreglo PHP que es solo dígitos se convierte en int y rompe los tipos.
function negocio_tel_clave(?string $telefono): string
{
    $dig = preg_replace('/\D/', '', (string)$telefono);
    return strlen($dig) >= 10 ? 't' . substr($dig, -10) : '';
}

// Clave de cruce de cada número de teléfono que aparezca en un texto.
function negocio_telefonos10(?string $texto): array
{
    if ($texto === null || $texto === '') return [];
    $out = [];
    // Un número puede venir con espacios/guiones/paréntesis entre dígitos.
    if (preg_match_all('/\+?\d[\d\s\-\(\)\.]{8,}\d/', $texto, $m)) {
        foreach ($m[0] as $crudo) {
            $k = negocio_tel_clave($crudo);
            if ($k !== '') $out[$k] = true;
        }
    }
    return array_keys($out);
}

$porDia = [];
$cursor = new DateTimeImmutable($desde);
$fin = new DateTimeImmutable($hasta);
while ($cursor <= $fin) {
    $porDia[$cursor->format('Y-m-d')] = [
        'dia' => $cursor->format('Y-m-d'),
        'live_capturado' => false,
        'visualizaciones' => 0,
        'espectadores_unicos' => 0,
        'espectadores_activos' => 0,
        'conversaciones_nuevas' => 0,
        'asesorias_pagadas' => 0,
        'documentos_pagados' => 0,
        'cursos_pagados' => 0,
    ];
    $cursor = $cursor->modify('+1 day');
}

// ---------- 1. Lives de TikTok ----------
$lives = [];
$tablaLivesExiste = true;
try {
    $st = $pdo->prepare(
        "SELECT fecha, visualizaciones, espectadores_unicos, espectadores_activos, duracion_media_min,
                pico_simultaneos, media_simultaneos, duracion_live_min, notas
         FROM tiktok_lives WHERE fecha >= :desde AND fecha < :hasta ORDER BY fecha DESC"
    );
    $st->execute($rango);
    $lives = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tablaLivesExiste = false; // migración 073 todavía no corrida
}
$tot = ['visualizaciones' => 0, 'espectadores_unicos' => 0, 'espectadores_activos' => 0,
        'pico_simultaneos' => 0, 'dias_capturados' => 0];
foreach ($lives as &$l) {
    foreach (['visualizaciones', 'espectadores_unicos', 'espectadores_activos', 'pico_simultaneos', 'media_simultaneos', 'duracion_live_min'] as $c) {
        $l[$c] = $l[$c] !== null ? (int)$l[$c] : null;
    }
    $l['duracion_media_min'] = $l['duracion_media_min'] !== null ? (float)$l['duracion_media_min'] : null;
    $d = $l['fecha'];
    if (isset($porDia[$d])) {
        $porDia[$d]['live_capturado'] = true;
        $porDia[$d]['visualizaciones'] = $l['visualizaciones'];
        $porDia[$d]['espectadores_unicos'] = $l['espectadores_unicos'];
        $porDia[$d]['espectadores_activos'] = $l['espectadores_activos'];
    }
    $tot['visualizaciones'] += $l['visualizaciones'];
    $tot['espectadores_unicos'] += $l['espectadores_unicos'];
    $tot['espectadores_activos'] += $l['espectadores_activos'];
    $tot['pico_simultaneos'] = max($tot['pico_simultaneos'], (int)$l['pico_simultaneos']);
    $tot['dias_capturados']++;
}
unset($l);

// ---------- 2. Conversaciones de WhatsApp ----------
// Nuevas = el primer mensaje entrante de ese número en toda la historia cae
// dentro del periodo. (Sin funciones de ventana: el MySQL del servidor no
// las soporta.)
$st = $pdo->prepare(
    "SELECT primero FROM (
        SELECT telefono, MIN(creado_en) AS primero
        FROM whatsapp_conversaciones WHERE direccion = 'entrante'
        GROUP BY telefono
     ) t
     WHERE primero >= :desde AND primero < :hasta"
);
$st->execute($rangoLive);
$convNuevas = 0;
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $primero) {
    $dia = negocio_jornada((string)$primero);
    if (isset($porDia[$dia])) $porDia[$dia]['conversaciones_nuevas']++;
    $convNuevas++;
}
$st = $pdo->prepare(
    "SELECT COUNT(DISTINCT telefono) FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= :desde AND creado_en < :hasta"
);
$st->execute($rangoLive);
$convTotales = (int)$st->fetchColumn();

// ---------- 3. Pagos ----------
$st = $pdo->prepare(
    "SELECT telefono, nombre_cliente, monto, pagado_en
     FROM citas_asesoria
     WHERE estado = 'confirmada' AND pagado_en >= :desde AND pagado_en < :hasta
     ORDER BY pagado_en"
);
$st->execute($rangoLive);
$citas = $st->fetchAll(PDO::FETCH_ASSOC);
$ingresoAsesorias = 0.0;
$personasPagaron = []; // tel10 => primer pago del periodo
foreach ($citas as $c) {
    $ingresoAsesorias += (float)$c['monto'];
    $diaC = negocio_jornada((string)$c['pagado_en']);
    if (isset($porDia[$diaC])) $porDia[$diaC]['asesorias_pagadas']++;
    $t10 = negocio_tel_clave($c['telefono']);
    if ($t10 === '') continue;
    if (!isset($personasPagaron[$t10])) {
        $personasPagaron[$t10] = ['nombre' => $c['nombre_cliente'], 'pagado_en' => $c['pagado_en']];
    }
}

// Clientes que repiten: ya habían pagado una asesoría ANTES del periodo
// (algunos clientes piden asesoría varias veces). Las que pagaron 2+ veces
// dentro del mismo periodo ya se ven en pagos > personas.
$personasRepiten = 0;
if ($personasPagaron) {
    $st = $pdo->prepare(
        "SELECT DISTINCT telefono FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en < :desde"
    );
    $st->execute([':desde' => $rangoLive[':desde']]);
    $antes = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tel) {
        $k = negocio_tel_clave($tel);
        if ($k !== '') $antes[$k] = true;
    }
    foreach ($personasPagaron as $k => $_) {
        if (isset($antes[$k])) $personasRepiten++;
    }
}
$pagosPorPersona = [];
foreach ($citas as $c) {
    $k = negocio_tel_clave($c['telefono']);
    if ($k !== '') $pagosPorPersona[$k] = ($pagosPorPersona[$k] ?? 0) + 1;
}
$personasVariasEnPeriodo = count(array_filter($pagosPorPersona, static fn($n) => $n > 1));

$extras = [];
foreach (['documentos' => 'compras_documento_calculo', 'cursos' => 'compras_curso'] as $clave => $tabla) {
    $extras[$clave] = ['cantidad' => 0, 'ingreso' => 0.0];
    try {
        $st = $pdo->prepare(
            "SELECT pagado_en, monto FROM $tabla
             WHERE estado = 'confirmada' AND pagado_en >= :desde AND pagado_en < :hasta"
        );
        $st->execute($rangoLive);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dia = negocio_jornada((string)$r['pagado_en']);
            if (isset($porDia[$dia])) $porDia[$dia][$clave . '_pagados']++;
            $extras[$clave]['cantidad']++;
            $extras[$clave]['ingreso'] += (float)$r['monto'];
        }
    } catch (PDOException $e) {
        // tabla inexistente en alguna instalación: se deja en 0
    }
}

// ---------- 4. Juicios ----------
// Mapa tel10 => expedientes (de expedientes.telefono y de prospectos convertidos).
$expPorTel = [];
$agregarExp = static function (string $t10, array $e) use (&$expPorTel): void {
    $expPorTel[$t10][$e['id']] = $e;
};
if ($personasPagaron) {
    $st = $pdo->query(
        "SELECT id, actor, demandado, exp, telefono, creado_en FROM expedientes
         WHERE telefono IS NOT NULL AND telefono <> ''"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        foreach (negocio_telefonos10($e['telefono']) as $t10) {
            if (isset($personasPagaron[$t10])) $agregarExp($t10, $e);
        }
    }
    $st = $pdo->query(
        "SELECT p.telefono AS tel_prospecto, e.id, e.actor, e.demandado, e.exp, e.telefono, e.creado_en
         FROM prospectos p JOIN expedientes e ON e.id = p.expediente_id"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $t10 = negocio_tel_clave($e['tel_prospecto']);
        if (isset($personasPagaron[$t10])) $agregarExp($t10, $e);
    }
}
$juicios = [];
foreach ($personasPagaron as $t10 => $p) {
    foreach ($expPorTel[$t10] ?? [] as $e) {
        // Solo cuenta si el expediente se dio de alta el mismo día del pago o después.
        if (substr((string)$e['creado_en'], 0, 10) < substr((string)$p['pagado_en'], 0, 10)) continue;
        $juicios[] = [
            'expediente_id' => (int)$e['id'],
            'actor' => $e['actor'],
            'demandado' => $e['demandado'],
            'exp' => $e['exp'],
            'asesoria_pagada_en' => $p['pagado_en'],
            'expediente_creado_en' => $e['creado_en'],
            'dias_entre' => (int)floor((strtotime((string)$e['creado_en']) - strtotime(substr((string)$p['pagado_en'], 0, 10))) / 86400),
        ];
        break; // una persona = un juicio para el embudo
    }
}

respond([
    'desde' => $desde,
    'hasta' => $hasta,
    'tabla_lives_existe' => $tablaLivesExiste,
    'jornada_desde' => $rangoLive[':desde'],
    'jornada_hasta' => $rangoLive[':hasta'],
    'hora_corte' => NEGOCIO_HORA_CORTE_LIVE,
    'live' => $tot,
    'lives' => $lives,
    'conversaciones_nuevas' => $convNuevas,
    'conversaciones_totales' => $convTotales,
    'asesorias' => [
        'pagos' => count($citas),
        'personas' => count($personasPagaron),
        'personas_ya_habian_pagado_antes' => $personasRepiten,
        'personas_pagaron_varias_en_periodo' => $personasVariasEnPeriodo,
        'ingreso' => round($ingresoAsesorias, 2),
    ],
    'documentos' => $extras['documentos'],
    'cursos' => $extras['cursos'],
    'juicios' => $juicios,
    'por_dia' => array_values($porDia),
]);
