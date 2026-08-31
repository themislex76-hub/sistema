<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Tesis que el cruce automático (ver jurisprudencia_cruce_helpers.php)
// encontró aplicables a expedientes activos. Administrador ve todas, un
// abogado normal solo las de sus propios expedientes -- mismo criterio de
// visibilidad que expedientes_bootstrap.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();

$pdo = db();

$sql = "SELECT m.id, m.expediente_id, m.registro_digital, m.interpretacion, m.creado_en,
               e.actor, e.demandado, t.rubro
        FROM jurisprudencia_expediente_match m
        JOIN expedientes e ON e.id = m.expediente_id
        JOIN jurisprudencia_tesis t ON t.registro_digital = m.registro_digital";
$params = [];
if ($user['rol'] !== 'administrador') {
    $sql .= ' WHERE e.abogado_id = :uid';
    $params[':uid'] = $user['id'];
}
$sql .= ' ORDER BY m.creado_en DESC LIMIT 30';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

respond(['matches' => $stmt->fetchAll()]);
