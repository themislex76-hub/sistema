<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';
require_once __DIR__ . '/anthropic_admin_helpers.php';
if (file_exists(__DIR__ . '/anthropic_credentials.php')) require_once __DIR__ . '/anthropic_credentials.php';

// Panel de Costos vs. Ingresos -- ahora automático: todo se calcula solo
// a partir de datos reales (Mercado Pago, WhatsApp, hosting fijo), salvo
// el costo de IA, que se queda como el único dato manual porque la Admin
// API de Anthropic rechaza la llave de este plan (403 permission_error,
// ver api/anthropic_admin_debug.log) -- si algún día se resuelve, este
// archivo ya está listo para usarla sin tocar nada más. Solo
// Administrador, es información financiera del despacho completo.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

// Hosting real (DonWeb), confirmado con el usuario: $1,994.53 MXN/año
// (dominio .COM + SSL + reporte de seguridad + WordPress stage + copia
// de seguridad diaria 25GB + plan de hosting+correos), sin contar el
// dominio .mx ni el servidor del otro producto (Control de Expedientes).
const HOSTING_MENSUAL_MXN = 166.21;

// WhatsApp Business: 1,000 mensajes de servicio gratis al mes por número
// de negocio, luego ~$0.1565 MXN c/u (tarifa de categoría Servicio,
// la que usa casi todo el volumen real del sistema).
const WHATSAPP_MENSAJES_GRATIS = 1000;
const WHATSAPP_COSTO_POR_MENSAJE_MXN = 0.1565;

// Tipo de cambio usado solo para convertir el costo de IA (en USD, vía
// Anthropic) a MXN cuando la Admin API sí funcione. Aproximado -- no hay
// una fuente en vivo en el sistema.
const TIPO_CAMBIO_USD_MXN = 18.7;

$pdo = db();

// 1) Ingresos de asesorías.
$asesoriasPorMes = $pdo->query(
    "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidas, SUM(monto) AS total
     FROM citas_asesoria WHERE estado = 'confirmada' AND pagado_en IS NOT NULL
     GROUP BY mes"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

// 2) Ingresos de documentos membretados ($49).
$documentosPorMes = $pdo->query(
    "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidos, SUM(monto) AS total
     FROM compras_documento_calculo WHERE estado = 'confirmada' AND pagado_en IS NOT NULL
     GROUP BY mes"
)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

// 3) Ingresos de cursos -- se consulta Mercado Pago en vivo (no hay tabla
// propia). De paso se junta la comisión REAL que Mercado Pago se quedó de
// CADA pago (fee_details): cursos por el texto de la descripción,
// asesorías y documentos por su mp_payment_id exacto (ya guardado en cada
// tabla desde su respectivo webhook de pago) -- así nunca se mezcla con
// comisión de compras personales que comparten la misma cuenta de MP.
$cursosPorMes = [];
$comisionMpPorMes = [];
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
        $comisionMpPorMes[$mes] = ($comisionMpPorMes[$mes] ?? 0) + $p['comision'];
    }

    $citasConPago = $pdo->query(
        "SELECT mp_payment_id, pagado_en FROM citas_asesoria WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL"
    )->fetchAll();
    foreach ($citasConPago as $cita) {
        $pid = (string)$cita['mp_payment_id'];
        if (!isset($pagosPorId[$pid])) continue;
        $mes = substr((string)$cita['pagado_en'], 0, 7);
        $comisionMpPorMes[$mes] = ($comisionMpPorMes[$mes] ?? 0) + $pagosPorId[$pid]['comision'];
    }

    $documentosConPago = $pdo->query(
        "SELECT mp_payment_id, pagado_en FROM compras_documento_calculo WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL"
    )->fetchAll();
    foreach ($documentosConPago as $doc) {
        $pid = (string)$doc['mp_payment_id'];
        if (!isset($pagosPorId[$pid])) continue;
        $mes = substr((string)$doc['pagado_en'], 0, 7);
        $comisionMpPorMes[$mes] = ($comisionMpPorMes[$mes] ?? 0) + $pagosPorId[$pid]['comision'];
    }
}

// 4) WhatsApp: mensajes salientes reales por mes, para estimar el costo
// por arriba de los 1,000 gratis.
$whatsappEnviadosPorMes = $pdo->query(
    "SELECT DATE_FORMAT(creado_en, '%Y-%m') AS mes, COUNT(*) AS enviados
     FROM whatsapp_conversaciones WHERE direccion = 'saliente'
     GROUP BY mes"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// 5) Costo de IA capturado a mano (único dato manual que queda, mientras
// la Admin API de Anthropic no funcione para este plan).
$costoIaManualPorMes = $pdo->query(
    "SELECT mes, costo_ia FROM costos_mensuales"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$iaAdminDisponible = defined('ANTHROPIC_ADMIN_API_KEY');

// 6) Se combinan todos los meses que aparezcan en cualquier fuente.
$todosLosMeses = array_unique(array_merge(
    array_keys($asesoriasPorMes),
    array_keys($documentosPorMes),
    array_keys($cursosPorMes),
    array_keys($whatsappEnviadosPorMes),
    array_keys($costoIaManualPorMes)
));
rsort($todosLosMeses);

$meses = [];
foreach ($todosLosMeses as $mes) {
    $ingresoAsesorias = isset($asesoriasPorMes[$mes]) ? (float)$asesoriasPorMes[$mes]['total'] : 0.0;
    $ingresoDocumentos = isset($documentosPorMes[$mes]) ? (float)$documentosPorMes[$mes]['total'] : 0.0;
    $ingresoCursos = $cursosPorMes[$mes] ?? 0.0;
    $ingresosTotales = $ingresoAsesorias + $ingresoDocumentos + $ingresoCursos;

    $comisionMp = $mpDisponible ? round($comisionMpPorMes[$mes] ?? 0.0, 2) : null;

    $enviados = (int)($whatsappEnviadosPorMes[$mes] ?? 0);
    $costoWhatsapp = round(max(0, $enviados - WHATSAPP_MENSAJES_GRATIS) * WHATSAPP_COSTO_POR_MENSAJE_MXN, 2);

    $costoHosting = HOSTING_MENSUAL_MXN;

    $costoIaUsd = $iaAdminDisponible ? anthropic_costo_mensual_usd($mes) : null;
    if ($costoIaUsd !== null) {
        $costoIa = round($costoIaUsd * TIPO_CAMBIO_USD_MXN, 2);
        $costoIaFuente = 'api';
    } elseif (isset($costoIaManualPorMes[$mes])) {
        $costoIa = (float)$costoIaManualPorMes[$mes];
        $costoIaFuente = 'manual';
    } else {
        $costoIa = null;
        $costoIaFuente = null;
    }

    $costosConocidos = $costoHosting + $costoWhatsapp + ($comisionMp ?? 0.0) + ($costoIa ?? 0.0);
    $costosCompletos = $mpDisponible && $costoIa !== null;
    $margen = $ingresosTotales - $costosConocidos;

    $meses[] = [
        'mes' => $mes,
        'ingreso_asesorias' => $ingresoAsesorias,
        'asesorias_vendidas' => isset($asesoriasPorMes[$mes]) ? (int)$asesoriasPorMes[$mes]['vendidas'] : 0,
        'ingreso_documentos' => $ingresoDocumentos,
        'documentos_vendidos' => isset($documentosPorMes[$mes]) ? (int)$documentosPorMes[$mes]['vendidos'] : 0,
        'ingreso_cursos' => $ingresoCursos,
        'ingresos_totales' => $ingresosTotales,
        'costo_hosting' => $costoHosting,
        'whatsapp_enviados' => $enviados,
        'costo_whatsapp' => $costoWhatsapp,
        'comision_mercadopago' => $comisionMp,
        'costo_ia' => $costoIa,
        'costo_ia_fuente' => $costoIaFuente,
        'costos_totales' => $costosConocidos,
        'costos_completos' => $costosCompletos,
        'margen' => $margen,
        'margen_pct' => $ingresosTotales > 0 ? round($margen / $ingresosTotales * 100, 1) : null,
    ];
}

respond(['meses' => $meses, 'mp_disponible' => $mpDisponible, 'ia_admin_disponible' => $iaAdminDisponible]);
