<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$respuesta = trim((string)($in['respuesta'] ?? ''));
if ($id <= 0) fail('Falta el id de la pregunta.', 400);
if ($respuesta === '') fail('Escribe una respuesta.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM supervisor_preguntas WHERE id = :id');
$stmt->execute([':id' => $id]);
$pregunta = $stmt->fetch();
if (!$pregunta) fail('Pregunta no encontrada.', 404);

// Mismo control de acceso que cualquier otro endpoint de escritura sobre
// un expediente -- administrador ve/edita todos, un abogado solo lo suyo.
guard_expediente_access($pdo, $user, (int)$pregunta['expediente_id']);

$resultado = ia_interpretar_respuesta_supervisor($pregunta['pregunta'], $respuesta, date('Y-m-d'));
$fechaMencionada = $resultado['fecha_mencionada'] ?? null;

$aplicado = false;
if ($fechaMencionada && in_array($pregunta['etapa_key'], ETAPA_KEYS, true)) {
    // Nunca sobrescribe una etapa que ya tiene fecha capturada -- mismo
    // principio que cron_analizar_acuerdos_ia.php con los acuerdos de
    // boletín.
    $yaTiene = $pdo->prepare('SELECT fecha FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key');
    $yaTiene->execute([':eid' => $pregunta['expediente_id'], ':key' => $pregunta['etapa_key']]);
    $filaExistente = $yaTiene->fetch();
    $tieneFecha = $filaExistente && !empty($filaExistente['fecha']) && $filaExistente['fecha'] !== '0000-00-00';

    if (!$tieneFecha) {
        $pdo->prepare(
            'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha)
             VALUES (:eid, :key, :fecha)
             ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)'
        )->execute([':eid' => $pregunta['expediente_id'], ':key' => $pregunta['etapa_key'], ':fecha' => $fechaMencionada]);

        $etiqueta = ETAPA_LABELS[$pregunta['etapa_key']] ?? $pregunta['etapa_key'];
        log_historial(
            $pdo, (int)$pregunta['expediente_id'], $user,
            'etapa_' . $pregunta['etapa_key'], null, "fecha: $fechaMencionada (confirmado por el abogado al supervisor de IA)"
        );
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $pregunta['expediente_id']]);
        $aplicado = true;
    }
}

$pdo->prepare(
    'UPDATE supervisor_preguntas
     SET respuesta = :resumen, fecha_aplicada = :fecha, estado = :estado, respondido_por = :uid, respondido_en = NOW()
     WHERE id = :id'
)->execute([
    ':resumen' => $respuesta,
    ':fecha' => $aplicado ? $fechaMencionada : null,
    ':estado' => $aplicado ? 'aplicada' : 'respondida',
    ':uid' => $user['id'],
    ':id' => $id,
]);

respond(['ok' => true, 'aplicado' => $aplicado]);
