<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_calendar_helpers.php';

// Lista los calendarios visibles para la cuenta de Google que el
// Administrador ya conectó (misma conexión que usa el sync de salida) --
// 11 nov 2026, pedido explícito del usuario. Nunca pide volver a
// autorizar nada: el permiso que ya se pidió (calendar.events) alcanza
// para leer eventos también, no solo escribirlos.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);

$pdo = db();
$accessToken = google_obtener_access_token($pdo, (int)$user['id']);
if ($accessToken === null) {
    fail('Conecta tu Google Calendar primero desde "Mi cuenta" (la misma cuenta que ve todos los sub-calendarios de audiencias).', 400);
}

$calendarios = google_listar_calendarios($accessToken);
respond(['calendarios' => $calendarios]);
