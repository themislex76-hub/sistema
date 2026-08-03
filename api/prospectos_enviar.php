<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$telefono = trim((string)($in['telefono'] ?? ''));
$texto = trim((string)($in['texto'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);
if ($texto === '') fail('Escribe un mensaje.', 400);

$pdo = db();
if ($user['rol'] !== 'administrador') {
    $chk = $pdo->prepare('SELECT asignado_a FROM prospectos WHERE telefono = :t');
    $chk->execute([':t' => $telefono]);
    $prospecto = $chk->fetch();
    if (!$prospecto || (int)$prospecto['asignado_a'] !== (int)$user['id']) {
        fail('No tienes acceso a este prospecto.', 403);
    }
}

if (!whatsapp_enviar($telefono, $texto)) {
    fail('No se pudo enviar el mensaje de WhatsApp. Revisa las credenciales del bot.', 502);
}

$stmt = $pdo->prepare(
    "INSERT INTO whatsapp_conversaciones (telefono, direccion, texto, respondido_por) VALUES (:t, 'saliente', :texto, 'humano')"
);
$stmt->execute([':t' => $telefono, ':texto' => $texto]);

respond(['ok' => true]);
