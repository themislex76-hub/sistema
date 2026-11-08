<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helpers.php';

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
$documentoId = (int)($in['documento_id'] ?? 0);

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
    'INSERT INTO avisos_boletin (expediente_id, fuente, origen, fecha_publicacion, resumen, url_verificacion, documento_id, estado)
     VALUES (:eid, :fuente, \'automatico\', :fecha, :resumen, :url, :documento_id, \'nuevo\')'
);
$stmt->execute([
    ':eid' => $expedienteId, ':fuente' => $fuente, ':fecha' => $fechaPublicacion,
    ':resumen' => $resumen, ':url' => $url !== '' ? $url : null,
    ':documento_id' => $documentoId > 0 ? $documentoId : null,
]);

$avisoId = (int)$pdo->lastInsertId();

// Avisa por push al Administrador y al/los abogado(s) asignados al
// expediente -- mismo criterio de "quién debe enterarse" que ya usa
// jurisprudencia_cruce_helpers.php, pero aquí siempre se incluye al
// Administrador también (no solo cuando nadie está asignado).
$expStmt = $pdo->prepare('SELECT exp, actor, demandado, abogado_id, abogado_id_2 FROM expedientes WHERE id = :id');
$expStmt->execute([':id' => $expedienteId]);
$exp = $expStmt->fetch();
if ($exp) {
    $destinatarios = [];
    $admins = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1")->fetchAll();
    foreach ($admins as $a) $destinatarios[(int)$a['id']] = true;
    if (!empty($exp['abogado_id'])) $destinatarios[(int)$exp['abogado_id']] = true;
    if (!empty($exp['abogado_id_2'])) $destinatarios[(int)$exp['abogado_id_2']] = true;

    $caso = trim(($exp['actor'] ?? '') . ' vs ' . ($exp['demandado'] ?? ''), ' vs');
    $referencia = $exp['exp'] ? "Exp. {$exp['exp']}" : ($caso !== '' ? $caso : 'expediente');
    $titulo = '📋 Aviso nuevo de boletín -- ' . $referencia;
    foreach (array_keys($destinatarios) as $usuarioId) {
        push_enviar_a_usuario($pdo, $usuarioId, $titulo, $resumen, '/sistema/');
    }
}

respond(['id' => $avisoId], 201);
