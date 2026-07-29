<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
$user = require_login();
$pdo = db();
$isAdmin = $user['rol'] === 'administrador';

$where = $isAdmin ? '1=1' : 'e.abogado_id = :uid';
$sql = "SELECT e.*, u.nombre AS abogado_nombre
        FROM expedientes e
        LEFT JOIN usuarios u ON u.id = e.abogado_id
        WHERE $where
        ORDER BY e.id ASC";
$stmt = $pdo->prepare($sql);
if (!$isAdmin) $stmt->execute([':uid' => $user['id']]);
else $stmt->execute();
$expedientesRows = $stmt->fetchAll();

$ids = array_map(fn($r) => (int)$r['id'], $expedientesRows);

$etapasByCase = [];
$pagosByCase = [];
$prestacionesExtraByCase = [];
$pendientesByCase = [];
$notasByCase = [];
$historialByCase = [];

if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));

    $q = $pdo->prepare("SELECT * FROM expediente_etapas WHERE expediente_id IN ($in)");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $etapasByCase[$r['expediente_id']][$r['etapa_key']] = [
            'fecha' => $r['fecha'],
            'fecha_programada' => $r['fecha_programada'],
            'resultado' => $r['resultado'],
        ];
    }

    $q = $pdo->prepare("SELECT * FROM expediente_pagos WHERE expediente_id IN ($in) ORDER BY orden ASC, id ASC");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $pagosByCase[$r['expediente_id']][] = [
            'id' => (int)$r['id'],
            'fecha' => $r['fecha'],
            'monto' => $r['monto'] !== null ? (float)$r['monto'] : null,
            'cobrado' => (bool)$r['cobrado'],
            'fecha_cobro' => $r['fecha_cobro'],
        ];
    }

    $q = $pdo->prepare("SELECT * FROM expediente_prestaciones_extra WHERE expediente_id IN ($in) ORDER BY orden ASC, id ASC");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $prestacionesExtraByCase[$r['expediente_id']][] = [
            'id' => (int)$r['id'],
            'concepto' => $r['concepto'],
            'monto' => $r['monto'] !== null ? (float)$r['monto'] : null,
        ];
    }

    $q = $pdo->prepare("SELECT * FROM expediente_pendientes WHERE expediente_id IN ($in) ORDER BY orden ASC, id ASC");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $pendientesByCase[$r['expediente_id']][] = [
            'id' => (int)$r['id'],
            'descripcion' => $r['descripcion'],
            'fecha_inicio' => $r['fecha_inicio'],
            'dias' => $r['dias'],
            'tipo' => $r['tipo'],
            'cumplido' => (bool)$r['cumplido'],
        ];
    }

    $q = $pdo->prepare("SELECT * FROM expediente_notas WHERE expediente_id IN ($in) ORDER BY creado_en ASC, id ASC");
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $notasByCase[$r['expediente_id']][] = [
            'fecha' => $r['creado_en'],
            'usuario' => $r['usuario_nombre'],
            'texto' => $r['texto'],
        ];
    }

    if ($isAdmin) {
        $q = $pdo->prepare("SELECT * FROM expediente_historial WHERE expediente_id IN ($in) ORDER BY creado_en ASC, id ASC");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) {
            $historialByCase[$r['expediente_id']][] = [
                'ts' => $r['creado_en'],
                'usuario' => $r['usuario_nombre'],
                'campo' => $r['campo'],
                'antes' => $r['antes'],
                'despues' => $r['despues'],
            ];
        }
    }
}

$expedientes = [];
foreach ($expedientesRows as $r) {
    $id = (int)$r['id'];
    $case = cast_numeric_fields($r);
    unset($case['abogado_nombre']);
    $case['id'] = $id;
    $case['abogado'] = $r['abogado_nombre'];
    $case['abogado_id'] = $r['abogado_id'] !== null ? (int)$r['abogado_id'] : null;

    $case['meta'] = [
        'notas' => $r['notas_internas'] ?? '',
        'cobro_pendiente' => (bool)$r['cobro_pendiente'],
        'concluido_manual' => (bool)$r['concluido_manual'],
        'amparo_activo' => (bool)$r['amparo_activo'],
        'amparo_presentado' => (bool)$r['amparo_presentado'],
        'amparo_fecha_notif' => $r['amparo_fecha_notif'] ?? '',
        'amparo_notas' => $r['amparo_notas'] ?? '',
        'convenio_manual' => [
            'activo' => (bool)$r['convenio_activo'],
            'monto' => $r['convenio_monto'] !== null ? (float)$r['convenio_monto'] : '',
            'fecha_pago' => $r['convenio_fecha_pago'] ?? '',
        ],
        'etapas' => $etapasByCase[$id] ?? new stdClass(),
        'pagos' => $pagosByCase[$id] ?? [],
        'prestaciones_extra' => $prestacionesExtraByCase[$id] ?? [],
        'pendientes' => $pendientesByCase[$id] ?? [],
        'notas_bitacora' => $notasByCase[$id] ?? [],
        'historial' => $historialByCase[$id] ?? [],
    ];

    // Estas columnas ya viven aparte en meta.*; no duplicarlas en el nivel superior.
    foreach (['notas_internas','cobro_pendiente','concluido_manual','amparo_activo','amparo_presentado',
              'amparo_fecha_notif','amparo_notas','convenio_activo','convenio_monto','convenio_fecha_pago',
              'codigo_acceso','portal_intentos_fallidos','portal_bloqueado_hasta','creado_en','actualizado_en'] as $k) {
        unset($case[$k]);
    }

    $expedientes[] = $case;
}

$equipoStmt = $pdo->query('SELECT id, nombre, rol FROM usuarios WHERE activo = 1 ORDER BY nombre ASC');
$equipo = [];
foreach ($equipoStmt->fetchAll() as $r) {
    $equipo[] = ['id' => (int)$r['id'], 'nombre' => $r['nombre'], 'rol' => $r['rol']];
}

respond([
    'expedientes' => $expedientes,
    'equipo' => $equipo,
]);
