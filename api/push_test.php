<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helpers.php';

// Manda una notificación de prueba al usuario que tiene la sesión abierta
// (a todos sus dispositivos con las notificaciones activadas) — para
// confirmar que de verdad llegan, sin tener que esperar a un mensaje real
// de WhatsApp o un pago.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

push_enviar_a_usuario(
    db(),
    (int)$user['id'],
    '🔔 Notificación de prueba',
    'Si ves esto, las notificaciones push ya están funcionando correctamente.'
);

respond(['ok' => true, 'mensaje' => 'Notificación mandada. Si no te llegó en unos segundos, revisa que le hayas dado clic a "Activar notificaciones" y aceptado el permiso en ESTE dispositivo.']);
