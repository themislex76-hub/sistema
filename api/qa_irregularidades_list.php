<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Lista las irregularidades pendientes para "Preguntas al abogado" (12 nov
// 2026) -- un abogado ve solo las de sus expedientes, el Administrador ve
// todas. Mismo patrón que supervisor_preguntas_list.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
$pdo = db();
$isAdmin = $user['rol'] === 'administrador';

$where = $isAdmin ? '1=1' : '(e.abogado_id = :uid1 OR e.abogado_id_2 = :uid2)';

$sql = "SELECT i.id, i.expediente_id, i.etapa_key, i.tipo, i.motivo, i.creado_en,
               e.exp AS expediente_exp, e.actor AS expediente_actor, e.demandado AS expediente_demandado
        FROM expediente_irregularidades i
        JOIN expedientes e ON e.id = i.expediente_id
        WHERE i.estado = 'pendiente' AND $where
        ORDER BY i.creado_en DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
if (!$isAdmin) $stmt->execute([':uid1' => $user['id'], ':uid2' => $user['id']]);
else $stmt->execute();

respond(['irregularidades' => $stmt->fetchAll()]);
