<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del prospecto.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM prospectos WHERE id = :id');
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) fail('Prospecto no encontrado.', 404);

$campos = [];
$params = [':id' => $id];

if (array_key_exists('estatus', $in)) {
    $estatus = (string)$in['estatus'];
    if (!in_array($estatus, ['nuevo', 'contactado', 'descartado', 'convertido'], true)) {
        fail('Estatus no válido.', 400);
    }
    $campos[] = 'estatus = :estatus';
    $params[':estatus'] = $estatus;
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

if (!$campos) fail('Nada que actualizar.', 400);

$sql = 'UPDATE prospectos SET ' . implode(', ', $campos) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

respond(['ok' => true]);
