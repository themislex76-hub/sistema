<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Descarta una irregularidad detectada por cron_qa_irregularidades.php --
// "Está bien, no es error" (12 nov 2026). El botón "Corregir" NO pasa por
// aquí: solo abre el expediente en "Etapas del juicio" (navegación pura en
// el frontend) -- si el abogado de verdad corrige la fecha, la próxima
// corrida semanal del cron detecta sola que ya no aplica y la cierra como
// 'corregida' sin que nadie tenga que volver aquí.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT i.*, e.abogado_id, e.abogado_id_2
     FROM expediente_irregularidades i
     JOIN expedientes e ON e.id = i.expediente_id
     WHERE i.id = :id'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('No encontrada.', 404);
if ($row['estado'] !== 'pendiente') fail('Esta irregularidad ya se resolvió.', 400);

if ($user['rol'] !== 'administrador'
    && (int)$row['abogado_id'] !== (int)$user['id']
    && (int)($row['abogado_id_2'] ?? 0) !== (int)$user['id']) {
    fail('No tienes permiso sobre esta irregularidad.', 403);
}

$pdo->prepare(
    "UPDATE expediente_irregularidades SET estado = 'descartada', resuelto_en = NOW(), resuelto_por = :uid WHERE id = :id"
)->execute([':uid' => $user['id'], ':id' => $id]);

respond(['ok' => true]);
