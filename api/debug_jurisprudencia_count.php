<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: cuántas tesis ha guardado ya el robot de
// jurisprudencia laboral, para confirmar si una corrida se interrumpió a
// medias o si de verdad terminó el historial completo. Solo Administrador.
// Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$total = (int)$pdo->query('SELECT COUNT(*) FROM jurisprudencia_tesis')->fetchColumn();
echo "Total de tesis guardadas: {$total}\n\n";

if ($total > 0) {
    $rango = $pdo->query(
        'SELECT MIN(fecha_publicacion) AS mas_vieja, MAX(fecha_publicacion) AS mas_nueva FROM jurisprudencia_tesis'
    )->fetch();
    echo "Fecha de publicación más vieja guardada: " . ($rango['mas_vieja'] ?? '(sin fecha)') . "\n";
    echo "Fecha de publicación más nueva guardada: " . ($rango['mas_nueva'] ?? '(sin fecha)') . "\n\n";

    echo "Últimas 5 tesis guardadas (por registro_digital más alto):\n";
    $stmt = $pdo->query(
        'SELECT registro_digital, rubro, fecha_publicacion FROM jurisprudencia_tesis ORDER BY registro_digital DESC LIMIT 5'
    );
    foreach ($stmt->fetchAll() as $t) {
        $rubro = mb_substr((string)$t['rubro'], 0, 80);
        echo "  [{$t['registro_digital']}] ({$t['fecha_publicacion']}) {$rubro}...\n";
    }
}
