<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Horarios semanales en los que el abogado (o administrador) que tiene la
// sesión abierta puede atender asesorías telefónicas de pago. Cada quien
// solo ve y edita los suyos.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$usuario = require_login();

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, dia_semana, hora_inicio, hora_fin
     FROM disponibilidad_asesorias
     WHERE usuario_id = :uid
     ORDER BY dia_semana ASC, hora_inicio ASC'
);
$stmt->execute([':uid' => $usuario['id']]);

$bloques = [];
foreach ($stmt->fetchAll() as $r) {
    $bloques[] = [
        'id' => (int)$r['id'],
        'dia_semana' => (int)$r['dia_semana'],
        'hora_inicio' => substr($r['hora_inicio'], 0, 5),
        'hora_fin' => substr($r['hora_fin'], 0, 5),
    ];
}

respond(['bloques' => $bloques]);
