<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta de solo lectura: embudo completo de cursos -- cuántos
// mostraron interés real (registrar_interes_curso -> prospectos tipo
// 'interes_curso'), cuántos llegaron a comprar (compras_curso confirmada),
// y la tasa de conversión, por curso y en total. Para decidir si el
// problema de ventas es de precio, de exposición (poca gente se entera),
// o de fricción al pagar. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
//
// Uso: debug_embudo_cursos.php?dias=60 (default 60)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = max(1, min(365, (int)($_GET['dias'] ?? 60)));
$pdo = db();
$desde = date('Y-m-d', strtotime("-{$dias} days"));

echo "=== Embudo de cursos -- últimos {$dias} días ===\n\n";

// 1) Interés mostrado (registrar_interes_curso)
$stmt = $pdo->prepare(
    "SELECT curso_interes, COUNT(*) AS n
     FROM prospectos
     WHERE tipo = 'interes_curso' AND creado_en >= :desde
     GROUP BY curso_interes"
);
$stmt->execute([':desde' => $desde]);
$interes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 2) Compras por estado y curso
$stmt = $pdo->prepare(
    "SELECT curso_slug, estado, COUNT(*) AS n, SUM(monto) AS total
     FROM compras_curso
     WHERE creado_en >= :desde
     GROUP BY curso_slug, estado"
);
$stmt->execute([':desde' => $desde]);
$compras = $stmt->fetchAll();

$SLUG_A_TITULO = [
    'procesal' => 'Nuevo Procedimiento Laboral Mexicano',
    'amparo' => 'El Juicio de Amparo en Materia del Trabajo',
    'actas' => 'Actas Administrativas Laborales',
];

echo "-- Interés mostrado (registrar_interes_curso) --\n";
$totalInteres = 0;
foreach ($interes as $curso => $n) {
    echo "  " . ($curso ?: '(sin especificar)') . ": {$n}\n";
    $totalInteres += (int)$n;
}
echo "  TOTAL: {$totalInteres}\n\n";

echo "-- Compras por curso y estado (se muestran tal cual existan en la tabla) --\n";
$porSlug = [];
foreach ($compras as $c) {
    $porSlug[$c['curso_slug']][$c['estado'] === '' ? '(vacío)' : $c['estado']] = ['n' => (int)$c['n'], 'total' => (float)$c['total']];
}
$totalConfirmadas = 0;
$totalMontoConfirmado = 0.0;
foreach ($SLUG_A_TITULO as $slug => $titulo) {
    echo "  {$titulo}:\n";
    if (empty($porSlug[$slug])) {
        echo "    (sin registros)\n";
        continue;
    }
    foreach ($porSlug[$slug] as $estado => $datos) {
        echo "    estado={$estado}: {$datos['n']} (\${$datos['total']} MXN)\n";
        if ($estado === 'confirmada') {
            $totalConfirmadas += $datos['n'];
            $totalMontoConfirmado += $datos['total'];
        }
    }
}
echo "  TOTAL confirmadas: {$totalConfirmadas} (\${$totalMontoConfirmado} MXN)\n\n";

$tasaConversion = $totalInteres > 0 ? round($totalConfirmadas / $totalInteres * 100, 1) : 0;
echo "-- Conversión interés -> compra confirmada: {$tasaConversion}% ({$totalConfirmadas} de {$totalInteres}) --\n\n";

echo "-- Cómo leer esto --\n";
echo "  Si el interés (arriba) es MUY bajo comparado con el tráfico general del bot, el problema es de EXPOSICIÓN -- casi nadie se entera o pregunta por los cursos, un descuento no va a ayudar mucho si nadie llega siquiera a verlo.\n";
echo "  Si el interés es alto pero la conversión a compra confirmada es baja, el problema SÍ puede ser de precio/fricción al pagar -- ahí una oferta real sí tiene sentido probar.\n";
