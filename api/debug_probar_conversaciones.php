<?php
declare(strict_types=1);

// Herramienta temporal: reproduce paso a paso lo que hace
// conversaciones_list.php y whatsapp_embudo.php, pero mostrando CUALQUIER
// error real en pantalla (config.php los oculta a propósito en producción).
// Solo Administrador. Se puede borrar cuando ya no haga falta.

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "Paso 1: cargando config.php...\n";
try {
    require_once __DIR__ . '/config.php';
    echo "  OK\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

if (!function_exists('require_admin')) {
    echo "No se pudo seguir (require_admin no existe).\n";
    exit;
}
require_admin();

echo "Paso 2: conectando a la base de datos...\n";
try {
    $pdo = db();
    echo "  OK\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

echo "Paso 3: cargando ia_helpers.php...\n";
try {
    require_once __DIR__ . '/ia_helpers.php';
    echo "  OK\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

echo "Paso 4: corriendo la consulta principal de conversaciones_list.php...\n";
try {
    $stmt = $pdo->query(
        "SELECT c.telefono, c.texto AS ultimo_texto, c.direccion AS ultima_direccion, c.creado_en AS ultimo_mensaje,
                t.total_mensajes, t.primer_mensaje,
                p.id AS prospecto_id, p.tipo AS prospecto_tipo, p.estatus AS prospecto_estatus, p.nombre AS prospecto_nombre,
                p.pausado_bot, p.expediente_id, u.nombre AS asignado_nombre,
                EXISTS(SELECT 1 FROM numeros_bloqueados nb WHERE nb.telefono = c.telefono) AS bloqueado
         FROM whatsapp_conversaciones c
         INNER JOIN (
             SELECT telefono, COUNT(*) AS total_mensajes, MIN(creado_en) AS primer_mensaje, MAX(id) AS ultimo_id
             FROM whatsapp_conversaciones
             GROUP BY telefono
         ) t ON t.ultimo_id = c.id
         LEFT JOIN prospectos p ON p.telefono = c.telefono
         LEFT JOIN usuarios u ON u.id = p.asignado_a
         ORDER BY c.creado_en DESC
         LIMIT 300"
    );
    $filas = $stmt->fetchAll();
    echo "  OK -- " . count($filas) . " fila(s) devueltas\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\nPaso 5: probando que la respuesta completa se pueda convertir a JSON (por si algún texto tiene caracteres inválidos)...\n";
try {
    $json = json_encode(['conversaciones' => $filas ?? []], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "  ERROR json_encode: " . json_last_error_msg() . "\n";
    } else {
        echo "  OK -- JSON de " . strlen($json) . " bytes\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\nListo.\n";
