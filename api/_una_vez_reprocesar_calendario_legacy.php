<?php
declare(strict_types=1);

// Script de UN SOLO USO (11 nov 2026) -- NO se deja programado, se corre
// una vez abriendo la URL directo y se borra después. Resuelve las
// sugerencias de Google Calendar que quedaron en estado 'pendiente' con
// el código VIEJO (antes de quitar el tap de confirmación): bajo la
// lógica nueva, si esa etapa no tenía ya una fecha agendada distinta, se
// hubieran aplicado solas -- esto las pone al día sin que el abogado
// tenga que tocar cada una a mano. Las que SÍ tienen un conflicto real
// (la etapa ya tenía otra fecha agendada) se dejan igual, pendientes de
// un tap -- eso no cambia.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';

$pdo = db();
$usuarioIA = ['id' => null, 'nombre' => 'IA (Google Calendar)'];

$stmt = $pdo->query(
    "SELECT s.*, ee.fecha_programada AS fecha_actual
     FROM google_calendario_sugerencias s
     LEFT JOIN expediente_etapas ee ON ee.expediente_id = s.expediente_id AND ee.etapa_key = s.etapa_key
     WHERE s.estado = 'pendiente'"
);
$filas = $stmt->fetchAll();

if (!$filas) {
    echo "Nada que reprocesar -- no hay sugerencias 'pendiente' legacy.\n";
    exit;
}

$aplicados = 0;
$rechazados = 0;
$dejadosPendientes = 0;

foreach ($filas as $s) {
    $yaProgramada = (!empty($s['fecha_actual']) && $s['fecha_actual'] !== '0000-00-00') ? $s['fecha_actual'] : null;

    if ($yaProgramada !== null && $yaProgramada !== $s['fecha']) {
        $dejadosPendientes++; // conflicto real -- sí necesita el tap, se deja igual
        continue;
    }

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :eid');
    $etapasStmt->execute([':eid' => $s['expediente_id']]);
    $etapas = $etapasStmt->fetchAll();

    if (!etapa_fecha_es_consistente($s['etapa_key'], $s['fecha'], $etapas)) {
        $pdo->prepare("UPDATE google_calendario_sugerencias SET estado = 'rechazada', resuelto_en = NOW() WHERE id = :id")
            ->execute([':id' => $s['id']]);
        log_historial(
            $pdo, (int)$s['expediente_id'], $usuarioIA,
            'calendario_ia_rechazo_' . $s['etapa_key'], null,
            "fecha agendada {$s['fecha']} rechazada por romper el orden cronológico (reprocesado, evento: \"{$s['titulo_evento']}\")"
        );
        $rechazados++;
        continue;
    }

    $pdo->prepare(
        'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha_programada, hora)
         VALUES (:eid, :key, :fecha, :hora)
         ON DUPLICATE KEY UPDATE fecha_programada = VALUES(fecha_programada), hora = VALUES(hora)'
    )->execute([
        ':eid' => $s['expediente_id'],
        ':key' => $s['etapa_key'],
        ':fecha' => $s['fecha'],
        ':hora' => $s['hora'],
    ]);
    log_historial(
        $pdo, (int)$s['expediente_id'], $usuarioIA,
        'etapa_' . $s['etapa_key'], null,
        "fecha agendada: {$s['fecha']} (llenado automático desde Google Calendar, reprocesado: \"{$s['titulo_evento']}\")"
    );
    $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $s['expediente_id']]);
    $pdo->prepare("UPDATE google_calendario_sugerencias SET estado = 'aplicada', resuelto_en = NOW() WHERE id = :id")
        ->execute([':id' => $s['id']]);
    $aplicados++;
}

echo "$aplicados sugerencia(s) aplicada(s) sola(s), $rechazados rechazada(s) por orden cronológico, "
    . "$dejadosPendientes dejada(s) pendiente(s) por conflicto real (sí necesitan tap).\n";
