<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
$pdo = db();
$isAdmin = $user['rol'] === 'administrador';

$where = $isAdmin ? '1=1' : '(e.abogado_id = :uid1 OR e.abogado_id_2 = :uid2)';

$sql = "SELECT sp.*, e.exp AS expediente_exp, e.actor AS expediente_actor, e.demandado AS expediente_demandado,
               ru.nombre AS respondido_por_nombre
        FROM supervisor_preguntas sp
        JOIN expedientes e ON e.id = sp.expediente_id
        LEFT JOIN usuarios ru ON ru.id = sp.respondido_por
        WHERE $where
        ORDER BY (sp.estado = 'pendiente') DESC, sp.creado_en DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
if (!$isAdmin) $stmt->execute([':uid1' => $user['id'], ':uid2' => $user['id']]);
else $stmt->execute();

$preguntas = [];
foreach ($stmt->fetchAll() as $r) {
    $preguntas[] = [
        'id' => (int)$r['id'],
        'expediente_id' => (int)$r['expediente_id'],
        'expediente_exp' => $r['expediente_exp'],
        'expediente_actor' => $r['expediente_actor'],
        'expediente_demandado' => $r['expediente_demandado'],
        'etapa_key' => $r['etapa_key'],
        'pregunta' => $r['pregunta'],
        'respuesta' => $r['respuesta'],
        'fecha_aplicada' => $r['fecha_aplicada'],
        'estado' => $r['estado'],
        'creado_en' => $r['creado_en'],
        'respondido_por_nombre' => $r['respondido_por_nombre'],
        'respondido_en' => $r['respondido_en'],
    ];
}

respond(['preguntas' => $preguntas]);
