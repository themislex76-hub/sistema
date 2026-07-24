<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$pdo = db();
$abogadoId = $user['rol'] === 'administrador' ? null : $user['id'];

$stmt = $pdo->prepare('INSERT INTO expedientes (status, abogado_id) VALUES (:status, :abogado_id)');
$stmt->execute([':status' => 'Status', ':abogado_id' => $abogadoId]);
$id = (int)$pdo->lastInsertId();

log_historial($pdo, $id, $user, 'expediente', null, 'Expediente creado');

respond(['id' => $id], 201);
