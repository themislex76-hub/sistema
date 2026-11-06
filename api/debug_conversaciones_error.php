<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: corre exactamente la misma consulta que usa
// conversaciones_list.php para llenar la vista de "Conversaciones (WhatsApp)"
// y dice, en español, si truena y por qué -- para diagnosticar por qué esa
// vista se quedó mostrando "Todavía no hay conversaciones registradas"
// aunque sí hay datos reales. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "1) ¿Existe la tabla numeros_bloqueados?\n";
try {
    $existe = $pdo->query("SELECT COUNT(*) FROM numeros_bloqueados")->fetchColumn();
    echo "   Sí existe. Tiene {$existe} número(s) bloqueado(s).\n\n";
} catch (PDOException $e) {
    echo "   NO existe o hay un error al leerla:\n   " . $e->getMessage() . "\n\n";
    echo "   Esto es casi seguro la causa: falta correr el SQL de la migración\n";
    echo "   sql/migraciones/044_numeros_bloqueados.sql en phpMyAdmin.\n";
    exit;
}

echo "2) ¿Corre bien la consulta completa de la vista de Conversaciones?\n";
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
    echo "   Sí corrió bien. Devolvió " . count($filas) . " fila(s).\n";
    if (count($filas) === 0) {
        echo "   La consulta funciona pero no encontró ninguna conversación --\n";
        echo "   revisemos si whatsapp_conversaciones tiene datos:\n";
        $total = $pdo->query("SELECT COUNT(*) FROM whatsapp_conversaciones")->fetchColumn();
        echo "   Total de mensajes en whatsapp_conversaciones: {$total}\n";
    } else {
        echo "   Primer registro: teléfono " . $filas[0]['telefono'] . ", último mensaje: \""
            . mb_strimwidth((string)$filas[0]['ultimo_texto'], 0, 80, '…') . "\"\n";
    }
} catch (PDOException $e) {
    echo "   TRUENA con este error:\n   " . $e->getMessage() . "\n";
}
