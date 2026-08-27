<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// Resumen semanal del despacho (ver ia_generar_resumen_semanal() en
// ia_helpers.php) -- solo para el Administrador. El frontend ya calculó
// todas las métricas (avances, riesgos, cobros de los últimos 7 días,
// metricsResumenSemanal() en app.js); este endpoint solo le pasa esos
// datos a la IA para que redacte el reporte. Se genera a mano con un
// botón, no hay caché -- es un reporte semanal, no algo que se recargue
// en cada visita al Tablero.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);
require_csrf();

$in = json_input();
$metricas = is_array($in['metricas'] ?? null) ? $in['metricas'] : [];
if (!$metricas) fail('Faltan datos para generar el resumen.', 400);

$texto = ia_generar_resumen_semanal($metricas);
if ($texto === null) fail('No se pudo generar el resumen semanal. Revisa ia_debug.log.', 502);

respond(['resumen' => $texto]);
