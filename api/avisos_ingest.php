<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por los robots de monitoreo (Federal, CDMX, Edomex) cuando
// encuentran una novedad real en el boletín de un expediente. Se evita
// duplicar el mismo aviso si ya existe uno idéntico sin revisar todavía
// (mismo expediente + fuente + resumen).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_robot_key();

$in = json_input();
$expedienteId = (int)($in['expediente_id'] ?? 0);
$fuente = (string)($in['fuente'] ?? 'otro');
$resumen = trim((string)($in['resumen'] ?? ''));
$fechaPublicacion = ($in['fecha_publicacion'] ?? '') !== '' ? $in['fecha_publicacion'] : null;
$url = trim((string)($in['url_verificacion'] ?? ''));

if ($expedienteId <= 0) fail('Falta el expediente.', 400);
if ($resumen === '') fail('Falta el resumen del aviso.', 400);

$fuentesValidas = ['cdmx_local', 'edomex_local', 'federal_laboral', 'otro'];
if (!in_array($fuente, $fuentesValidas, true)) $fuente = 'otro';

$pdo = db();

$existe = $pdo->prepare(
    "SELECT id FROM avisos_boletin
     WHERE expediente_id = :eid AND fuente = :fuente AND resumen = :resumen AND estado = 'nuevo'
     LIMIT 1"
);
$existe->execute([':eid' => $expedienteId, ':fuente' => $fuente, ':resumen' => $resumen]);
if ($existe->fetch()) {
    respond(['ok' => true, 'duplicado' => true]);
}

$stmt = $pdo->prepare(
    'INSERT INTO avisos_boletin (expediente_id, fuente, origen, fecha_publicacion, resumen, url_verificacion, estado)
     VALUES (:eid, :fuente, \'automatico\', :fecha, :resumen, :url, \'nuevo\')'
);
$stmt->execute([
    ':eid' => $expedienteId, ':fuente' => $fuente, ':fecha' => $fechaPublicacion,
    ':resumen' => $resumen, ':url' => $url !== '' ? $url : null,
]);

respond(['id' => (int)$pdo->lastInsertId()], 201);
