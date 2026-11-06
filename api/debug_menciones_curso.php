<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: cuenta cuántas conversaciones de WhatsApp
// mencionaron la palabra "curso" (probable interés en los cursos en
// línea del despacho), para tener un número aproximado de cuánta gente
// ha preguntado — no hay ningún registro estructurado de esto todavía
// (a diferencia de los prospectos de despido/asesoría paga). Excluye
// palabras comunes que contienen "curso" pero no tienen nada que ver
// (recurso, concurso, discurso) para no inflar el conteo. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$condicion = "direccion = 'entrante'
    AND texto LIKE '%curso%'
    AND texto NOT LIKE '%recurso%'
    AND texto NOT LIKE '%concurso%'
    AND texto NOT LIKE '%discurso%'";

$totales = $pdo->query(
    "SELECT COUNT(DISTINCT telefono) AS numeros, COUNT(*) AS mensajes
     FROM whatsapp_conversaciones WHERE {$condicion}"
)->fetch();

echo "Números distintos que mencionaron 'curso': " . $totales['numeros'] . "\n";
echo "Mensajes totales que mencionan 'curso': " . $totales['mensajes'] . "\n";
echo str_repeat('-', 70) . "\n\n";

$stmt = $pdo->query(
    "SELECT telefono, texto, creado_en
     FROM whatsapp_conversaciones WHERE {$condicion}
     ORDER BY creado_en DESC"
);
foreach ($stmt->fetchAll() as $r) {
    $texto = mb_substr(str_replace(["\r", "\n"], ' ', $r['texto']), 0, 140);
    echo $r['creado_en'] . "  " . $r['telefono'] . "\n  \"" . $texto . "\"\n\n";
}
