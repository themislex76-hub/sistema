<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_admin();
require_csrf();

$in = json_input();
$clave = trim((string)($in['clave'] ?? ''));
$valor = (string)($in['valor'] ?? '');
if ($clave === '') fail('Falta la clave.', 400);

// Lista blanca: este endpoint genérico de clave/valor solo debe poder tocar
// las claves que el sistema realmente usa.
$permitidas = ['salario_minimo_diario'];
if (!in_array($clave, $permitidas, true)) fail('Clave no reconocida.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO configuracion (clave, valor) VALUES (:clave, :valor) ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
);
$stmt->execute([':clave' => $clave, ':valor' => $valor]);

respond(['ok' => true]);
