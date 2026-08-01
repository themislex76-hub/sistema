<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_login();

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT direccion, texto, respondido_por, creado_en FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY id ASC LIMIT 300'
);
$stmt->execute([':t' => $telefono]);

respond(['mensajes' => $stmt->fetchAll()]);
