<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$usuario = require_login();

$body = json_input();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 422);

$pdo = db();
// Solo se puede borrar un bloque propio — nunca el de otro abogado.
$stmt = $pdo->prepare('DELETE FROM disponibilidad_asesorias WHERE id = :id AND usuario_id = :uid');
$stmt->execute([':id' => $id, ':uid' => $usuario['id']]);

respond(['eliminado' => $stmt->rowCount() > 0]);
