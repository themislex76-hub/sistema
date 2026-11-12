<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Mapa simple abogado_id -> calendar_id (12 nov 2026) -- a diferencia de
// google_calendarios_mapeo_listar.php (solo Administrador, trae nombres
// para la pantalla de Equipo), este endpoint lo puede pedir CUALQUIER
// usuario autenticado: lo necesita el sync de salida (sistema → Google
// Calendar) para decidir a qué sub-calendario mandar cada evento, sin
// importar quién tenga la sesión abierta.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
$rows = $pdo->query('SELECT abogado_id, calendar_id FROM google_calendarios_vigilados WHERE activo = 1')->fetchAll();
$mapa = [];
foreach ($rows as $r) {
    $mapa[(int)$r['abogado_id']] = $r['calendar_id'];
}
respond(['mapa' => $mapa]);
