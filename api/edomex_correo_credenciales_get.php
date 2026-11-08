<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// El propio abogado consulta si ya tiene guardado su usuario del correo de
// notificaciones de Edomex. Nunca se regresa la contraseña (ni cifrada ni
// en texto plano) al navegador -- solo si está configurado o no, y el
// usuario para que pueda confirmar cuál es.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$stmt = $pdo->prepare('SELECT edomex_correo_usuario, edomex_correo_actualizado_en FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

respond([
    'configurado' => $row && $row['edomex_correo_usuario'] !== null && $row['edomex_correo_usuario'] !== '',
    'edomex_correo_usuario' => $row['edomex_correo_usuario'] ?? null,
    'actualizado_en' => $row['edomex_correo_actualizado_en'] ?? null,
]);
