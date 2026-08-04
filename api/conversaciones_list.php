<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Vista de todas las conversaciones de WhatsApp (calificaran o no como
// prospecto), para que el Administrador pueda ver de un vistazo qué está
// pasando con el bot y revisar cómo está contestando. Solo Administrador:
// es una herramienta de control de calidad del bot, no de trabajo diario
// de los abogados (eso es "Prospectos").
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

$pdo = db();

$stmt = $pdo->query(
    "SELECT c.telefono, c.texto AS ultimo_texto, c.direccion AS ultima_direccion, c.creado_en AS ultimo_mensaje,
            t.total_mensajes, t.primer_mensaje,
            p.tipo AS prospecto_tipo, p.estatus AS prospecto_estatus, p.nombre AS prospecto_nombre,
            p.expediente_id, u.nombre AS asignado_nombre
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

$conversaciones = [];
foreach ($stmt->fetchAll() as $r) {
    $conversaciones[] = [
        'telefono' => $r['telefono'],
        'ultimo_texto' => $r['ultimo_texto'],
        'ultima_direccion' => $r['ultima_direccion'],
        'ultimo_mensaje' => $r['ultimo_mensaje'],
        'total_mensajes' => (int)$r['total_mensajes'],
        'primer_mensaje' => $r['primer_mensaje'],
        'prospecto_tipo' => $r['prospecto_tipo'],
        'prospecto_estatus' => $r['prospecto_estatus'],
        'prospecto_nombre' => $r['prospecto_nombre'],
        'expediente_id' => $r['expediente_id'] !== null ? (int)$r['expediente_id'] : null,
        'asignado_nombre' => $r['asignado_nombre'],
    ];
}

$statsRow = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM whatsapp_conversaciones WHERE direccion = 'entrante' AND creado_en >= CURDATE()) AS entrantes_hoy,
        (SELECT COUNT(*) FROM whatsapp_conversaciones WHERE direccion = 'entrante' AND creado_en >= (CURDATE() - INTERVAL 6 DAY)) AS entrantes_semana,
        (SELECT COUNT(DISTINCT telefono) FROM whatsapp_conversaciones) AS conversaciones_totales,
        (SELECT COUNT(DISTINCT telefono) FROM whatsapp_conversaciones WHERE creado_en >= CURDATE()) AS conversaciones_nuevas_hoy,
        (SELECT COUNT(*) FROM prospectos) AS total_prospectos"
)->fetch();

$conversacionesTotales = (int)($statsRow['conversaciones_totales'] ?? 0);
$totalProspectos = (int)($statsRow['total_prospectos'] ?? 0);

respond([
    'conversaciones' => $conversaciones,
    'stats' => [
        'entrantes_hoy' => (int)$statsRow['entrantes_hoy'],
        'entrantes_semana' => (int)$statsRow['entrantes_semana'],
        'conversaciones_totales' => $conversacionesTotales,
        'conversaciones_nuevas_hoy' => (int)$statsRow['conversaciones_nuevas_hoy'],
        'total_prospectos' => $totalProspectos,
        'tasa_conversion' => $conversacionesTotales > 0 ? round($totalProspectos / $conversacionesTotales * 100, 1) : null,
    ],
]);
