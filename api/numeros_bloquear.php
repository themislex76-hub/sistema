<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Bloquea un número -- el bot deja de contestarle solo, pero si escribe
// de nuevo SÍ se avisa al despacho (igual que un reclamo), ver
// procesar_mensaje_entrante() en whatsapp_procesar.php. Solo
// Administrador: es una decisión del despacho, no algo que un socio
// deba poder hacer por su cuenta.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_admin();
require_csrf();

$in = json_input();
$telefono = trim((string)($in['telefono'] ?? ''));
$motivo = trim((string)($in['motivo'] ?? ''));
if ($telefono === '') fail('Falta el teléfono.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO numeros_bloqueados (telefono, motivo, bloqueado_por)
     VALUES (:t, :motivo, :uid)
     ON DUPLICATE KEY UPDATE motivo = VALUES(motivo), bloqueado_por = VALUES(bloqueado_por)"
);
$stmt->execute([':t' => $telefono, ':motivo' => $motivo !== '' ? $motivo : null, ':uid' => $user['id']]);

respond(['ok' => true]);
