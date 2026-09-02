<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Informe de cursos vendidos por mes (Nuevo Procedimiento Laboral Mexicano,
// El Juicio de Amparo en Materia del Trabajo, Actas Administrativas
// Laborales) -- a diferencia de las asesorías (ver
// asesorias_ingresos_mensual.php), los cursos se venden en la página
// aparte expertoslaborales.com/cursos y no dejan ningún registro en la
// base de datos de este sistema, así que este reporte no consulta la
// base de datos: consulta directo el historial de pagos de la cuenta de
// Mercado Pago del despacho (la misma que ya usa el sistema para las
// asesorías) y excluye los pagos de asesoría para no duplicar ese
// reporte. Solo Administrador -- es información financiera del despacho
// completo, mismo criterio que whatsapp_embudo.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify('-24 months')->modify('first day of this month')->setTime(0, 0, 0);

$pagos = mercadopago_buscar_pagos_aprobados($desde, $hasta);
if ($pagos === null) {
    fail('No se pudo consultar Mercado Pago. Revisa mercadopago_debug.log.', 502);
}

$porMes = [];
foreach ($pagos as $p) {
    // "Asesoría laboral personalizada..." ya tiene su propio reporte
    // (Ingresos por periodo -> Asesorías de pago vendidas por mes) --
    // aquí solo nos interesa lo que NO sea esa asesoría, es decir, los
    // cursos (cualquier otro título que la cuenta de Mercado Pago tenga
    // registrado).
    if (stripos($p['description'], 'Asesoría laboral personalizada') === 0) continue;
    if ($p['date_approved'] === '') continue;

    $fecha = new DateTimeImmutable($p['date_approved']);
    $mes = $fecha->format('Y-m');
    $titulo = $p['description'] !== '' ? $p['description'] : '(sin título)';

    if (!isset($porMes[$mes])) $porMes[$mes] = [];
    if (!isset($porMes[$mes][$titulo])) $porMes[$mes][$titulo] = ['vendidos' => 0, 'total' => 0.0];
    $porMes[$mes][$titulo]['vendidos']++;
    $porMes[$mes][$titulo]['total'] += $p['transaction_amount'];
}

krsort($porMes);
$meses = [];
foreach ($porMes as $mes => $cursos) {
    $totalMes = 0.0;
    $vendidosMes = 0;
    $listaCursos = [];
    foreach ($cursos as $titulo => $d) {
        $totalMes += $d['total'];
        $vendidosMes += $d['vendidos'];
        $listaCursos[] = ['titulo' => $titulo, 'vendidos' => $d['vendidos'], 'total' => $d['total']];
    }
    usort($listaCursos, static fn($a, $b) => $b['total'] <=> $a['total']);
    $meses[] = ['mes' => $mes, 'vendidos' => $vendidosMes, 'total' => $totalMes, 'cursos' => $listaCursos];
}

respond(['meses' => $meses]);
