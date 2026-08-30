<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helpers.php';

// Manda una notificación push de prueba a quien la pida, para poder ver
// (sonido, vibración, que se quede fija en pantalla) cómo se va a ver una
// notificación real sin tener que esperar a que ocurra algo de verdad.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$pdo = db();
push_enviar_a_usuario(
    $pdo,
    (int)$user['id'],
    '🔔 Notificación de prueba',
    'Así se va a ver un aviso real -- si sonó, vibró, y se quedó fija en pantalla, ya quedó todo listo.',
    '/sistema/'
);

respond(['ok' => true]);
