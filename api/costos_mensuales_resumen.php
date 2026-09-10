<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Compara, mes a mes, los costos capturados a mano (IA, hosting, WhatsApp
// Business API, comisión de Mercado Pago) contra los ingresos que el
// sistema ya sabe automático (asesorías de pago + cursos vendidos) --
// para responder "¿esto vale la pena?" con números reales en vez de a
// ojo. Solo Administrador, es información financiera del despacho
// completo (mismo criterio que ingresos/cursos).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();

// 1) Costos capturados a mano.
$costosPorMes = $pdo->query(
    "SELECT mes, costo_ia, costo_hosting, costo_whatsapp, comision_mercadopago, notas
     FROM costos_mensuales ORDER BY mes DESC"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

// 2) Ingresos de asesorías (mismo criterio que asesorias_ingresos_mensual.php).
$asesoriasPorMes = $pdo->query(
    "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidas, SUM(monto) AS total
     FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en IS NOT NULL
     GROUP BY mes"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

// 3) Ingresos de cursos (mismo criterio que cursos_ingresos_mensual.php --
// se consulta Mercado Pago en vivo, no hay tabla propia). De paso, se suma
// la comisión REAL que Mercado Pago se quedó de cada curso (fee_details),
// para poder sugerir un valor automático de "comisión de Mercado Pago" en
// vez de que el Administrador tenga que adivinarlo cada mes.
$cursosPorMes = [];
$comisionMpAutoPorMes = [];
$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify('-24 months')->modify('first day of this month')->setTime(0, 0, 0);
$pagos = mercadopago_buscar_pagos_aprobados($desde, $hasta);
$mpDisponible = $pagos !== null;
$pagosPorId = [];
if ($pagos !== null) {
    foreach ($pagos as $p) {
        if ($p['id'] !== '') $pagosPorId[$p['id']] = $p;
        if ($p['date_approved'] === '') continue;
        $d = mb_strtolower($p['description'], 'UTF-8');
        $esCurso = preg_match('/amparo|\bactas?\b|procesal|procedimiento laboral/u', $d) === 1;
        if (!$esCurso) continue;
        $mes = (new DateTimeImmutable($p['date_approved']))->format('Y-m');
        $cursosPorMes[$mes] = ($cursosPorMes[$mes] ?? 0) + $p['transaction_amount'];
        $comisionMpAutoPorMes[$mes] = ($comisionMpAutoPorMes[$mes] ?? 0) + $p['comision'];
    }
}

// La comisión de las asesorías se suma aparte: se busca el pago exacto de
// cada cita confirmada por su mp_payment_id (ya guardado desde el webhook
// de pago) dentro de los mismos resultados de Mercado Pago, en vez de
// adivinar por el texto de la descripción -- así no se cuenta comisión de
// compras personales que comparten la misma cuenta de Mercado Pago.
if ($pagos !== null) {
    $citasConPago = $pdo->query(
        "SELECT mp_payment_id, pagado_en FROM citas_asesoria WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL"
    )->fetchAll();
    foreach ($citasConPago as $cita) {
        $pid = (string)$cita['mp_payment_id'];
        if (!isset($pagosPorId[$pid])) continue;
        $mes = substr((string)$cita['pagado_en'], 0, 7);
        $comisionMpAutoPorMes[$mes] = ($comisionMpAutoPorMes[$mes] ?? 0) + $pagosPorId[$pid]['comision'];
    }
}

// 4) Se combinan todos los meses que aparezcan en cualquiera de las 3
// fuentes, para no perder de vista un mes con costos pero sin ingresos
// (o viceversa).
$todosLosMeses = array_unique(array_merge(
    array_keys($costosPorMes),
    array_keys($asesoriasPorMes),
    array_keys($cursosPorMes)
));
rsort($todosLosMeses);

$meses = [];
foreach ($todosLosMeses as $mes) {
    $c = $costosPorMes[$mes] ?? null;
    $costoIa = $c ? (float)$c['costo_ia'] : 0.0;
    $costoHosting = $c ? (float)$c['costo_hosting'] : 0.0;
    $costoWhatsapp = $c ? (float)$c['costo_whatsapp'] : 0.0;
    $comisionMp = $c ? (float)$c['comision_mercadopago'] : 0.0;
    $costosTotales = $costoIa + $costoHosting + $costoWhatsapp + $comisionMp;

    $ingresoAsesorias = isset($asesoriasPorMes[$mes]) ? (float)$asesoriasPorMes[$mes]['total'] : 0.0;
    $ingresoCursos = $cursosPorMes[$mes] ?? 0.0;
    $ingresosTotales = $ingresoAsesorias + $ingresoCursos;

    $margen = $ingresosTotales - $costosTotales;

    $meses[] = [
        'mes' => $mes,
        'costo_ia' => $costoIa,
        'costo_hosting' => $costoHosting,
        'costo_whatsapp' => $costoWhatsapp,
        'comision_mercadopago' => $comisionMp,
        'costos_totales' => $costosTotales,
        'ingreso_asesorias' => $ingresoAsesorias,
        'asesorias_vendidas' => isset($asesoriasPorMes[$mes]) ? (int)$asesoriasPorMes[$mes]['vendidas'] : 0,
        'ingreso_cursos' => $ingresoCursos,
        'ingresos_totales' => $ingresosTotales,
        'margen' => $margen,
        'margen_pct' => $ingresosTotales > 0 ? round($margen / $ingresosTotales * 100, 1) : null,
        'notas' => $c['notas'] ?? null,
        'tiene_costos_capturados' => $c !== null,
        'comision_mercadopago_sugerida' => $mpDisponible ? round($comisionMpAutoPorMes[$mes] ?? 0.0, 2) : null,
    ];
}

respond(['meses' => $meses, 'mp_disponible' => $mpDisponible]);
