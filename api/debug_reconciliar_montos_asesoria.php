<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mercadopago_helpers.php';

// Herramienta de un solo uso: corrige la columna 'monto' de citas_asesoria
// para las citas ya confirmadas -- bug real detectado en producción: esa
// columna nunca se llenaba en ningún punto del flujo de agendado/webhook,
// así que se quedaba siempre en su DEFAULT de la tabla (299.00) sin
// importar el precio real cobrado por Mercado Pago (el cobro real sí era
// correcto). Aquí se pregunta a la API de Mercado Pago, con el
// mp_payment_id ya guardado de cada cita, cuál fue el transaction_amount
// real, y se corrige. Es seguro correrlo más de una vez (no hace nada si
// ya coincide). Solo Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
// Cada cita revisada implica una llamada real a la API de Mercado Pago
// (varios cientos de ms) -- revisar TODAS las citas confirmadas desde
// siempre tronaba por timeout del hosting antes de terminar. En la
// práctica solo hace falta revisar las de después del cambio de precio
// (antes todas eran $299 de verdad, coinciden con el default, no hay
// nada que corregir ahí) -- por default solo mira los últimos 15 días.
// Si hiciera falta un rango más amplio, se puede pedir con ?dias=60.
if (ob_get_level() > 0) { ob_implicit_flush(true); ob_end_flush(); } else { ob_implicit_flush(true); }

$dias = isset($_GET['dias']) ? max(1, (int)$_GET['dias']) : 15;
$desde = (new DateTimeImmutable("-{$dias} days"))->format('Y-m-d H:i:s');

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, telefono, monto, mp_payment_id, pagado_en
     FROM citas_asesoria
     WHERE estado = 'confirmada' AND mp_payment_id IS NOT NULL
       AND pagado_en >= :desde
     ORDER BY id DESC"
);
$stmt->execute([':desde' => $desde]);
$citas = $stmt->fetchAll();

echo "Revisando " . count($citas) . " cita(s) confirmada(s) con pago verificable de los últimos {$dias} día(s)...\n";
echo "(agrega ?dias=60 a la URL para revisar un rango más amplio)\n\n";

$corregidas = 0;
$sinCambio = 0;
$errores = 0;

foreach ($citas as $c) {
    $pago = mercadopago_obtener_pago($c['mp_payment_id']);
    if ($pago === null) {
        echo "cita {$c['id']} ({$c['telefono']}): no se pudo consultar el pago {$c['mp_payment_id']} -- se deja igual.\n";
        $errores++;
        continue;
    }
    $real = round((float)($pago['transaction_amount'] ?? 0), 2);
    $guardado = round((float)$c['monto'], 2);
    if ($real <= 0) {
        echo "cita {$c['id']} ({$c['telefono']}): Mercado Pago no regresó transaction_amount válido -- se deja igual.\n";
        $errores++;
        continue;
    }
    if (abs($real - $guardado) < 0.005) {
        $sinCambio++;
        continue;
    }
    $upd = $pdo->prepare('UPDATE citas_asesoria SET monto = :m WHERE id = :id');
    $upd->execute([':m' => $real, ':id' => $c['id']]);
    echo "cita {$c['id']} ({$c['telefono']}, pagada {$c['pagado_en']}): \${$guardado} -> \${$real} corregido.\n";
    $corregidas++;
}

echo "\n-- Resumen --\n";
echo "Corregidas: {$corregidas}\n";
echo "Ya estaban bien: {$sinCambio}\n";
echo "No se pudieron verificar: {$errores}\n";
