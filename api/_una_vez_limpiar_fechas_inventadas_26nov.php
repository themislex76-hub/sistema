<?php
declare(strict_types=1);

// Script de UN SOLO USO (26 nov 2026) -- NO se deja programado, se corre
// una vez abriendo la URL directo y se puede borrar después (mismo patrón
// que api/_una_vez_reprocesar_calendario_legacy.php). Pedido explícito del
// usuario tras ver el reporte api/reporte_fechas_celebradas_sospechosas.php
// (45 etapas sospechosas, 12 de sospecha ALTA): "si" (limpiar las de
// sospecha ALTA).
//
// Repite EXACTAMENTE la misma detección que el reporte (misma firma del
// bug: un humano guardó una fecha celebrada que coincide con el día del
// guardado, y en ESE MISMO guardado se tocaron 2+ etapas de golpe -- la
// firma de "Guardar etapas" guardando el formulario completo por otra
// razón) pero SOLO para las de sospecha ALTA (tamaño de lote >= 2) --
// las de sospecha media se quedan para que el usuario las revise a mano
// con calma, con más riesgo de ser legítimas.
//
// Solo BORRA la fecha celebrada inventada (deja la etapa en blanco, nunca
// pone una fecha nueva -- no se sabe cuál es la real) -- si con eso la
// fila de la etapa queda completamente vacía (sin hora/programada/
// resultado tampoco), se borra la fila entera, igual que ya hace
// expedientes_update_etapas.php. Queda registrado en el Historial de
// cambios de cada expediente para que se pueda auditar o, si alguien SÍ
// sabe la fecha real, capturarla después desde "Etapas del juicio".

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';

$pdo = db();
$usuarioCorreccion = ['id' => null, 'nombre' => 'Corrección automática (fecha probablemente inventada, revisión 26 nov 2026)'];

$expStmt = $pdo->query(
    "SELECT id, exp, actor, demandado, status FROM expedientes WHERE concluido_manual = 0"
);
$expedientes = [];
foreach ($expStmt->fetchAll() as $r) {
    $s = strtolower((string)($r['status'] ?? ''));
    if (strpos($s, 'pagado') !== false || strpos($s, 'ya no quiso') !== false) continue;
    $expedientes[(int)$r['id']] = $r;
}

$histStmt = $pdo->query(
    "SELECT expediente_id, usuario_id, usuario_nombre, campo, despues, creado_en
     FROM expediente_historial
     WHERE usuario_id IS NOT NULL AND campo LIKE 'etapa_%'
     ORDER BY creado_en DESC"
);
$historial = $histStmt->fetchAll();

$loteConteo = [];
foreach ($historial as $h) {
    $loteKey = $h['expediente_id'] . '|' . $h['usuario_id'] . '|' . $h['creado_en'];
    $loteConteo[$loteKey][$h['campo']] = true;
}

$candidatas = [];
$vistos = [];
foreach ($historial as $h) {
    $dedupeKey = $h['expediente_id'] . '|' . $h['campo'];
    if (isset($vistos[$dedupeKey])) continue;
    $vistos[$dedupeKey] = true;

    if (!preg_match('/^fecha:\s*(\d{4}-\d{2}-\d{2})/', (string)$h['despues'], $m)) continue;
    $fechaGuardada = $m[1];
    $diaGuardado = substr((string)$h['creado_en'], 0, 10);
    if ($fechaGuardada !== $diaGuardado) continue;

    $expedienteId = (int)$h['expediente_id'];
    if (!isset($expedientes[$expedienteId])) continue;

    $etapaKey = substr($h['campo'], strlen('etapa_'));
    if (!in_array($etapaKey, ETAPA_KEYS, true)) continue;

    $loteKey = $h['expediente_id'] . '|' . $h['usuario_id'] . '|' . $h['creado_en'];
    $tamanoLote = count($loteConteo[$loteKey] ?? []);
    if ($tamanoLote < 2) continue; // solo sospecha ALTA en este script de limpieza

    $candidatas[] = [
        'expediente' => $expedientes[$expedienteId],
        'etapa_key' => $etapaKey,
        'fecha' => $fechaGuardada,
        'tamano_lote' => $tamanoLote,
    ];
}

$labels = defined('ETAPA_LABELS') ? ETAPA_LABELS : [];
$limpiadas = [];
foreach ($candidatas as $c) {
    $eid = (int)$c['expediente']['id'];
    $key = $c['etapa_key'];

    $actualStmt = $pdo->prepare('SELECT fecha, hora, fecha_programada, resultado FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key');
    $actualStmt->execute([':eid' => $eid, ':key' => $key]);
    $actual = $actualStmt->fetch();
    if (!$actual || $actual['fecha'] !== $c['fecha']) continue; // ya se corrigió desde que se generó el reporte -- no tocar

    $antesTexto = etapa_texto_resumen($actual);
    $quedaVacia = empty($actual['hora']) && empty($actual['fecha_programada']) && empty($actual['resultado']);

    if ($quedaVacia) {
        $pdo->prepare('DELETE FROM expediente_etapas WHERE expediente_id = :eid AND etapa_key = :key')
            ->execute([':eid' => $eid, ':key' => $key]);
    } else {
        $pdo->prepare('UPDATE expediente_etapas SET fecha = NULL WHERE expediente_id = :eid AND etapa_key = :key')
            ->execute([':eid' => $eid, ':key' => $key]);
    }

    $despuesTexto = etapa_texto_resumen([
        'fecha' => null, 'hora' => $actual['hora'], 'fecha_programada' => $actual['fecha_programada'], 'resultado' => $actual['resultado'],
    ]);
    log_historial(
        $pdo, $eid, $usuarioCorreccion, 'etapa_' . $key, $antesTexto,
        ($despuesTexto ?: '(etapa vaciada)') . " -- se borró \"fecha: {$c['fecha']}\" por parecer inventada (checkbox marcado sin fecha, {$c['tamano_lote']} etapas guardadas de golpe el mismo día)"
    );
    $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $eid]);

    $limpiadas[] = $c['expediente']['exp'] . ' -- ' . $c['expediente']['actor'] . ' (' . ($labels[$key] ?? $key) . ', era ' . $c['fecha'] . ')';
}

header('Content-Type: text/plain; charset=utf-8');
echo count($candidatas) . " etapa(s) de sospecha ALTA encontrada(s), " . count($limpiadas) . " limpiada(s) de verdad (las demás ya habían cambiado desde el reporte).\n\n";
foreach ($limpiadas as $linea) echo "- $linea\n";
