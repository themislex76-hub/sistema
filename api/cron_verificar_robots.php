<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job, 1 vez al día a media
// mañana (ej. 11:00 am -- ya debieron correr los 5 robots diarios/
// semanales a esa hora) -- NO se abre desde el navegador ni tiene sesión.
//
// Pedido explícito del usuario (20 nov 2026) tras descubrir a mano, con
// `schtasks` en su máquina de Windows, que 3 de los 5 robots llevaban sin
// correr por un archivo lanzador (run_diario.bat) faltante -- nadie lo
// notó hasta que se investigó expresamente. Este cron revisa
// `robot_heartbeats` (que cada robot actualiza solo al terminar, vía
// api/robot_heartbeat.php) y avisa por push al Administrador si alguno
// lleva más tiempo del esperado sin reportarse -- sin tener que revisar
// el Programador de tareas de Windows a mano.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: 1 vez al día.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/robot_helpers.php';
require_once __DIR__ . '/push_helpers.php';

$pdo = db();
$estado = robots_estado($pdo);
$vencidos = array_values(array_filter($estado, fn($r) => $r['vencido']));

if (!$vencidos) {
    echo count($estado) . " robot(es) revisado(s), todos reportaron a tiempo. Nada que avisar.\n";
    exit;
}

$lineas = [];
foreach ($vencidos as $r) {
    $ultima = $r['ultima_corrida_en'] ? date('d/m/Y H:i', strtotime($r['ultima_corrida_en'])) : 'nunca ha reportado';
    $lineas[] = "- {$r['nombre']}: última corrida {$ultima}";
}
$cuerpo = push_armar_cuerpo($lineas);

$admins = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1")->fetchAll();
foreach ($admins as $a) {
    push_enviar_a_usuario($pdo, (int)$a['id'], '🤖 Robot(s) de monitoreo sin reportarse', $cuerpo, '/sistema/');
}

echo count($vencidos) . " robot(s) atrasado(s) de " . count($estado) . " -- push enviado a "
    . count($admins) . " administrador(es).\n";
