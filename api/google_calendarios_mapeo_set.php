<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Guarda (o actualiza) a qué abogado corresponde un sub-calendario de
// Google -- 11 nov 2026. usuario_lector_id siempre es quien hace esta
// llamada (el Administrador con la cuenta de Google conectada que ve
// todos los sub-calendarios).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);
require_csrf();

$in = json_input();
$calendarId = trim((string)($in['calendar_id'] ?? ''));
$nombreCalendario = trim((string)($in['nombre_calendario'] ?? ''));
$abogadoId = (int)($in['abogado_id'] ?? 0);
if ($calendarId === '' || $abogadoId <= 0) fail('Falta el calendario o el abogado.', 400);

$pdo = db();
$chk = $pdo->prepare("SELECT 1 FROM usuarios WHERE id = :id AND rol IN ('abogado','administrador')");
$chk->execute([':id' => $abogadoId]);
if (!$chk->fetch()) fail('Abogado no válido.', 400);

$pdo->prepare(
    'INSERT INTO google_calendarios_vigilados (calendar_id, nombre_calendario, abogado_id, usuario_lector_id, activo)
     VALUES (:cal, :nombre, :abogado, :lector, 1)
     ON DUPLICATE KEY UPDATE nombre_calendario = VALUES(nombre_calendario), abogado_id = VALUES(abogado_id),
       usuario_lector_id = VALUES(usuario_lector_id), activo = 1'
)->execute([
    ':cal' => $calendarId,
    ':nombre' => $nombreCalendario ?: $calendarId,
    ':abogado' => $abogadoId,
    ':lector' => $user['id'],
]);

respond(['ok' => true]);
