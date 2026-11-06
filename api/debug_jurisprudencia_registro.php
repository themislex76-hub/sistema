<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: revisa si un registro digital específico ya está
// guardado en la biblioteca de jurisprudencia, y si sí, muestra su rubro
// completo tal cual quedó -- para diagnosticar si el buscador con IA se lo
// está pasando por alto o si de plano todavía no se ha cargado. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$registro = (int)($_GET['registro'] ?? 0);
if ($registro <= 0) fail('Falta el parámetro ?registro=NNNNNNN en la URL.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM jurisprudencia_tesis WHERE registro_digital = :r');
$stmt->execute([':r' => $registro]);
$t = $stmt->fetch();

if (!$t) {
    echo "NO está en la biblioteca todavía el registro {$registro}.\n";
    exit;
}

echo "SÍ está en la biblioteca:\n\n";
echo "Registro digital: {$t['registro_digital']}\n";
echo "Rubro completo: {$t['rubro']}\n";
echo 'Instancia: ' . ($t['instancia'] ?: '(sin dato)') . "\n";
echo 'Época: ' . ($t['epoca'] ?: '(sin dato)') . "\n";
echo 'Fecha de publicación: ' . ($t['fecha_publicacion'] ?: '(sin dato)') . "\n";
echo "\nLargo del rubro: " . mb_strlen((string)$t['rubro']) . " caracteres.\n";
echo "Largo del texto completo: " . mb_strlen((string)$t['texto_completo']) . " caracteres.\n";
echo "\n=== Texto completo tal como está guardado (fuente: SCJN) ===\n\n";
echo $t['texto_completo'] . "\n";
