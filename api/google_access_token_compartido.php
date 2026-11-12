<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_calendar_helpers.php';

// Token de la cuenta de Google COMPARTIDA -- la misma que ya se usa para
// "Calendarios de Google vigilados" en Equipo (12 nov 2026, pedido
// explícito del usuario: el sync de salida debe mandar cada evento al
// sub-calendario del abogado asignado al caso, "a la agenda compartida",
// no al calendario principal de quien dé clic en "Sincronizar ahora").
// Cualquier usuario autenticado puede pedirlo -- es el mismo
// usuario_lector_id que ya quedó guardado al mapear el primer
// calendario desde Equipo.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$lectorId = $pdo->query('SELECT usuario_lector_id FROM google_calendarios_vigilados WHERE activo = 1 LIMIT 1')->fetchColumn();
if (!$lectorId) fail('Todavía no hay ningún calendario de Google compartido configurado (ver "Equipo" → Calendarios de Google vigilados).', 400);

$accessToken = google_obtener_access_token($pdo, (int)$lectorId);
if ($accessToken === null) fail('La cuenta de Google compartida ya no está conectada.', 400);

respond(['access_token' => $accessToken]);
