<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$etapas = is_array($in['etapas'] ?? null) ? $in['etapas'] : [];
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
guard_expediente_access($pdo, $user, $id);

// Se necesita el valor ANTERIOR de cada etapa (antes de tocar nada) para
// poder registrar en el historial solo las que de verdad cambiaron --
// log_historial() ya trae su propio guard de "antes === despues", así que
// llamarlo siempre (incluso sin cambio real) es seguro, solo no escribe
// nada si no hubo diferencia.
$existentesStmt = $pdo->prepare('SELECT etapa_key, fecha, hora, fecha_programada, resultado FROM expediente_etapas WHERE expediente_id = :eid');
$existentesStmt->execute([':eid' => $id]);
$existentes = [];
foreach ($existentesStmt->fetchAll() as $r) {
    $existentes[$r['etapa_key']] = $r;
}

$upsert = $pdo->prepare(
    'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha, hora, fecha_programada, resultado)
     VALUES (:eid, :key, :fecha, :hora, :fecha_programada, :resultado)
     ON DUPLICATE KEY UPDATE fecha = VALUES(fecha), hora = VALUES(hora), fecha_programada = VALUES(fecha_programada), resultado = VALUES(resultado)'
);
$delete = $pdo->prepare('DELETE FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key');

foreach (ETAPA_KEYS as $key) {
    $val = $etapas[$key] ?? null;
    $fecha = is_array($val) ? ($val['fecha'] ?? '') : '';
    $hora = is_array($val) ? ($val['hora'] ?? '') : '';
    $fechaProg = is_array($val) ? ($val['fecha_programada'] ?? '') : '';
    $resultado = is_array($val) ? ($val['resultado'] ?? '') : '';
    $fecha = $fecha === '' ? null : $fecha;
    $hora = $hora === '' ? null : $hora;
    $fechaProg = $fechaProg === '' ? null : $fechaProg;
    $resultado = $resultado === '' ? null : $resultado;

    $anteriorTexto = etapa_texto_resumen($existentes[$key] ?? null);
    $nuevaTexto = etapa_texto_resumen(['fecha' => $fecha, 'hora' => $hora, 'fecha_programada' => $fechaProg, 'resultado' => $resultado]);
    log_historial($pdo, $id, $user, 'etapa_' . $key, $anteriorTexto, $nuevaTexto);

    if ($fecha === null && $hora === null && $fechaProg === null && $resultado === null) {
        $delete->execute([':eid' => $id, ':key' => $key]);
        continue;
    }
    $upsert->execute([':eid' => $id, ':key' => $key, ':fecha' => $fecha, ':hora' => $hora, ':fecha_programada' => $fechaProg, ':resultado' => $resultado]);
}

// Si se marcó la etapa de amparo directo con fecha, refleja el estado también
// en las banderas de amparo (igual que hacía el frontend original).
if (!empty($etapas['amparo_directo']['fecha'])) {
    $pdo->prepare('UPDATE expedientes SET amparo_activo = 1, amparo_presentado = 1 WHERE id = :id')->execute([':id' => $id]);
}

// expediente_etapas es una tabla aparte -- guardar aquí NO toca
// expedientes.actualizado_en solo (MySQL no propaga ON UPDATE
// CURRENT_TIMESTAMP entre tablas). Sin este UPDATE explícito, el informe
// ejecutivo con IA (que decide si regenerar comparando actualizado_en
// contra resumen_ejecutivo_generado_en) nunca se enteraría de que cambió
// la bitácora de trámite, y se quedaría mostrando una próxima acción
// vieja aunque el abogado ya haya registrado avances reales.
$pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $id]);

respond(['ok' => true]);
