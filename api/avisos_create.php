<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$expedienteId = (int)($in['expediente_id'] ?? 0);
$fuente = (string)($in['fuente'] ?? 'otro');
$resumen = trim((string)($in['resumen'] ?? ''));
$fechaPublicacion = ($in['fecha_publicacion'] ?? '') !== '' ? $in['fecha_publicacion'] : null;
$url = trim((string)($in['url_verificacion'] ?? ''));

if ($expedienteId <= 0) fail('Falta el expediente.', 400);
if ($resumen === '') fail('Escribe qué encontraste (o que no encontraste nada nuevo) al revisar el boletín.', 400);

$fuentesValidas = ['cdmx_local', 'edomex_local', 'federal_laboral', 'otro'];
if (!in_array($fuente, $fuentesValidas, true)) $fuente = 'otro';

$pdo = db();
guard_expediente_access($pdo, $user, $expedienteId);

$stmt = $pdo->prepare(
    'INSERT INTO avisos_boletin (expediente_id, fuente, origen, fecha_publicacion, resumen, url_verificacion, estado, creado_por)
     VALUES (:eid, :fuente, \'manual\', :fecha, :resumen, :url, \'nuevo\', :uid)'
);
$stmt->execute([
    ':eid' => $expedienteId, ':fuente' => $fuente, ':fecha' => $fechaPublicacion,
    ':resumen' => $resumen, ':url' => $url !== '' ? $url : null, ':uid' => $user['id'],
]);

respond(['id' => (int)$pdo->lastInsertId()], 201);
