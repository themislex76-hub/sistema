<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El frontend consulta aquí (con sesión normal) si hay un captcha de
// Edomex esperando que alguien lo resuelva.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT c.id, c.expediente_id, c.imagen_base64, c.creado_en, e.exp AS expediente_exp
     FROM edomex_captcha_pendiente c
     LEFT JOIN expedientes e ON e.id = c.expediente_id
     WHERE c.estado = 'pendiente'
     ORDER BY c.creado_en DESC LIMIT 1"
);
$stmt->execute();
$row = $stmt->fetch();

if (!$row) {
    respond(['pendiente' => null]);
}

respond(['pendiente' => [
    'id' => (int)$row['id'],
    'expediente_id' => $row['expediente_id'] !== null ? (int)$row['expediente_id'] : null,
    'expediente_exp' => $row['expediente_exp'],
    'imagen_base64' => $row['imagen_base64'],
    'creado_en' => $row['creado_en'],
]]);
