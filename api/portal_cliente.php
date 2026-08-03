<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);

$in = json_input();
$apellido = trim((string)($in['apellido'] ?? ''));
$codigo = strtoupper(trim((string)($in['codigo'] ?? '')));

if ($apellido === '' || $codigo === '') {
    fail('Completa tu apellido y el código de acceso.', 400);
}

$pdo = db();
$generic = 'No se encontró ningún expediente con esos datos. Verifica tu apellido y el código de acceso.';

// El código de acceso (8 caracteres al azar, ver random_access_code()) ya
// identifica al expediente por sí solo -- el apellido es nada más un
// segundo dato para confirmar que es la persona correcta.
$stmt = $pdo->prepare("SELECT e.*, u.nombre AS abogado_nombre FROM expedientes e LEFT JOIN usuarios u ON u.id = e.abogado_id WHERE e.codigo_acceso = :codigo LIMIT 1");
$stmt->execute([':codigo' => $codigo]);
$row = $stmt->fetch();

$logIntento = function (?int $expedienteId, bool $exito) use ($pdo, $codigo) {
    $stmt = $pdo->prepare('INSERT INTO portal_intentos_log (expediente_id, exp_buscado, exito, ip) VALUES (:eid, :exp, :exito, :ip)');
    $stmt->execute([':eid' => $expedienteId, ':exp' => $codigo, ':exito' => $exito ? 1 : 0, ':ip' => client_ip()]);
};

if (!$row || stripos((string)$row['actor'], $apellido) === false) {
    $logIntento($row ? (int)$row['id'] : null, false);
    fail($generic, 404);
}

$id = (int)$row['id'];

if (!empty($row['portal_bloqueado_hasta']) && strtotime($row['portal_bloqueado_hasta']) > time()) {
    $logIntento($id, false);
    fail('Demasiados intentos fallidos para este expediente. Intenta de nuevo más tarde o contacta al despacho.', 423);
}

$pdo->prepare('UPDATE expedientes SET portal_intentos_fallidos = 0, portal_bloqueado_hasta = NULL WHERE id = :id')->execute([':id' => $id]);
$logIntento($id, true);

$case = cast_numeric_fields($row);
unset($case['abogado_nombre'], $case['codigo_acceso'], $case['portal_intentos_fallidos'], $case['portal_bloqueado_hasta']);
$case['id'] = $id;
$case['abogado'] = $row['abogado_nombre'];

$etapas = [];
$q = $pdo->prepare('SELECT * FROM expediente_etapas WHERE expediente_id = :id');
$q->execute([':id' => $id]);
foreach ($q->fetchAll() as $r) {
    $etapas[$r['etapa_key']] = ['fecha' => $r['fecha'], 'hora' => $r['hora'], 'fecha_programada' => $r['fecha_programada'], 'resultado' => $r['resultado']];
}

$pagos = [];
$q = $pdo->prepare('SELECT * FROM expediente_pagos WHERE expediente_id = :id ORDER BY orden ASC, id ASC');
$q->execute([':id' => $id]);
foreach ($q->fetchAll() as $r) {
    $pagos[] = ['fecha' => $r['fecha'], 'monto' => $r['monto'] !== null ? (float)$r['monto'] : null, 'cobrado' => (bool)$r['cobrado'], 'fecha_cobro' => $r['fecha_cobro']];
}

$case['meta'] = [
    'notas' => '',
    'cobro_pendiente' => (bool)$row['cobro_pendiente'],
    'concluido_manual' => (bool)$row['concluido_manual'],
    'amparo_activo' => (bool)$row['amparo_activo'],
    'amparo_presentado' => (bool)$row['amparo_presentado'],
    'amparo_fecha_notif' => $row['amparo_fecha_notif'] ?? '',
    'amparo_notas' => '',
    'convenio_manual' => [
        'activo' => (bool)$row['convenio_activo'],
        'monto' => $row['convenio_monto'] !== null ? (float)$row['convenio_monto'] : '',
        'fecha_pago' => $row['convenio_fecha_pago'] ?? '',
    ],
    'etapas' => $etapas ?: new stdClass(),
    'pagos' => $pagos,
    'pendientes' => [],
    'notas_bitacora' => [],
    'historial' => [],
];

foreach (['notas_internas','cobro_pendiente','concluido_manual','amparo_activo','amparo_presentado',
          'amparo_fecha_notif','amparo_notas','convenio_activo','convenio_monto','convenio_fecha_pago',
          'creado_en','actualizado_en'] as $k) {
    unset($case[$k]);
}

respond(['expediente' => $case]);
