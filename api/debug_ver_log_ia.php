<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: muestra el contenido de ia_debug.log, donde
// ia_llamar_claude() anota el motivo exacto cuando falla una llamada a la
// API de Claude (sin saldo, credencial inválida, la API caída, etc.). Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/ia_debug.log';
if (!file_exists($logFile)) {
    echo "No existe ia_debug.log todavía — eso significa que ia_llamar_claude() no ha registrado ningún fallo.\n";
    exit;
}

// Solo las últimas ~40 líneas, para no hacer scroll infinito si lleva
// tiempo fallando.
$lineas = file($logFile, FILE_IGNORE_NEW_LINES);
$ultimas = array_slice($lineas, -40);
echo implode("\n", $ultimas) . "\n";
