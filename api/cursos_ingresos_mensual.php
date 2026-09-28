<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Informe de cursos vendidos por mes -- a diferencia de la versión
// anterior (que consultaba el historial de pagos de Mercado Pago en
// vivo, para cubrir también las ventas hechas directo en la página vieja
// de Netlify), esto solo cuenta lo comprado DESDE EL BOT (tabla
// compras_curso), igual que asesorias_ingresos_mensual.php con las
// asesorías -- decisión consciente: panel simple y confiable, a cambio
// de no ver aquí las ventas de Netlify (esas no dejan registro en esta
// base de datos). Solo Administrador -- es información financiera del
// despacho completo, mismo criterio que antes.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();
$sql = "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, curso_slug, COUNT(*) AS vendidos, SUM(monto) AS total
        FROM compras_curso
        WHERE estado = 'confirmada' AND pagado_en IS NOT NULL
        GROUP BY mes, curso_slug
        ORDER BY mes DESC";
$rows = $pdo->query($sql)->fetchAll();

$porMes = [];
foreach ($rows as $r) {
    $mes = $r['mes'];
    if (!isset($porMes[$mes])) $porMes[$mes] = ['mes' => $mes, 'vendidos' => 0, 'total' => 0.0, 'cursos' => []];
    $vendidos = (int)$r['vendidos'];
    $total = (float)$r['total'];
    $porMes[$mes]['vendidos'] += $vendidos;
    $porMes[$mes]['total'] += $total;
    $titulo = CURSOS_CATALOGO[$r['curso_slug']]['titulo'] ?? $r['curso_slug'];
    $porMes[$mes]['cursos'][] = ['titulo' => $titulo, 'vendidos' => $vendidos, 'total' => $total];
}

$meses = array_values($porMes);
usort($meses, static fn($a, $b) => strcmp($b['mes'], $a['mes']));
$meses = array_slice($meses, 0, 24);
foreach ($meses as &$m) {
    usort($m['cursos'], static fn($a, $b) => $b['total'] <=> $a['total']);
}
unset($m);

respond(['meses' => $meses]);
