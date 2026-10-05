<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Informe de documentos membretados de cálculo vendidos por mes ($49 MXN,
// tabla compras_documento_calculo) -- mismo criterio que
// asesorias_ingresos_mensual.php y cursos_ingresos_mensual.php: panel
// simple sobre la base de datos local, sin depender de consultar
// Mercado Pago en vivo. Solo Administrador -- es información financiera
// del despacho completo.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();
$sql = "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidos, SUM(monto) AS total
        FROM compras_documento_calculo
        WHERE estado = 'confirmada' AND pagado_en IS NOT NULL
        GROUP BY mes
        ORDER BY mes DESC";
$rows = $pdo->query($sql)->fetchAll();

$meses = [];
foreach ($rows as $r) {
    $meses[] = [
        'mes' => $r['mes'],
        'vendidos' => (int)$r['vendidos'],
        'total' => (float)$r['total'],
    ];
}

$meses = array_slice($meses, 0, 24);

respond(['meses' => $meses]);
