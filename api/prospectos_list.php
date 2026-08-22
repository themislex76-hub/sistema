<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();
// El Administrador ve todos los prospectos; un abogado solo ve los que se
// le turnaron desde esta misma vista (ver prospectos_update.php).
$esAdmin = $user['rol'] === 'administrador';
// ultimo_entrante: el mensaje ENTRANTE más reciente de ese teléfono — se
// compara contra visto_en para saber si hay algo que el abogado todavía no
// ha visto (el bot puede seguir contestando solo sin que eso cuente como
// "visto por un humano", por eso se compara contra visto_en y no contra
// actualizado_en).
// ultima_actividad: el mensaje más reciente sea entrante o saliente — para
// ordenar la lista igual que WhatsApp (el chat con la actividad más
// reciente arriba de todo, no importa quién escribió al final).
$sql = "SELECT p.*, e.exp AS expediente_exp, u.nombre AS asignado_nombre,
               (SELECT MAX(creado_en) FROM whatsapp_conversaciones w
                WHERE w.telefono = p.telefono AND w.direccion = 'entrante') AS ultimo_entrante,
               (SELECT MAX(creado_en) FROM whatsapp_conversaciones w
                WHERE w.telefono = p.telefono) AS ultima_actividad,
               (SELECT COUNT(*) FROM whatsapp_conversaciones w
                WHERE w.telefono = p.telefono AND w.direccion = 'entrante'
                  AND w.creado_en > COALESCE(p.visto_en, '1970-01-01')) AS mensajes_sin_leer,
               (SELECT w.texto FROM whatsapp_conversaciones w
                WHERE w.telefono = p.telefono ORDER BY w.id DESC LIMIT 1) AS ultimo_mensaje_texto,
               (SELECT w.direccion FROM whatsapp_conversaciones w
                WHERE w.telefono = p.telefono ORDER BY w.id DESC LIMIT 1) AS ultimo_mensaje_direccion
        FROM prospectos p
        LEFT JOIN expedientes e ON e.id = p.expediente_id
        LEFT JOIN usuarios u ON u.id = p.asignado_a"
     . (!$esAdmin ? ' WHERE p.asignado_a = :uid' : '')
     . " ORDER BY COALESCE(ultima_actividad, p.actualizado_en) DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($esAdmin ? [] : [':uid' => $user['id']]);

$prospectos = [];
foreach ($stmt->fetchAll() as $r) {
    $mensajesSinLeer = (int)$r['mensajes_sin_leer'];
    $prospectos[] = [
        'id' => (int)$r['id'],
        'telefono' => $r['telefono'],
        'tipo' => $r['tipo'],
        'nombre' => $r['nombre'],
        'estado_ubicacion' => $r['estado_ubicacion'],
        'resumen_caso' => $r['resumen_caso'],
        'estatus' => $r['estatus'],
        'pausado_bot' => (bool)$r['pausado_bot'],
        'mensaje_nuevo' => $mensajesSinLeer > 0,
        'mensajes_sin_leer' => $mensajesSinLeer,
        'asignado_a' => $r['asignado_a'] !== null ? (int)$r['asignado_a'] : null,
        'asignado_nombre' => $r['asignado_nombre'],
        'notas_internas' => $r['notas_internas'],
        'expediente_id' => $r['expediente_id'] !== null ? (int)$r['expediente_id'] : null,
        'expediente_exp' => $r['expediente_exp'],
        'creado_en' => $r['creado_en'],
        'actualizado_en' => $r['actualizado_en'],
        'ultima_actividad' => $r['ultima_actividad'] ?? $r['actualizado_en'],
        'ultimo_mensaje_texto' => $r['ultimo_mensaje_texto'],
        'ultimo_mensaje_direccion' => $r['ultimo_mensaje_direccion'],
    ];
}

respond(['prospectos' => $prospectos]);
