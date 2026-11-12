<?php
declare(strict_types=1);

// Panel "Posibles irregularidades" (12 nov 2026, pedido explícito del
// usuario: "tenemos que hacer que la ia sea muy efectiva revisando los
// juicios para detectar irregularidades, atrasos, términos, llenar de
// manera correcta el sistema"). Script pensado para correr solo, vía un
// Cron Job (cPanel), UNA VEZ A LA SEMANA (no diario como el resto -- esto
// revisa lo que YA está registrado, no hay prisa por detectarlo el mismo
// día) -- NO se abre desde el navegador ni tiene sesión.
//
// A diferencia de cron_auditoria_expediente_ia.php (que llena huecos sin
// fecha), este NUNCA corrige nada solo -- solo genera una alerta de un
// tap para el abogado ("Corregir" lo lleva a Etapas del juicio / "Está
// bien, no es error" descarta), con 2 chequeos deliberadamente baratos y
// conservadores para no generarle trabajo de revisión de más:
//
// 1. Orden cronológico roto entre etapas YA registradas (sin IA, gratis)
//    -- candado que ya existía para llenados automáticos
//    (etapa_fecha_es_consistente()) pero nunca se aplicaba sobre captura
//    MANUAL del abogado. Se recalcula completo cada corrida: si una
//    violación ya marcada deja de existir (el abogado la corrigió), se
//    cierra sola como 'corregida'; si el abogado ya dijo "está bien, no
//    es error" y la situación no cambió, no se le vuelve a mostrar.
//
// 2. "Segunda opinión" de IA SOLO sobre etapas que un mecanismo
//    automático llenó solo (expediente_historial.usuario_id IS NULL,
//    campo LIKE 'etapa_%') -- nunca sobre captura manual del abogado (ahí
//    confiamos en su criterio). Usa el mismo prompt reforzado hoy tras el
//    bug real de Alan Campuzano Brito. Se marca qa_verificado_en una sola
//    vez por fila de historial para no re-pagar la misma revisión.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez a la semana (ej. lunes en la madrugada).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/push_helpers.php';

const LOTE_VERIFICACION_IA = 25;

$pdo = db();

// Mismo criterio de "activo" que ya usan los demás crons de este tipo
// (concluido_manual=0 no basta solo).
$stmt = $pdo->query(
    "SELECT e.id, e.actor, e.demandado, e.exp, e.status, e.abogado_id, e.abogado_id_2
     FROM expedientes e
     WHERE e.concluido_manual = 0
       AND EXISTS (SELECT 1 FROM expediente_etapas et WHERE et.expediente_id = e.id)"
);
$candidatos = array_values(array_filter($stmt->fetchAll(), function ($r) {
    $s = strtolower((string)($r['status'] ?? ''));
    return strpos($s, 'pagado') === false && strpos($s, 'ya no quiso') === false;
}));

$nuevasPorExpediente = []; // expediente_id => [motivos cortos...] (para el push)

$upsertIrregularidad = function (int $expedienteId, ?string $etapaKey, string $tipo, string $motivo, ?string $fechaDetectada) use ($pdo, &$nuevasPorExpediente) {
    $stmt = $pdo->prepare(
        'SELECT id, estado, motivo FROM expediente_irregularidades
         WHERE expediente_id = :eid AND tipo = :tipo AND (etapa_key = :key OR (etapa_key IS NULL AND :key2 IS NULL))'
    );
    $stmt->execute([':eid' => $expedienteId, ':tipo' => $tipo, ':key' => $etapaKey, ':key2' => $etapaKey]);
    $existente = $stmt->fetch();

    if (!$existente) {
        $pdo->prepare(
            'INSERT INTO expediente_irregularidades (expediente_id, etapa_key, tipo, motivo, fecha_detectada, estado)
             VALUES (:eid, :key, :tipo, :motivo, :fecha, \'pendiente\')'
        )->execute([':eid' => $expedienteId, ':key' => $etapaKey, ':tipo' => $tipo, ':motivo' => $motivo, ':fecha' => $fechaDetectada]);
        $nuevasPorExpediente[$expedienteId][] = $motivo;
        return;
    }

    if ($existente['estado'] === 'pendiente') {
        // Ya está visible -- se actualiza el motivo por si cambió, sin volver a avisar.
        if ($existente['motivo'] !== $motivo) {
            $pdo->prepare('UPDATE expediente_irregularidades SET motivo = :motivo, fecha_detectada = :fecha WHERE id = :id')
                ->execute([':motivo' => $motivo, ':fecha' => $fechaDetectada, ':id' => $existente['id']]);
        }
        return;
    }

    // Estaba 'corregida' o 'descartada': si el motivo es idéntico al que ya
    // se resolvió, se respeta esa decisión (no se vuelve a molestar). Si
    // cambió (algo nuevo pasó), se reabre.
    if ($existente['motivo'] !== $motivo) {
        $pdo->prepare(
            "UPDATE expediente_irregularidades SET estado = 'pendiente', motivo = :motivo, fecha_detectada = :fecha,
             resuelto_en = NULL, resuelto_por = NULL WHERE id = :id"
        )->execute([':motivo' => $motivo, ':fecha' => $fechaDetectada, ':id' => $existente['id']]);
        $nuevasPorExpediente[$expedienteId][] = $motivo;
    }
};

// --- 1. Orden cronológico (gratis, sin IA) ------------------------------
$revisados = 0;
foreach ($candidatos as $c) {
    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $c['id']]);
    $etapas = $etapasStmt->fetchAll();
    $revisados++;

    $violaciones = detectar_irregularidades_orden_cronologico($etapas);
    foreach ($violaciones as $etapaKey => $motivo) {
        $fechaDetectada = null;
        foreach ($etapas as $e) {
            if ($e['etapa_key'] === $etapaKey) { $fechaDetectada = $e['fecha']; break; }
        }
        $upsertIrregularidad((int)$c['id'], $etapaKey, 'orden_cronologico', $motivo, $fechaDetectada);
    }

    // Auto-cerrar las que ya no aplican (el abogado corrigió la fecha).
    $pendientesStmt = $pdo->prepare(
        "SELECT id, etapa_key FROM expediente_irregularidades
         WHERE expediente_id = :eid AND tipo = 'orden_cronologico' AND estado = 'pendiente'"
    );
    $pendientesStmt->execute([':eid' => $c['id']]);
    foreach ($pendientesStmt->fetchAll() as $p) {
        if (!isset($violaciones[$p['etapa_key']])) {
            $pdo->prepare("UPDATE expediente_irregularidades SET estado = 'corregida', resuelto_en = NOW() WHERE id = :id")
                ->execute([':id' => $p['id']]);
        }
    }
}

// --- 2. Segunda opinión de IA sobre llenados automáticos ----------------
$candidatosPorId = [];
foreach ($candidatos as $c) $candidatosPorId[(int)$c['id']] = $c;

// Confirmado real (12 nov 2026, expediente de Luis Enrique Aguilar Mateos
// y otros): cron_leer_google_calendar.php también deja historial con
// usuario_id NULL y campo LIKE 'etapa_%', pero su texto es "fecha
// agendada: ..." -- llena fecha_programada (un citatorio AGENDADO a
// futuro), no fecha (la celebrada). Esta "segunda opinión" solo tiene
// sentido para confirmaciones de que un evento YA ocurrió -- comparada
// contra fecha_programada, siempre "falla" (nunca va a decir que ya se
// celebró algo que apenas se está agendando), generando falsos positivos
// sistemáticos. Se excluyen explícitamente estas filas.
$historialStmt = $pdo->prepare(
    "SELECT h.id, h.expediente_id, h.campo, h.despues
     FROM expediente_historial h
     WHERE h.usuario_id IS NULL AND h.campo LIKE 'etapa_%' AND h.qa_verificado_en IS NULL
       AND h.despues NOT LIKE 'fecha agendada:%'
     ORDER BY h.creado_en ASC
     LIMIT " . LOTE_VERIFICACION_IA
);
$historialStmt->execute();
$filasHistorial = $historialStmt->fetchAll();

$verificadas = 0;
$sospechosas = 0;
$fallidas = 0;

foreach ($filasHistorial as $h) {
    $etapaKey = substr($h['campo'], strlen('etapa_'));
    if (!in_array($etapaKey, ETAPA_KEYS, true)) {
        // formato inesperado (ej. 'etapa_x_rechazo_y') -- no aplica, se marca visto y se omite
        $pdo->prepare('UPDATE expediente_historial SET qa_verificado_en = NOW() WHERE id = :id')->execute([':id' => $h['id']]);
        continue;
    }
    $expedienteId = (int)$h['expediente_id'];
    if (!isset($candidatosPorId[$expedienteId])) {
        // expediente concluido/pagado desde entonces -- ya no aplica revisarlo
        $pdo->prepare('UPDATE expediente_historial SET qa_verificado_en = NOW() WHERE id = :id')->execute([':id' => $h['id']]);
        continue;
    }

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $expedienteId]);
    $etapas = $etapasStmt->fetchAll();

    $fechaActual = null;
    foreach ($etapas as $e) {
        if ($e['etapa_key'] === $etapaKey) { $fechaActual = $e['fecha']; break; }
    }
    if (!$fechaActual || $fechaActual === '0000-00-00') {
        // el abogado ya borró/cambió esta etapa desde entonces -- nada que verificar
        $pdo->prepare('UPDATE expediente_historial SET qa_verificado_en = NOW() WHERE id = :id')->execute([':id' => $h['id']]);
        continue;
    }

    $expStmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $expStmt->execute([':id' => $expedienteId]);
    $expediente = $expStmt->fetch();
    if (!$expediente) continue;

    $docsStmt = $pdo->prepare('SELECT nombre_archivo, categoria, ia_resumen FROM expediente_documentos WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 12');
    $docsStmt->execute([':id' => $expedienteId]);
    $documentosRecientes = $docsStmt->fetchAll();

    $avisosStmt = $pdo->prepare('SELECT resumen, ia_resumen, creado_en FROM avisos_boletin WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 10');
    $avisosStmt->execute([':id' => $expedienteId]);
    $avisosRecientes = $avisosStmt->fetchAll();

    $preguntasStmt = $pdo->prepare("SELECT pregunta, respuesta FROM supervisor_preguntas WHERE expediente_id = :id AND estado IN ('respondida','aplicada') ORDER BY creado_en DESC LIMIT 10");
    $preguntasStmt->execute([':id' => $expedienteId]);
    $preguntasRecientes = $preguntasStmt->fetchAll();

    $resultado = ia_verificar_fecha_etapa_llenada_sola(
        $etapaKey, $fechaActual, (string)$h['despues'], $expediente, $etapas,
        $documentosRecientes, $avisosRecientes, $preguntasRecientes
    );
    $verificadas++;

    if ($resultado === null) {
        $fallidas++;
        continue; // se reintenta la siguiente corrida (qa_verificado_en se queda sin tocar)
    }

    $pdo->prepare('UPDATE expediente_historial SET qa_verificado_en = NOW() WHERE id = :id')->execute([':id' => $h['id']]);

    if ($resultado['sospechosa']) {
        $sospechosas++;
        $upsertIrregularidad($expedienteId, $etapaKey, 'fecha_sin_respaldo', (string)$resultado['motivo'], $fechaActual);
    }
}

// --- Push, uno por expediente con novedades -----------------------------
foreach ($nuevasPorExpediente as $expedienteId => $motivos) {
    $c = $candidatosPorId[$expedienteId] ?? null;
    if (!$c) continue;
    $caso = trim(($c['actor'] ?? '') . ' vs ' . ($c['demandado'] ?? ''), ' vs');
    $n = count($motivos);
    $cuerpo = $n === 1
        ? $motivos[0]
        : "$n posibles irregularidades encontradas -- entra a revisarlas.";
    $abogadosDestino = array_filter([(int)($c['abogado_id'] ?? 0), (int)($c['abogado_id_2'] ?? 0)]);
    foreach (array_unique($abogadosDestino) as $abogadoId) {
        push_enviar_a_usuario(
            $pdo, $abogadoId, '⚠️ Posible irregularidad detectada',
            "$caso: $cuerpo", '/sistema/?irregularidad=1'
        );
    }
}

echo count($candidatos) . " expediente(s) activo(s) revisado(s) por orden cronológico, "
    . $verificadas . " llenado(s) automático(s) verificado(s) con IA (" . $sospechosas . " sospechoso(s), "
    . $fallidas . " fallido(s)), " . count($nuevasPorExpediente) . " expediente(s) con novedades nuevas.\n";
