<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca en la biblioteca de jurisprudencia por
// palabra clave en el rubro (búsqueda simple LIKE, no FULLTEXT) -- para
// confirmar si una tesis específica ya está guardada, sin necesitar su
// número de registro exacto (a diferencia de debug_jurisprudencia_registro.php).
// También muestra, para cada resultado, la relevancia que le daría el
// mismo MATCH(rubro) AGAINST() que usa jurisprudencia_buscar.php -- para
// diagnosticar si el filtro por palabras clave la habría encontrado o no
// con un texto de búsqueda dado. Solo Administrador. Se puede borrar
// cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') fail('Falta el parámetro ?q=palabra clave en la URL.', 400);

$pdo = db();

echo "=== Búsqueda simple (LIKE) por \"{$q}\" en el rubro ===\n\n";
$stmt = $pdo->prepare(
    'SELECT registro_digital, rubro, epoca, fecha_publicacion FROM jurisprudencia_tesis
     WHERE rubro LIKE :q ORDER BY fecha_publicacion DESC LIMIT 20'
);
$stmt->execute([':q' => '%' . $q . '%']);
$filas = $stmt->fetchAll();
if (!$filas) {
    echo "NO se encontró ninguna tesis con \"{$q}\" en el rubro.\n";
} else {
    foreach ($filas as $f) {
        echo "[{$f['registro_digital']}] ({$f['fecha_publicacion']}, {$f['epoca']})\n{$f['rubro']}\n\n";
    }
}

// La búsqueda que de verdad usa el buscador barato (jurisprudencia_buscar.php)
// -- para confirmar si ESTE texto exacto la traería como candidata o no.
echo "\n=== Con MATCH(rubro) AGAINST() (lo que usa el buscador real) ===\n\n";
$stmtRel = $pdo->prepare(
    'SELECT registro_digital, rubro, MATCH(rubro) AGAINST (:q1 IN NATURAL LANGUAGE MODE) AS relevancia
     FROM jurisprudencia_tesis
     WHERE MATCH(rubro) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
     ORDER BY relevancia DESC LIMIT 20'
);
$stmtRel->execute([':q1' => $q, ':q2' => $q]);
$filasRel = $stmtRel->fetchAll();
if (!$filasRel) {
    echo "Con ese texto de búsqueda, MATCH(rubro) AGAINST() no encuentra NINGUNA candidata (relevancia 0 en todas).\n";
} else {
    foreach ($filasRel as $f) {
        echo "[{$f['registro_digital']}] relevancia=" . round((float)$f['relevancia'], 4) . "\n{$f['rubro']}\n\n";
    }
}
