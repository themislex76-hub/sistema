<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/citas_helpers.php';

// Herramienta temporal: crea una cita de prueba, YA confirmada (pagada),
// con hora_inicio dentro de la ventana que revisa cron_recordatorio_asesoria.php
// (50-70 minutos a partir de ahora) — para probar el recordatorio sin
// esperar a una cita real. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
//
// Uso: abre esta URL agregando ?telefono=52XXXXXXXXXX (tu propio WhatsApp,
// con código de país 52 pero SIN el 1 extra que se usa para marcar — la API
// de Meta lo rechaza) — si no mandas el teléfono, no crea nada, para no
// arriesgarte a mandarle un mensaje de prueba a un cliente real por error.
$user = require_admin();
header('Content-Type: text/plain; charset=utf-8');

$telefono = trim((string)($_GET['telefono'] ?? ''));
if ($telefono === '') {
    echo "Falta el teléfono. Usa la URL así:\n"
        . "debug_crear_cita_prueba.php?telefono=52XXXXXXXXXX\n"
        . "(tu propio WhatsApp: 52 + tu número a 10 dígitos, SIN el 1).\n";
    exit;
}

$pdo = db();

$ahora = new DateTimeImmutable();
$inicio = $ahora->modify('+58 minutes');
$fin = $inicio->modify('+1 hour');

$stmt = $pdo->prepare(
    "INSERT INTO citas_asesoria (telefono, usuario_id, fecha, hora_inicio, hora_fin, estado, monto, nombre_cliente, pagado_en)
     VALUES (:telefono, :usuario_id, :fecha, :hora_inicio, :hora_fin, 'confirmada', 399.00, 'Prueba recordatorio', NOW())"
);
$stmt->execute([
    ':telefono' => $telefono,
    ':usuario_id' => $user['id'],
    ':fecha' => $inicio->format('Y-m-d'),
    ':hora_inicio' => $inicio->format('H:i:00'),
    ':hora_fin' => $fin->format('H:i:00'),
]);

echo "Cita de prueba creada (id " . $pdo->lastInsertId() . ") para las " . citas_formatear_hora($inicio->format('H:i')) . " de hoy.\n"
    . "En el próximo ciclo del cron (máximo 15 minutos) debería llegarte el recordatorio a {$telefono}.\n";
