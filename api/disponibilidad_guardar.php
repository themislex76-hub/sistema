<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Reemplazo completo del horario semanal de disponibilidad del usuario en
// sesión -- pedido explícito del usuario (14 nov 2026): la UI vieja
// ("Día" + "Desde"/"Hasta" + "Agregar horario", una fila a la vez) hacía
// tedioso dejarse espacio entre asesorías -- había que capturar bloques
// sueltos (ej. 11:00-12:00, luego 13:00-14:00) uno por uno para que
// quedara un hueco entre ellos. La UI nueva es una cuadrícula de casillas
// (día x hora, de 9am a 7pm, ver disponibilidadPanelHTML() en app.js) --
// cada hora marcada se guarda como su propio bloque de 1h; una casilla sin
// marcar entre 2 marcadas ya deja el espacio libre solo, sin necesidad de
// capturar rangos exactos.
//
// Mismo patrón de "reemplazo completo" que expedientes_update_pagos.php:
// borra todo lo que el usuario tenía y vuelve a insertar desde cero lo que
// mandó la cuadrícula -- no hay forma de comparar casilla por casilla cuál
// cambió, así que no tiene caso intentar un UPDATE parcial.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$usuario = require_login();

$in = json_input();
$horarios = is_array($in['horarios'] ?? null) ? $in['horarios'] : [];

$validos = [];
foreach ($horarios as $h) {
    if (!is_array($h)) continue;
    $dia = (int)($h['dia_semana'] ?? 0);
    $hora = (int)($h['hora'] ?? -1);
    if ($dia < 1 || $dia > 7) continue;
    if ($hora < 0 || $hora > 23) continue;
    $validos[] = [$dia, $hora];
}

$pdo = db();
$pdo->prepare('DELETE FROM disponibilidad_asesorias WHERE usuario_id = :uid')->execute([':uid' => $usuario['id']]);

$ins = $pdo->prepare(
    'INSERT INTO disponibilidad_asesorias (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES (:uid, :dia, :inicio, :fin)'
);
foreach ($validos as [$dia, $hora]) {
    $ins->execute([
        ':uid' => $usuario['id'],
        ':dia' => $dia,
        ':inicio' => sprintf('%02d:00', $hora),
        ':fin' => sprintf('%02d:00', $hora + 1),
    ]);
}

respond(['ok' => true, 'total' => count($validos)]);
