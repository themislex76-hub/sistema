<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Informe fijo de asesorías de pago ($299/$399) vendidas por mes -- antes
// solo se podía sacar por SQL directo en phpMyAdmin. Administrador ve
// todos los socios; un socio normal solo ve las suyas (las que le tocó
// atender).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();

$sql = "SELECT DATE_FORMAT(pagado_en, '%Y-%m') AS mes, COUNT(*) AS vendidas, SUM(monto) AS total
        FROM citas_asesoria
        WHERE estado = 'confirmada' AND pagado_en IS NOT NULL";
$params = [];
if ($user['rol'] !== 'administrador') {
    $sql .= ' AND usuario_id = :uid';
    $params[':uid'] = $user['id'];
}
$sql .= ' GROUP BY mes ORDER BY mes DESC LIMIT 24';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$meses = array_map(static function ($r) {
    return [
        'mes' => $r['mes'],
        'vendidas' => (int)$r['vendidas'],
        'total' => (float)$r['total'],
    ];
}, $stmt->fetchAll());

respond(['meses' => $meses]);
