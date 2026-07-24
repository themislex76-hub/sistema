<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

// GET ?id=123        -> ver el código de acceso actual (lo genera si no existe)
// POST {id, regenerar:true} -> genera uno nuevo (invalida el anterior)
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) fail('Falta el id del expediente.', 400);
    $row = guard_expediente_access($pdo, $user, $id);
    $codigo = $row['codigo_acceso'];
    if (!$codigo) {
        $codigo = random_access_code();
        $pdo->prepare('UPDATE expedientes SET codigo_acceso = :c WHERE id = :id')->execute([':c' => $codigo, ':id' => $id]);
    }
    respond(['codigo_acceso' => $codigo]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) fail('Falta el id del expediente.', 400);
    guard_expediente_access($pdo, $user, $id);
    $codigo = random_access_code();
    $pdo->prepare('UPDATE expedientes SET codigo_acceso = :c, portal_intentos_fallidos = 0, portal_bloqueado_hasta = NULL WHERE id = :id')
        ->execute([':c' => $codigo, ':id' => $id]);
    respond(['codigo_acceso' => $codigo]);
}

fail('Método no permitido.', 405);
