<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: cuántos números distintos han recibido la oferta
// del documento membretado ($49) -- ya sea de la IA en vivo o del
// recordatorio automático (cron_seguimiento_calculadora.php) -- y
// cuántos de esos ya lo compraron. Detecta la oferta por texto (busca
// "membrete" + "$49" en mensajes salientes), no hay una marca explícita
// en la base de datos de cuándo se ofreció. Solo Administrador. Se puede
// borrar cuando ya no haga falta.
//
// Uso: debug_ofertas_documento.php?dias=14 (default 14)
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$dias = (int)($_GET['dias'] ?? 14);
if ($dias <= 0) $dias = 14;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT telefono, MIN(creado_en) AS primera_oferta, COUNT(*) AS veces_ofrecido
     FROM whatsapp_conversaciones
     WHERE direccion = 'saliente'
       AND texto LIKE '%membrete%' AND texto LIKE '%\$49%'
       AND creado_en >= NOW() - INTERVAL :dias DAY
     GROUP BY telefono
     ORDER BY primera_oferta DESC"
);
$stmt->execute([':dias' => $dias]);
$ofertas = $stmt->fetchAll();

if (!$ofertas) {
    echo "Nadie ha recibido la oferta del documento (texto con 'membrete' + '\$49') en los últimos {$dias} día(s).\n";
    exit;
}

$telefonos = array_column($ofertas, 'telefono');
$in = implode(',', array_fill(0, count($telefonos), '?'));
$compraron = $pdo->prepare(
    "SELECT telefono, estado, pagado_en FROM compras_documento_calculo WHERE telefono IN ($in) AND estado = 'confirmada'"
);
$compraron->execute($telefonos);
$compradores = [];
foreach ($compraron->fetchAll() as $c) $compradores[$c['telefono']] = $c['pagado_en'];

$totalCompraron = count($compradores);

echo "=== Oferta del documento membretado (\$49) -- últimos {$dias} día(s) ===\n\n";
echo "Números distintos que recibieron la oferta: " . count($ofertas) . "\n";
echo "De esos, ya compraron el documento: {$totalCompraron}\n\n";

foreach ($ofertas as $o) {
    $tel = $o['telefono'];
    $compro = isset($compradores[$tel]) ? "SÍ compró ({$compradores[$tel]})" : "no compró";
    echo "  {$tel} -- ofrecido {$o['veces_ofrecido']} vez(es), primera vez {$o['primera_oferta']} -- {$compro}\n";
}
