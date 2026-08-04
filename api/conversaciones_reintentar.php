<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/whatsapp_helpers.php';
require_once __DIR__ . '/whatsapp_procesar.php';

// Reintenta las conversaciones que se quedaron con la respuesta de
// emergencia (la IA falló, típicamente por falta de saldo en Anthropic).
// GET  -> cuenta cuántas conversaciones son candidatas a reintento.
// POST {telefono} -> reintenta una sola conversación.
// POST {lote:true, tamano:N} -> reintenta hasta N conversaciones candidatas
//   en esta llamada (para evitar el límite de tiempo de ejecución de PHP en
//   hosting compartido); el frontend repite la llamada hasta que "restantes"
//   sea 0.
require_admin();

// Cada reintento hace dos llamadas de red (Anthropic + WhatsApp), así que un
// lote puede tardar más que el límite por defecto de PHP en hosting
// compartido (típicamente 30s) y el servidor corta la respuesta a la mitad
// — eso es lo que el frontend reporta como "no se pudo conectar". Le pedimos
// más margen aquí; si el hosting lo ignora (algunos lo bloquean), el tamaño
// de lote reducido abajo es la mitigación real.
set_time_limit(120);

$pdo = db();

function conversaciones_candidatas_reintento(PDO $pdo, int $limite): array
{
    $stmt = $pdo->prepare(
        "SELECT c.telefono
         FROM whatsapp_conversaciones c
         INNER JOIN (
             SELECT telefono, MAX(id) AS ultimo_id FROM whatsapp_conversaciones GROUP BY telefono
         ) t ON t.ultimo_id = c.id
         LEFT JOIN prospectos p ON p.telefono = c.telefono
         WHERE c.direccion = 'saliente' AND c.texto = :fallback
           AND (p.pausado_bot IS NULL OR p.pausado_bot = 0)
         ORDER BY c.creado_en ASC
         LIMIT " . (int)$limite
    );
    $stmt->execute([':fallback' => IA_FALLBACK_TEXTO]);
    return array_column($stmt->fetchAll(), 'telefono');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $candidatos = conversaciones_candidatas_reintento($pdo, 1000);
    respond(['pendientes' => count($candidatos)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_input();

    if (!empty($body['lote'])) {
        $tamano = isset($body['tamano']) ? max(1, min(5, (int)$body['tamano'])) : 3;
        $candidatos = conversaciones_candidatas_reintento($pdo, $tamano);
        $resultados = [];
        foreach ($candidatos as $telefono) {
            $r = reintentar_conversacion_fallida($pdo, $telefono);
            $resultados[] = ['telefono' => $telefono, 'ok' => $r['ok'], 'motivo' => $r['motivo']];
        }
        $restantes = count(conversaciones_candidatas_reintento($pdo, 1000));
        respond(['resultados' => $resultados, 'restantes' => $restantes]);
    }

    $telefono = trim((string)($body['telefono'] ?? ''));
    if ($telefono === '') fail('Falta el teléfono.', 422);

    $r = reintentar_conversacion_fallida($pdo, $telefono);
    respond(['ok' => $r['ok'], 'motivo' => $r['motivo']]);
}

fail('Método no permitido.', 405);
