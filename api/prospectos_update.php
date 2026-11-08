<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del prospecto.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, asignado_a FROM prospectos WHERE id = :id');
$stmt->execute([':id' => $id]);
$prospecto = $stmt->fetch();
if (!$prospecto) fail('Prospecto no encontrado.', 404);

$esAdmin = $user['rol'] === 'administrador';
if (!$esAdmin) {
    if ((int)$prospecto['asignado_a'] !== (int)$user['id']) fail('No tienes acceso a este prospecto.', 403);
    // Solo el Administrador puede turnar/reasignar un prospecto.
    unset($in['asignado_a']);
}

$campos = [];
$params = [':id' => $id];

if (array_key_exists('estatus', $in)) {
    $estatus = (string)$in['estatus'];
    if (!in_array($estatus, ['nuevo', 'contactado', 'descartado', 'convertido'], true)) {
        fail('Estatus no válido.', 400);
    }
    $campos[] = 'estatus = :estatus';
    $params[':estatus'] = $estatus;
    // "Descartado" es que el despacho no toma el litigio, no que se deje
    // de contestarle a la persona — si no se mandó explícitamente un
    // valor para pausado_bot en esta misma petición, se reactiva el bot
    // para que siga contestando solo si escribe de nuevo.
    if ($estatus === 'descartado' && !array_key_exists('pausado_bot', $in)) {
        $campos[] = 'pausado_bot = 0';
    }
}
if (array_key_exists('nombre', $in)) {
    $campos[] = 'nombre = :nombre';
    $params[':nombre'] = (string)$in['nombre'] !== '' ? (string)$in['nombre'] : null;
}
if (array_key_exists('notas_internas', $in)) {
    $campos[] = 'notas_internas = :notas';
    $params[':notas'] = (string)$in['notas_internas'];
}
if (array_key_exists('pausado_bot', $in)) {
    $campos[] = 'pausado_bot = :pausado';
    $params[':pausado'] = !empty($in['pausado_bot']) ? 1 : 0;
}
if (array_key_exists('asignado_a', $in)) {
    $asignadoA = (int)$in['asignado_a'];
    $campos[] = 'asignado_a = :asignado';
    $params[':asignado'] = $asignadoA > 0 ? $asignadoA : null;
}
if (!empty($in['marcar_visto'])) {
    // Se manda al abrir el chat del prospecto — apaga el indicador de
    // "mensaje nuevo" hasta que llegue otro mensaje entrante después de
    // este momento (ver prospectos_list.php).
    $campos[] = 'visto_en = NOW()';
}

if (!$campos) fail('Nada que actualizar.', 400);

$sql = 'UPDATE prospectos SET ' . implode(', ', $campos) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

respond(['ok' => true]);
