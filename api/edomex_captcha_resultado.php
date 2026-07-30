<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El robot de Edomex consulta aquí (con su llave, no sesión) si ya alguien
// resolvió el captcha que publicó, para leer la respuesta y continuar.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('Falta el id del captcha.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT estado, respuesta FROM edomex_captcha_pendiente WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('Captcha no encontrado.', 404);

respond(['estado' => $row['estado'], 'respuesta' => $row['respuesta']]);
