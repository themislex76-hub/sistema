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
// Si el día D no hubo live (ej. sábado y domingo: el live del viernes genera
// ventas el fin de semana), esa jornada se le cuenta al último live capturado
// -- "todo lo que llega entre un live y el siguiente es del live anterior" --
// hasta NEGOCIO_DIAS_ARRASTRE días atrás; más allá (o si no hay lives
// capturados) se queda en su propio día.
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
const NEGOCIO_DIAS_ARRASTRE = 3;
$rangoLive = [':desde' => $desde . $corte, ':hasta' => $hastaExclusiva . $corte];
// Ventana de búsqueda: lo de los días siguientes a 'hasta' puede caer en un
// live de 'hasta' por el arrastre (ej. periodo que termina en viernes).
$rangoBusqueda = [
    ':desde' => $rangoLive[':desde'],
    ':hasta' => date('Y-m-d', strtotime($hastaExclusiva . ' +' . NEGOCIO_DIAS_ARRASTRE . ' days')) . $corte,
];

// Fechas de todos los lives capturados (para el arrastre de días sin live).
$NEGOCIO_FECHAS_LIVE = [];
try {
    $NEGOCIO_FECHAS_LIVE = array_flip($pdo->query('SELECT fecha FROM tiktok_lives')->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    // migración 073 todavía no corrida
}

// Jornada de un momento: de 20:00 del día D a 19:59 del día D+1 → D.
function negocio_jornada(string $ts): string
{
    return date('Y-m-d', strtotime($ts) - NEGOCIO_HORA_CORTE_LIVE * 3600);
}

// Live al que se le cuenta una jornada: el mismo día si hubo live; si no,
// el último live capturado dentro de NEGOCIO_DIAS_ARRASTRE días atrás.
function negocio_dia_live_de_jornada(string $dia): string
{
    global $NEGOCIO_FECHAS_LIVE;
    if (!$NEGOCIO_FECHAS_LIVE || isset($NEGOCIO_FECHAS_LIVE[$dia])) return $dia;
    for ($i = 1; $i <= NEGOCIO_DIAS_ARRASTRE; $i++) {
        $antes = date('Y-m-d', strtotime($dia . " -$i day"));
        if (isset($NEGOCIO_FECHAS_LIVE[$antes])) return $antes;
    }
    return $dia;
}

function negocio_dia_live(string $ts): string
{
    return negocio_dia_live_de_jornada(negocio_jornada($ts));
}

// Canal de llegada según el mensaje ya escrito del link de WhatsApp de cada
// canal (acordado con el usuario el 16 nov 2026; número del bot 56 5273 6263):
//   "Hola, quiero orientación gratis"   -> perfil de TikTok / lives
//   "Hola, vi su video"                 -> videos (Facebook/Instagram/YouTube)
//   "Hola, vengo de su página web"      -> página web
//   "Hola, los encontré en Google"      -> Google Maps
// Cualquier otro primer mensaje = escribió directo (antes de los links,
// prácticamente todo venía del live).
const NEGOCIO_CANALES = [
    'tiktok' => 'Perfil de TikTok / lives (link)',
    'videos' => 'Videos (Facebook / Instagram / YouTube)',
    'web' => 'Página web',
    'google' => 'Google Maps',
    'directo' => 'Escribió directo (sin link)',
];
function negocio_canal(string $texto): string
{
    $t = mb_strtolower($texto, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    if (str_contains($t, 'vi su video')) return 'videos';
    if (str_contains($t, 'pagina web')) return 'web';
    if (str_contains($t, 'encontre en google')) return 'google';
    if (str_contains($t, 'orientacion gratis')) return 'tiktok';
    return 'directo';
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
// dentro del periodo. El primer mensaje (MIN(id), los ids son crecientes)
// también dice de qué canal llegó: cada canal tiene su link de WhatsApp con
// un mensaje ya escrito (ver negocio_canal()). Sin funciones de ventana: el
// MySQL del servidor no las soporta.
$st = $pdo->prepare(
    "SELECT w.telefono, w.texto, w.creado_en
     FROM whatsapp_conversaciones w
     JOIN (SELECT MIN(id) AS mid FROM whatsapp_conversaciones
           WHERE direccion = 'entrante' GROUP BY telefono) f ON f.mid = w.id
     WHERE w.creado_en >= :desde AND w.creado_en < :hasta"
);
$st->execute($rangoBusqueda);
$convNuevas = 0;
$nuevasPorCanal = []; // canal => [tel10 => true]
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dia = negocio_dia_live((string)$r['creado_en']);
    if (!isset($porDia[$dia])) continue;
    $porDia[$dia]['conversaciones_nuevas']++;
    $convNuevas++;
    $k = negocio_tel_clave($r['telefono']);
    $nuevasPorCanal[negocio_canal((string)$r['texto'])][$k !== '' ? $k : 'x' . $convNuevas] = true;
}
// Personas distintas que escribieron. Se agrupa por teléfono + día + si fue
// antes o después del corte de las 8 pm (máx. 2 filas por número por día),
// para no traer cada mensaje suelto.
$st = $pdo->prepare(
    "SELECT telefono, DATE(creado_en) AS d, (HOUR(creado_en) >= " . NEGOCIO_HORA_CORTE_LIVE . ") AS tarde
     FROM whatsapp_conversaciones
     WHERE direccion = 'entrante' AND creado_en >= :desde AND creado_en < :hasta
     GROUP BY telefono, DATE(creado_en), (HOUR(creado_en) >= " . NEGOCIO_HORA_CORTE_LIVE . ")"
);
$st->execute($rangoBusqueda);
$telefonosEscribieron = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $jornada = (int)$r['tarde'] ? (string)$r['d'] : date('Y-m-d', strtotime($r['d'] . ' -1 day'));
    if (isset($porDia[negocio_dia_live_de_jornada($jornada)])) $telefonosEscribieron[(string)$r['telefono']] = true;
}
$convTotales = count($telefonosEscribieron);

// ---------- 3. Pagos ----------
$st = $pdo->prepare(
    "SELECT telefono, nombre_cliente, monto, pagado_en
     FROM citas_asesoria
     WHERE estado = 'confirmada' AND pagado_en >= :desde AND pagado_en < :hasta
     ORDER BY pagado_en"
);
$st->execute($rangoBusqueda);
$citas = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $c['dia_live'] = negocio_dia_live((string)$c['pagado_en']);
    if (isset($porDia[$c['dia_live']])) $citas[] = $c;
}
$ingresoAsesorias = 0.0;
$personasPagaron = []; // tel10 => primer pago del periodo
foreach ($citas as $c) {
    $ingresoAsesorias += (float)$c['monto'];
    $porDia[$c['dia_live']]['asesorias_pagadas']++;
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
        $st->execute($rangoBusqueda);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dia = negocio_dia_live((string)$r['pagado_en']);
            if (!isset($porDia[$dia])) continue;
            $porDia[$dia][$clave . '_pagados']++;
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
    'dias_arrastre' => NEGOCIO_DIAS_ARRASTRE,
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
    'canales' => (static function () use ($nuevasPorCanal, $personasPagaron): array {
        $out = [];
        foreach (NEGOCIO_CANALES as $clave => $nombre) {
            $tels = $nuevasPorCanal[$clave] ?? [];
            $pagaron = 0;
            foreach ($tels as $k => $_) {
                if (isset($personasPagaron[$k])) $pagaron++;
            }
            $out[] = ['canal' => $clave, 'nombre' => $nombre, 'conversaciones_nuevas' => count($tels), 'pagaron_asesoria' => $pagaron];
        }
        return $out;
    })(),
    'por_dia' => array_values($porDia),
]);
