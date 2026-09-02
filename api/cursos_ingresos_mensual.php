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
// Mercado Pago del despacho.
//
// OJO: esa misma cuenta de Mercado Pago se usa para TODO -- asesorías
// (con links generados a mano desde hace años, con títulos libres tipo
// "55 1234 5678 asesoría link de pago"), cálculos sueltos, la suscripción
// de Control de Expedientes, y hasta compras/ventas personales de
// Mercado Libre sin relación con el despacho. Por eso NO se excluye lo
// que no es asesoría (eso dejaba pasar de todo) -- al revés, solo se
// cuenta como curso lo que el título reconoce por palabra clave, todo lo
// demás simplemente no se cuenta. Solo Administrador -- es información
// financiera del despacho completo, mismo criterio que whatsapp_embudo.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

/**
 * Identifica a cuál de los 3 cursos corresponde un pago real de Mercado
 * Pago, a partir de su descripción/título -- los títulos "limpios" vienen
 * de la página de cursos (ej. "Nuevo Procedimiento Laboral Mexicano"),
 * pero también hay años de links de pago armados a mano con títulos como
 * "curso procesal link de pago" o "55 1234 5678 curso de actas". Se
 * agrupan todas las variantes bajo el nombre oficial del curso. Devuelve
 * null si el título no reconoce ningún curso (asesorías, cálculos,
 * suscripciones, compras personales, etc. -- todo eso se ignora aquí).
 */
function cursos_clasificar_titulo(string $descripcion): ?string
{
    $d = mb_strtolower($descripcion, 'UTF-8');
    if (preg_match('/amparo/u', $d)) return 'El Juicio de Amparo en Materia del Trabajo';
    if (preg_match('/\bactas?\b/u', $d)) return 'Actas Administrativas Laborales';
    if (preg_match('/procesal|procedimiento laboral/u', $d)) return 'Nuevo Procedimiento Laboral Mexicano';
    return null;
}

$hasta = new DateTimeImmutable('now');
$desde = $hasta->modify('-24 months')->modify('first day of this month')->setTime(0, 0, 0);

$pagos = mercadopago_buscar_pagos_aprobados($desde, $hasta);
if ($pagos === null) {
    fail('No se pudo consultar Mercado Pago. Revisa mercadopago_debug.log.', 502);
}

$porMes = [];
foreach ($pagos as $p) {
    if ($p['date_approved'] === '') continue;
    $curso = cursos_clasificar_titulo($p['description']);
    if ($curso === null) continue;

    $fecha = new DateTimeImmutable($p['date_approved']);
    $mes = $fecha->format('Y-m');

    if (!isset($porMes[$mes])) $porMes[$mes] = [];
    if (!isset($porMes[$mes][$curso])) $porMes[$mes][$curso] = ['vendidos' => 0, 'total' => 0.0];
    $porMes[$mes][$curso]['vendidos']++;
    $porMes[$mes][$curso]['total'] += $p['transaction_amount'];
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
