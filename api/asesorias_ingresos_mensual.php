<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Informe fijo de asesorías de pago ($299/$399) vendidas por mes -- antes
// solo se podía sacar por SQL directo en phpMyAdmin. Administrador ve
// todos los socios; un socio normal solo ve las suyas (las que le tocó
// atender). Además del total por mes, trae el desglose por monto (cuántas
// a $299, cuántas a $399, etc.) -- útil sobre todo alrededor de un cambio
// de precio, para ver la mezcla real en vez de solo el total.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();

$sql = "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, monto, COUNT(*) AS vendidas, SUM(monto) AS total
        FROM citas_asesoria
        WHERE estado = 'confirmada' AND pagado_en IS NOT NULL";
$params = [];
if ($user['rol'] !== 'administrador') {
    $sql .= ' AND usuario_id = :uid';
    $params[':uid'] = $user['id'];
}
$sql .= ' GROUP BY mes, monto ORDER BY mes DESC, monto DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$porMes = [];
foreach ($stmt->fetchAll() as $r) {
    $mes = $r['mes'];
    if (!isset($porMes[$mes])) $porMes[$mes] = ['mes' => $mes, 'vendidas' => 0, 'total' => 0.0, 'por_monto' => []];
    $vendidas = (int)$r['vendidas'];
    $total = (float)$r['total'];
    $porMes[$mes]['vendidas'] += $vendidas;
    $porMes[$mes]['total'] += $total;
    $porMes[$mes]['por_monto'][] = ['monto' => (float)$r['monto'], 'vendidas' => $vendidas, 'total' => $total];
}

$meses = array_values($porMes);
usort($meses, static fn($a, $b) => strcmp($b['mes'], $a['mes']));
$meses = array_slice($meses, 0, 24);

respond(['meses' => $meses]);
