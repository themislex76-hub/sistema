<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// Confirma (con un tap, nunca sola) la categoría que la IA sugirió al
// analizar un documento (ver cron_analizar_documentos_ia.php,
// expediente_documentos.ia_categoria_sugerida) -- 11 nov 2026, pedido
// explícito del usuario. A diferencia de llenar sola una etapa (una fecha
// se puede borrar desde "Etapas del juicio" si la IA se equivocó), hoy no
// hay forma de deshacer una recategorización desde la UI salvo borrando el
// documento entero, así que esto SIEMPRE requiere la confirmación del
// abogado, nunca corre automático.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM expediente_documentos WHERE id = :id');
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) fail('Documento no encontrado.', 404);

guard_expediente_access($pdo, $user, (int)$doc['expediente_id']);

if (!$doc['ia_categoria_sugerida'] || !in_array($doc['ia_categoria_sugerida'], CATEGORIA_DOCUMENTO_KEYS, true)) {
    fail('Este documento no tiene una categoría sugerida pendiente de confirmar.', 400);
}

$nueva = $doc['ia_categoria_sugerida'];
$pdo->prepare('UPDATE expediente_documentos SET categoria = :cat, ia_categoria_sugerida = NULL WHERE id = :id')
    ->execute([':cat' => $nueva, ':id' => $id]);

log_historial(
    $pdo, (int)$doc['expediente_id'], $user,
    'documento_categoria', $doc['categoria'], $nueva . " (sugerida por IA, confirmada por el abogado para '{$doc['nombre_archivo']}')"
);

respond(['ok' => true, 'categoria' => $nueva]);
