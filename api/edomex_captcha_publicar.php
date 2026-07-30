<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El robot de Edomex (corre en la computadora del despacho) publica aquí la
// imagen del captcha que necesita que alguien resuelva. Solo puede haber un
// captcha pendiente a la vez — cualquier otro que siga sin resolver se
// marca expirado (el robot solo procesa un expediente a la vez de todas
// formas).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_robot_key();

$in = json_input();
$expedienteId = isset($in['expediente_id']) ? (int)$in['expediente_id'] : null;
$imagenBase64 = (string)($in['imagen_base64'] ?? '');

if ($imagenBase64 === '') fail('Falta la imagen del captcha.', 400);
if ($expedienteId !== null && $expedienteId <= 0) $expedienteId = null;

$pdo = db();
$pdo->exec("UPDATE edomex_captcha_pendiente SET estado = 'expirado' WHERE estado = 'pendiente'");

$stmt = $pdo->prepare(
    "INSERT INTO edomex_captcha_pendiente (expediente_id, imagen_base64, estado) VALUES (:eid, :img, 'pendiente')"
);
$stmt->execute([':eid' => $expedienteId, ':img' => $imagenBase64]);

respond(['id' => (int)$pdo->lastInsertId()], 201);
