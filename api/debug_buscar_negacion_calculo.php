<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: busca en whatsapp_conversaciones mensajes SALIENTES
// (del bot) donde pudo haber cometido el mismo error que con Eli -- decirle
// a alguien que el CÁLCULO (no la asesoría) ya no es gratis, o que el
// cálculo es parte de la asesoría de pago. Es una búsqueda de texto, no
// perfecta (el bot redacta distinto cada vez), pero cubre las frases más
// probables para que el abogado revise a mano cada caso encontrado. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$patrones = [
    '%no manejamos c%lculos%',
    '%no ofrecemos c%lculos%',
    '%ya no hacemos c%lculos%',
    '%c%lculos ni revisiones gratu%',
    '%c%lculo exacto%parte de la asesor%',
    '%c%lculo%es parte de la asesor%',
    '%el c%lculo%no es gratis%',
    '%c%lculo%tiene costo%',
    '%c%lculo%tiene un costo%',
];

$condiciones = [];
$params = [];
foreach ($patrones as $i => $p) {
    $condiciones[] = "texto LIKE :p{$i}";
    $params[":p{$i}"] = $p;
}

$sql = "SELECT telefono, texto, creado_en FROM whatsapp_conversaciones
        WHERE direccion = 'saliente' AND (" . implode(' OR ', $condiciones) . ")
        ORDER BY creado_en DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll();

echo "Buscando mensajes del bot que pudieron negar el cálculo gratis por error...\n\n";

if (!$filas) {
    echo "No se encontró ningún otro caso con esas frases -- parece que fue solo con Eli.\n";
    echo "(Esta búsqueda es por texto, no es perfecta: si alguien recuerda otro caso\n";
    echo "puntual, revísalo a mano en Conversaciones.)\n";
    exit;
}

foreach ($filas as $r) {
    echo "  " . $r['telefono'] . " -- " . $r['creado_en'] . "\n";
    echo "    \"" . mb_strimwidth((string)$r['texto'], 0, 200, '…') . "\"\n\n";
}
echo "Total encontrados: " . count($filas) . "\n";
