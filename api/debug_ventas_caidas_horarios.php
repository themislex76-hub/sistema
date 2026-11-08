<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: cuenta cuántos prospectos mostraron interés
// real en la asesoría de pago pero se encontraron con que el sistema NO
// tenía NINGÚN horario disponible (ver ia_resultado_ofrecer_horarios en
// ia_helpers.php) -- ese caso siempre deja un registro en prospectos con
// un resumen_caso reconocible.
//
// OJO -- esto es una COTA MÍNIMA, no el conteo exacto: prospectos se
// actualiza con UPSERT por teléfono (ON DUPLICATE KEY UPDATE), así que
// (1) si el mismo número se topó con "sin horarios" varias veces, solo
// cuenta una vez, y (2) si ese mismo número luego volvió por otro motivo
// y su resumen_caso se sobrescribió, este conteo ya no lo ve -- el
// número real de ventas perdidas por falta de horarios es IGUAL O MAYOR
// a lo que muestra esta herramienta. Tampoco incluye el caso distinto de
// "sí había horarios pero ninguno le quedaba al cliente" -- ese no deja
// ningún registro en el sistema todavía.
// Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT id, telefono, nombre, estatus, creado_en, actualizado_en, resumen_caso
     FROM prospectos
     WHERE resumen_caso LIKE '%no hay horarios disponibles%'
     ORDER BY actualizado_en DESC"
);
$stmt->execute();
$filas = $stmt->fetchAll();

echo "=== Prospectos atorados por falta de horarios (cota mínima, ver nota arriba) ===\n";
echo count($filas) . " número(s) distinto(s) encontrado(s).\n\n";

$porEstatus = [];
$porDia = [];
foreach ($filas as $f) {
    $porEstatus[$f['estatus']] = ($porEstatus[$f['estatus']] ?? 0) + 1;
    $dia = substr((string)$f['creado_en'], 0, 10);
    $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;
}

echo "-- Por estatus actual --\n";
foreach ($porEstatus as $estatus => $n) {
    echo "  {$estatus}: {$n}\n";
}
echo "\n-- Por día de la primera vez que se registró (puede repetirse después del mismo número) --\n";
ksort($porDia);
foreach ($porDia as $dia => $n) {
    echo "  {$dia}: {$n}\n";
}

echo "\n-- Detalle (más reciente primero) --\n";
foreach ($filas as $f) {
    $nombre = $f['nombre'] ?: '(sin nombre)';
    echo "#{$f['id']} | {$f['telefono']} | {$nombre} | estatus={$f['estatus']} | visto por última vez: {$f['actualizado_en']}\n";
}

if (!$filas) {
    echo "(ninguno encontrado)\n";
}
