<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Guarda (o actualiza) los costos capturados a mano de un mes -- IA,
// hosting, WhatsApp Business API y comisión de Mercado Pago. Un registro
// por mes (upsert): si ya existía, se sobreescribe. Solo Administrador,
// es información financiera del despacho completo.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$mes = trim((string)($in['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) fail('Falta el mes (formato YYYY-MM).', 400);

$costoIa = (float)($in['costo_ia'] ?? 0);
$costoHosting = (float)($in['costo_hosting'] ?? 0);
$costoWhatsapp = (float)($in['costo_whatsapp'] ?? 0);
$comisionMp = (float)($in['comision_mercadopago'] ?? 0);
$notas = trim((string)($in['notas'] ?? ''));

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO costos_mensuales (mes, costo_ia, costo_hosting, costo_whatsapp, comision_mercadopago, notas, actualizado_por)
     VALUES (:mes, :ia, :hosting, :whatsapp, :mp, :notas, :uid)
     ON DUPLICATE KEY UPDATE
       costo_ia = VALUES(costo_ia), costo_hosting = VALUES(costo_hosting),
       costo_whatsapp = VALUES(costo_whatsapp), comision_mercadopago = VALUES(comision_mercadopago),
       notas = VALUES(notas), actualizado_por = VALUES(actualizado_por)"
);
$stmt->execute([
    ':mes' => $mes,
    ':ia' => $costoIa,
    ':hosting' => $costoHosting,
    ':whatsapp' => $costoWhatsapp,
    ':mp' => $comisionMp,
    ':notas' => $notas !== '' ? $notas : null,
    ':uid' => $user['id'],
]);

respond(['ok' => true]);
