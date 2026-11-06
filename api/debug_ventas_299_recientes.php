<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca citas de asesoría confirmadas y COBRADAS
// de verdad en $299 (vía mp_payment_id, no solo la columna monto) en los
// últimos días, y para cada una muestra el mensaje saliente que disparó
// mercadopago_monto_asesoria_a_respetar() a devolver 299 en vez del
// precio vigente -- para diagnosticar si es un falso positivo del ancla
// ("llamada telefónica"/"Rubén Buerhend" + "$299" coincidiendo con un
// renglón de un cálculo, no una cotización real). Solo Administrador.
// Se puede borrar cuando ya no haga falta.
//
// Uso: debug_ventas_299_recientes.php?dias=15
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = max(1, (int)($_GET['dias'] ?? 15));
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT id, telefono, monto, nombre_cliente, pagado_en, creado_en
     FROM citas_asesoria
     WHERE estado = 'confirmada' AND monto = 299.00
       AND pagado_en >= :desde
     ORDER BY pagado_en DESC"
);
$stmt->execute([':desde' => date('Y-m-d H:i:s', strtotime("-{$dias} days"))]);
$citas = $stmt->fetchAll();

if (!$citas) {
    echo "No hay ninguna cita confirmada en \$299 en los últimos {$dias} días.\n";
    exit;
}

$anclaAsesoria = "(texto LIKE '%asesor%' OR texto LIKE '%llamada telefónica%' OR texto LIKE '%Rubén Buerhend%')";

foreach ($citas as $c) {
    echo "=== Cita #{$c['id']} -- {$c['telefono']} ({$c['nombre_cliente']}) -- pagada {$c['pagado_en']} (creada {$c['creado_en']}) ===\n";
    $stmt2 = $pdo->prepare(
        "SELECT id, texto, creado_en FROM whatsapp_conversaciones
         WHERE telefono = :t AND direccion = 'saliente'
           AND texto LIKE '%\$299%' AND {$anclaAsesoria}
           AND creado_en <= :limite
         ORDER BY creado_en DESC LIMIT 1"
    );
    $stmt2->execute([':t' => $c['telefono'], ':limite' => $c['creado_en']]);
    $msg = $stmt2->fetch();
    if (!$msg) {
        echo "  (no se encontró el mensaje que disparó el ancla de \$299 -- raro, revisar a mano)\n\n";
        continue;
    }
    echo "  Mensaje ancla (id {$msg['id']}, {$msg['creado_en']}):\n";
    echo "  \"" . $msg['texto'] . "\"\n\n";
}
