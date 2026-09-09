<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Quita el bloqueo de un número -- el bot vuelve a poder contestarle
// solo (si no hay ninguna otra razón para que esté pausado, ej. un
// prospecto pausado a mano aparte de esto). Solo Administrador.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$telefono = trim((string)($in['telefono'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
$stmt = $pdo->prepare('DELETE FROM numeros_bloqueados WHERE telefono = :t');
$stmt->execute([':t' => $telefono]);

respond(['ok' => true]);
