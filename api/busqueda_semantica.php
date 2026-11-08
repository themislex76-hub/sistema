<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// Búsqueda de expedientes con lenguaje natural (ver ia_buscar_expedientes()
// en ia_helpers.php). El frontend ya arma, por cada expediente visible para
// el usuario en sesión, un resumen de texto con solo los campos ya
// capturados (resumenBusquedaCaso() en app.js) -- este endpoint solo le
// pasa la pregunta + esos resúmenes a la IA y regresa qué expedientes
// coinciden. No hay caché: cada búsqueda es una pregunta distinta.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
$casos = is_array($in['casos'] ?? null) ? $in['casos'] : [];

if ($pregunta === '') fail('Escribe una pregunta para buscar.', 400);
if (!$casos) respond(['resultados' => []]);

// Tope defensivo -- ningún despacho tiene hoy tantos expedientes activos
// como para acercarse a esto, pero evita un prompt descontrolado si algún
// día lo tiene.
$casos = array_slice($casos, 0, 500);

$resultados = ia_buscar_expedientes($pregunta, $casos);
if ($resultados === null) fail('No se pudo completar la búsqueda. Revisa ia_debug.log.', 502);

respond(['resultados' => $resultados]);
