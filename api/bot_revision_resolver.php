<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Cierra una conversación marcada por la revisión diaria del bot:
// 'atendida' (ya se corrigió con el cliente o se ajustó el bot) o
// 'descartada' (la IA revisora se equivocó, no era error).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$estado = (string)($in['estado'] ?? '');
if ($id <= 0 || !in_array($estado, ['atendida', 'descartada'], true)) fail('Datos inválidos.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    "UPDATE bot_revisiones SET estado = :e, resuelto_por = :u, resuelto_en = NOW()
     WHERE id = :id AND estado = 'pendiente'"
);
$stmt->execute([':e' => $estado, ':u' => (int)$user['id'], ':id' => $id]);
respond(['ok' => true]);
