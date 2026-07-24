<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$campos = is_array($in['campos'] ?? null) ? $in['campos'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
$row = guard_expediente_access($pdo, $user, $id);

$sets = [];
$params = [':id' => $id];
$cambios = [];

foreach ($campos as $key => $value) {
    if (!in_array($key, EDITABLE_CAMPOS, true)) continue;
    $value = is_string($value) ? trim($value) : $value;
    if ($value === '') $value = null;
    $before = $row[$key];
    // Comparación laxa: evita registrar "cambios" solo por diferencias de tipo (string vs number).
    if ((string)$before !== (string)$value) {
        $cambios[] = ['campo' => $key, 'antes' => $before === null ? null : (string)$before, 'despues' => $value === null ? null : (string)$value];
    }
    $sets[] = "`$key` = :$key";
    $params[":$key"] = $value;
}

if ($sets) {
    $sql = 'UPDATE expedientes SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    foreach ($cambios as $c) {
        log_historial($pdo, $id, $user, $c['campo'], $c['antes'], $c['despues']);
    }
}

respond(['ok' => true, 'cambios' => count($cambios)]);
