<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Guarda (o actualiza) el costo de IA capturado a mano de un mes -- el
// único dato manual que queda en el panel de Costos vs. Ingresos, porque
// la Admin API de Anthropic rechaza la llave de este plan (403
// permission_error). Un registro por mes (upsert). Solo Administrador.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$mes = trim((string)($in['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) fail('Falta el mes (formato YYYY-MM).', 400);

$costoIa = (float)($in['costo_ia'] ?? 0);

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO costos_mensuales (mes, costo_ia, actualizado_por)
     VALUES (:mes, :ia, :uid)
     ON DUPLICATE KEY UPDATE costo_ia = VALUES(costo_ia), actualizado_por = VALUES(actualizado_por)"
);
$stmt->execute([
    ':mes' => $mes,
    ':ia' => $costoIa,
    ':uid' => $user['id'],
]);

respond(['ok' => true]);
