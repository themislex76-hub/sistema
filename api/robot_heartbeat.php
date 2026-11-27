<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Lo llama cada robot local (Federal/CDMX/Buzón CDMX/Edomex correo/
// Jurisprudencia) al terminar su corrida, con éxito o con error -- para
// que el sistema sepa, sin tener que revisar el Programador de tareas de
// Windows a mano, cuándo fue la última vez que cada uno corrió de verdad
// (pedido explícito del usuario, 20 nov 2026, tras descubrir que 3 de los
// 5 robots llevaban sin correr por un archivo lanzador faltante y nadie
// lo notó). Ver panel "Robots de monitoreo" en Equipo
// (api/robot_heartbeats_list.php) y la alerta diaria
// (api/cron_verificar_robots.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_robot_key();

$in = json_input();
$robot = trim((string)($in['robot'] ?? ''));
$ok = !empty($in['ok']) ? 1 : 0;
$resumen = trim((string)($in['resumen'] ?? ''));

$robotsValidos = ['federal', 'cdmx', 'cdmx_buzon', 'edomex_correo', 'jurisprudencia'];
if (!in_array($robot, $robotsValidos, true)) fail('Robot desconocido.', 400);

$pdo = db();
$pdo->prepare(
    'INSERT INTO robot_heartbeats (robot, ultima_corrida_en, ultimo_ok, ultimo_resumen)
     VALUES (:robot, NOW(), :ok, :resumen)
     ON DUPLICATE KEY UPDATE ultima_corrida_en = NOW(), ultimo_ok = VALUES(ultimo_ok), ultimo_resumen = VALUES(ultimo_resumen)'
)->execute([
    ':robot' => $robot,
    ':ok' => $ok,
    ':resumen' => $resumen !== '' ? mb_substr($resumen, 0, 255) : null,
]);

respond(['ok' => true]);
