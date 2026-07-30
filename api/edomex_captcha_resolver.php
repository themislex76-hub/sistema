<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El frontend manda aquí el código que la persona leyó del captcha.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$respuesta = trim((string)($in['respuesta'] ?? ''));

if ($id <= 0) fail('Falta el id del captcha.', 400);
if ($respuesta === '') fail('Escribe el código que ves en la imagen.', 400);

$pdo = db();
$stmt = $pdo->prepare("SELECT id FROM edomex_captcha_pendiente WHERE id = :id AND estado = 'pendiente'");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) fail('Ese captcha ya no está pendiente (puede que haya expirado).', 404);

$upd = $pdo->prepare(
    "UPDATE edomex_captcha_pendiente SET respuesta = :resp, estado = 'resuelto', resuelto_en = NOW() WHERE id = :id"
);
$upd->execute([':resp' => $respuesta, ':id' => $id]);

respond(['ok' => true]);
