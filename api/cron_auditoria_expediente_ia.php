<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (DonWeb/cPanel) una vez
// al día -- NO se abre desde el navegador ni tiene sesión. Pedido explícito
// del usuario (10 nov 2026): "la ia tiene que leer toda la info del
// expediente y modificar todo... acuerdate que la intencion es que el
// sistema sea lo mas autosuficiente posible". A diferencia de los otros
// disparadores que ya existen (cron_analizar_acuerdos_ia.php,
// cron_analizar_documentos_ia.php, la respuesta del abogado al supervisor)
// -- que llenan una etapa sola mirando SOLO la pieza de información que
// acaba de llegar -- este cron hace una auditoría integral: por cada
// expediente activo en juicio, le manda a la IA TODO el contexto ya
// guardado junto (bitácora de etapas, documentos+resúmenes, avisos de
// boletín+resúmenes, preguntas/respuestas del supervisor) y busca una
// etapa sin fecha donde, cruzando varias de esas fuentes, sí hay una fecha
// mencionada literalmente -- algo que ningún disparador puntual, mirando
// una sola fuente a la vez, alcanza a detectar.
//
// Mismo principio de siempre: nunca calcula/infiere plazos, nunca
// sobrescribe una etapa que ya tiene fecha capturada, y queda registrado
// en log_historial como llenado automático (auditable/corregible a mano).
// Solo se vuelve a auditar un expediente si cambió algo desde la última
// vez (columna ultima_auditoria_ia, migración 064) -- mismo patrón que ya
// usa el informe ejecutivo (resumen_ejecutivo_generado_en) para no pagar
// de más en expedientes que no se movieron.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez al día (puede ir junto con
// cron_generar_preguntas_supervisor.php).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

const LOTE_MAXIMO = 20;

$pdo = db();

// Candidatos: activos (no concluidos a mano), con al menos una etapa ya
// registrada (si no tiene ninguna, todavía no está en juicio -- nada que
// auditar aquí) y que cambiaron desde la última auditoría (o nunca se han
// auditado).
$stmt = $pdo->query(
    "SELECT e.id, e.actor, e.demandado, e.exp, e.status
     FROM expedientes e
     WHERE e.concluido_manual = 0
       AND (e.ultima_auditoria_ia IS NULL OR e.ultima_auditoria_ia < e.actualizado_en)
       AND EXISTS (SELECT 1 FROM expediente_etapas et WHERE et.expediente_id = e.id)
     ORDER BY e.actualizado_en ASC
     LIMIT " . LOTE_MAXIMO
);
$candidatos = $stmt->fetchAll();

// Mismo criterio de "activo" que el resto del sistema (ver
// reporte_monitoreo_faltante.php) -- concluido_manual=0 no basta solo.
$candidatos = array_values(array_filter($candidatos, function ($r) {
    $s = strtolower((string)($r['status'] ?? ''));
    return strpos($s, 'pagado') === false && strpos($s, 'ya no quiso') === false;
}));

if (!$candidatos) {
    echo "Sin expedientes pendientes de auditar.\n";
    exit;
}

$auditados = 0;
$aplicados = 0;
$fallidos = 0;

foreach ($candidatos as $c) {
    $expStmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $expStmt->execute([':id' => $c['id']]);
    $expediente = $expStmt->fetch();
    if (!$expediente) continue;

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $c['id']]);
    $etapas = $etapasStmt->fetchAll();

    $docsStmt = $pdo->prepare('SELECT nombre_archivo, categoria, ia_resumen FROM expediente_documentos WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 12');
    $docsStmt->execute([':id' => $c['id']]);
    $documentosRecientes = $docsStmt->fetchAll();

    $avisosStmt = $pdo->prepare('SELECT resumen, ia_resumen, creado_en FROM avisos_boletin WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 10');
    $avisosStmt->execute([':id' => $c['id']]);
    $avisosRecientes = $avisosStmt->fetchAll();

    $preguntasStmt = $pdo->prepare("SELECT pregunta, respuesta FROM supervisor_preguntas WHERE expediente_id = :id AND estado IN ('respondida','aplicada') ORDER BY creado_en DESC LIMIT 10");
    $preguntasStmt->execute([':id' => $c['id']]);
    $preguntasRecientes = $preguntasStmt->fetchAll();

    $sugerencias = ia_auditar_expediente($expediente, $etapas, $documentosRecientes, $avisosRecientes, $preguntasRecientes);
    $auditados++;

    if ($sugerencias === null) {
        $fallidos++;
        continue; // se reintenta en la siguiente corrida (ultima_auditoria_ia se queda sin tocar)
    }

    $huboFill = false;
    foreach ($sugerencias as $s) {
        // Revisión final: nunca confiar en que la IA haya respetado la
        // instrucción de no sugerir una etapa que ya tiene fecha -- se
        // vuelve a checar contra lo que de verdad hay en la base antes de
        // escribir nada.
        $yaTieneFecha = false;
        foreach ($etapas as $e) {
            if ($e['etapa_key'] === $s['etapa_key'] && !empty($e['fecha']) && $e['fecha'] !== '0000-00-00') {
                $yaTieneFecha = true;
                break;
            }
        }
        if ($yaTieneFecha) continue;

        // Confirmado real (10 nov 2026): la IA puso "Objeciones y réplica"
        // con una fecha ANTERIOR a "Contestación de demanda recibida" --
        // imposible por ley (Art. 873-B LFT). Vio una fecha real en el
        // contexto pero la atribuyó a la etapa equivocada. Se descarta
        // cualquier sugerencia que rompa el orden cronológico real contra
        // las demás etapas ya registradas, en vez de aplicarla a ciegas.
        if (!etapa_fecha_es_consistente($s['etapa_key'], $s['fecha'], $etapas)) {
            // Campo SIN el prefijo "etapa_" a propósito -- expediente_historial
            // alimenta el conteo de "avances" de la semana en el frontend
            // (metricsResumenSemanal(), filtra por /^etapa_/), y un rechazo
            // no es un avance real; se deja registrado aparte, solo para
            // auditoría/depuración.
            log_historial(
                $pdo, (int)$c['id'], ['id' => null, 'nombre' => 'IA (auditoría integral)'],
                'auditoria_ia_rechazo_' . $s['etapa_key'], null,
                "fecha sugerida {$s['fecha']} rechazada por romper el orden cronológico con otras etapas ya registradas" . ($s['motivo'] !== '' ? " (motivo de la IA: {$s['motivo']})" : '')
            );
            continue;
        }

        $pdo->prepare(
            'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha)
             VALUES (:eid, :key, :fecha)
             ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)'
        )->execute([':eid' => $c['id'], ':key' => $s['etapa_key'], ':fecha' => $s['fecha']]);

        $motivoTexto = $s['motivo'] !== '' ? " ({$s['motivo']})" : '';
        log_historial(
            $pdo, (int)$c['id'], ['id' => null, 'nombre' => 'IA (auditoría integral)'],
            'etapa_' . $s['etapa_key'], null,
            "fecha: {$s['fecha']} (llenado automático por auditoría integral{$motivoTexto})"
        );
        // Se refleja en el arreglo en memoria -- si la IA sugirió varias
        // etapas en la misma corrida, las siguientes se revisan contra
        // esta ya aplicada, no solo contra lo que había al principio.
        $etapas[] = ['etapa_key' => $s['etapa_key'], 'fecha' => $s['fecha']];
        $huboFill = true;
        $aplicados++;
    }

    // ultima_auditoria_ia en la MISMA sentencia que actualizado_en cuando
    // hubo un cambio (ambas quedan con el mismo NOW() de MySQL) -- así la
    // comparación de la consulta de arriba ("ultima_auditoria_ia <
    // actualizado_en") da falso de inmediato y este expediente no se
    // vuelve a seleccionar hasta que cambie algo más.
    if ($huboFill) {
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW(), ultima_auditoria_ia = NOW() WHERE id = :id')->execute([':id' => $c['id']]);
    } else {
        $pdo->prepare('UPDATE expedientes SET ultima_auditoria_ia = NOW() WHERE id = :id')->execute([':id' => $c['id']]);
    }
}

echo count($candidatos) . " expediente(s) candidato(s), " . $auditados . " auditado(s) con IA, "
    . $aplicados . " etapa(s) llenada(s) sola(s), " . $fallidos . " fallido(s) (se reintentan después).\n";
