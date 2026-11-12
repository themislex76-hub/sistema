<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Panel de monitoreo "Actividad de la IA" (11 nov 2026, pedido explícito
// del usuario: "una sección donde podamos ver los cambios que ha hecho
// la IA para monitorear lo que está haciendo"). Con 5 mecanismos
// distintos llenando etapas solas (acuerdos de boletín, documentos
// subidos, auditoría integral, Google Calendar, y el script de
// reprocesamiento legacy), la única forma de revisarlo hoy era entrar al
// Historial de cambios de cada expediente uno por uno.
//
// Alcance a propósito limitado a usuario_id IS NULL -- esas son las
// entradas donde la IA actuó SOLA, sin que ningún humano la haya
// confirmado antes (log_historial() con ['id'=>null, 'nombre'=>'IA
// (...)']). Las que sí pasan por un tap de confirmación humana primero
// (responder una pregunta del supervisor, confirmar una sugerencia de
// Google Calendar, recategorizar un documento) quedan con el usuario_id
// real de quien confirmó -- ya tienen un humano de por medio, así que no
// es la misma urgencia de supervisión.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
if ($user['rol'] !== 'administrador') fail('Esta acción requiere permisos de administrador.', 403);

$pdo = db();
$dias = isset($_GET['dias']) ? max(1, min(90, (int)$_GET['dias'])) : 30;

$stmt = $pdo->prepare(
    "SELECT h.id, h.expediente_id, h.usuario_nombre, h.campo, h.antes, h.despues, h.creado_en,
            e.actor AS expediente_actor, e.demandado AS expediente_demandado, e.exp AS expediente_exp
     FROM expediente_historial h
     LEFT JOIN expedientes e ON e.id = h.expediente_id
     WHERE h.usuario_id IS NULL AND h.creado_en >= DATE_SUB(NOW(), INTERVAL :dias DAY)
     ORDER BY h.creado_en DESC
     LIMIT 300"
);
$stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
$stmt->execute();

respond(['actividad' => $stmt->fetchAll(), 'dias' => $dias]);
