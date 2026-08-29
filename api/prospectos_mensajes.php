<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
if ($user['rol'] !== 'administrador') {
    $chk = $pdo->prepare('SELECT asignado_a FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $telefono]);
    $prospecto = $chk->fetch();
    if (!$prospecto || (int)$prospecto['asignado_a'] !== (int)$user['id']) {
        fail('No tienes acceso a este prospecto.', 403);
    }
}
$stmt = $pdo->prepare(
    'SELECT id, direccion, texto, media_ruta, media_mime, respondido_por, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC LIMIT 300'
);
$stmt->execute([':t' => $telefono]);

respond(['mensajes' => $stmt->fetchAll()]);
