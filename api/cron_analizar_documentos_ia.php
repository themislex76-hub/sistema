<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (cada 15-30 minutos,
// junto con cron_analizar_acuerdos_ia.php) -- NO se abre desde el
// navegador ni tiene sesión. Lee con IA CUALQUIER documento que un
// abogado suba a mano en la pestaña "Documentos" (constancias, convenios,
// pruebas, cédulas de notificación, fotos...), para que se vea un
// resumen de qué trata sin tener que abrirlo -- pedido explícito del
// usuario ("sería excelente que en documentos se agregue un resumen del
// documento a un lado para ver de qué se trata").
//
// Si el documento ya llegó de un robot de boletín (tiene un aviso ligado
// en avisos_boletin, ya analizado por cron_analizar_acuerdos_ia.php), NO
// se vuelve a mandar a la IA -- se copia ese resultado, para no pagar dos
// veces por el mismo archivo.
//
// Solo puede leer PDF, JPG y PNG (lo único que la API de Anthropic recibe
// como documento/imagen nativo) -- Word/Excel/txt se marcan como
// analizados sin resumen, para no reintentarlos en cada corrida.
//
// Mismo principio de siempre sobre llenar la bitácora de "Etapas del
// juicio" sola: si la IA sugiere una etapa Y el documento menciona una
// fecha literal, y esa etapa todavía no tiene fecha capturada, se llena
// sola (nunca sobrescribe lo que el abogado ya registró a mano). A
// diferencia del cron de boletín, aquí NUNCA se usa la fecha de SUBIDA
// como respaldo -- un documento se puede subir mucho después de que pasó
// lo que acredita, así que sin fecha explícita en el documento no se
// aplica nada solo (el resumen sigue siendo útil aunque no se aplique).
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: cada 15-30 minutos.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

const LOTE_MAXIMO = 15;
const EXTENSIONES_LEGIBLES = ['pdf', 'jpg', 'jpeg', 'png'];

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT d.id, d.expediente_id, d.nombre_archivo, d.nombre_disco, d.tipo_mime, d.categoria,
            ab.id AS aviso_id, ab.ia_resumen AS aviso_ia_resumen, ab.ia_etapa_sugerida AS aviso_ia_etapa,
            ab.ia_fecha_mencionada AS aviso_ia_fecha, ab.ia_aplicado AS aviso_ia_aplicado
     FROM expediente_documentos d
     LEFT JOIN avisos_boletin ab ON ab.documento_id = d.id
     WHERE d.ia_analizado_en IS NULL AND (ab.id IS NULL OR ab.ia_analizado_en IS NOT NULL)
     ORDER BY d.creado_en ASC
     LIMIT " . LOTE_MAXIMO
);
$stmt->execute();
$documentos = $stmt->fetchAll();

if (!$documentos) {
    echo "Sin documentos pendientes de analizar.\n";
    exit;
}

$analizados = 0;
$copiados = 0;
$aplicados = 0;
$omitidos = 0;
$fallidos = 0;
$sugerenciasCategoria = 0;

foreach ($documentos as $doc) {
    // Caso 1: ya viene de un robot de boletín y ya se analizó ahí --
    // copiar en vez de volver a pagar por la misma lectura.
    if ($doc['aviso_id']) {
        $pdo->prepare(
            'UPDATE expediente_documentos
             SET ia_resumen = :resumen, ia_etapa_sugerida = :etapa, ia_fecha_mencionada = :fecha, ia_aplicado = :aplicado, ia_analizado_en = NOW()
             WHERE id = :id'
        )->execute([
            ':resumen' => $doc['aviso_ia_resumen'],
            ':etapa' => $doc['aviso_ia_etapa'],
            ':fecha' => $doc['aviso_ia_fecha'],
            ':aplicado' => $doc['aviso_ia_aplicado'],
            ':id' => $doc['id'],
        ]);
        // El resumen copiado también es información nueva para el Informe
        // Ejecutivo (lee expediente_documentos.ia_resumen) -- sin esto,
        // un documento sin etapa que llenar nunca quedaba reflejado ahí
        // (mismo hueco real que en cron_analizar_acuerdos_ia.php).
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['expediente_id']]);
        $copiados++;
        continue;
    }

    $ext = strtolower(pathinfo($doc['nombre_archivo'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSIONES_LEGIBLES, true)) {
        // Word/Excel/txt -- no se puede leer con este mecanismo. Se marca
        // analizado para no reintentarlo cada corrida.
        $pdo->prepare('UPDATE expediente_documentos SET ia_analizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['id']]);
        $omitidos++;
        continue;
    }

    $ruta = documentos_dir((int)$doc['expediente_id']) . '/' . $doc['nombre_disco'];

    $expStmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $expStmt->execute([':id' => $doc['expediente_id']]);
    $expediente = $expStmt->fetch();
    if (!$expediente) continue; // expediente borrado -- no debería pasar, pero no truena el lote por esto

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha, fecha_programada FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $doc['expediente_id']]);
    $etapas = $etapasStmt->fetchAll();

    $resultado = ia_analizar_acuerdo_boletin($ruta, $doc['tipo_mime'], $expediente, $etapas, $doc['nombre_archivo'], $doc['categoria']);
    $analizados++;
    if (!$resultado) {
        $fallidos++;
        continue; // se reintenta en la siguiente corrida (ia_analizado_en se queda NULL)
    }

    $aplicado = false;
    // A diferencia del cron de boletín, aquí se exige una fecha mencionada
    // LITERALMENTE en el documento -- nunca se usa la fecha de subida como
    // respaldo (un documento se sube cuando sea, no necesariamente el
    // mismo día de lo que acredita).
    if ($resultado['etapa_sugerida'] && $resultado['fecha_mencionada']) {
        $yaTieneFecha = false;
        foreach ($etapas as $e) {
            if ($e['etapa_key'] === $resultado['etapa_sugerida'] && !empty($e['fecha']) && $e['fecha'] !== '0000-00-00') {
                $yaTieneFecha = true;
                break;
            }
        }
        $usuarioLog = ['id' => null, 'nombre' => 'IA (lectura automática de documento)'];
        $fechaEtapa = $resultado['fecha_mencionada'];

        if (!$yaTieneFecha && etapa_fecha_es_futura($fechaEtapa)) {
            // Confirmado real (19 nov 2026): un acuerdo real señaló una
            // audiencia FUTURA (11 de enero de 2027) y por poco se
            // guardaba como "ya celebrada" -- imposible por definición.
            // Nunca se aplica a "fecha"; se redirige a "fecha agendada"
            // si la etapa lo soporta, o se rechaza.
            if (in_array($resultado['etapa_sugerida'], ETAPA_CALENDARIO_KEYS, true)) {
                $yaProgramada = null;
                foreach ($etapas as $e) {
                    if ($e['etapa_key'] === $resultado['etapa_sugerida'] && !empty($e['fecha_programada']) && $e['fecha_programada'] !== '0000-00-00') {
                        $yaProgramada = $e['fecha_programada'];
                        break;
                    }
                }
                if ($yaProgramada === null || $yaProgramada === $fechaEtapa) {
                    $pdo->prepare(
                        'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha_programada)
                         VALUES (:eid, :key, :fecha)
                         ON DUPLICATE KEY UPDATE fecha_programada = VALUES(fecha_programada)'
                    )->execute([':eid' => $doc['expediente_id'], ':key' => $resultado['etapa_sugerida'], ':fecha' => $fechaEtapa]);
                    log_historial(
                        $pdo, (int)$doc['expediente_id'], $usuarioLog,
                        'etapa_' . $resultado['etapa_sugerida'], null,
                        "fecha agendada: $fechaEtapa (llenado automático a partir de '{$doc['nombre_archivo']}')"
                    );
                    $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['expediente_id']]);
                    $aplicado = true;
                    $aplicados++;
                } else {
                    log_historial(
                        $pdo, (int)$doc['expediente_id'], $usuarioLog,
                        'auditoria_ia_rechazo_' . $resultado['etapa_sugerida'], null,
                        "fecha agendada $fechaEtapa (de '{$doc['nombre_archivo']}') no se aplicó: la etapa ya tenía una fecha agendada distinta ($yaProgramada) -- posible conflicto, requiere revisión"
                    );
                }
            } else {
                log_historial(
                    $pdo, (int)$doc['expediente_id'], $usuarioLog,
                    'auditoria_ia_rechazo_' . $resultado['etapa_sugerida'], null,
                    "fecha futura $fechaEtapa (de '{$doc['nombre_archivo']}') no se aplicó: esta etapa no tiene concepto de \"fecha agendada\", nunca se marca como celebrada con una fecha que todavía no llega -- se deja pendiente"
                );
            }
            $yaTieneFecha = true; // ya se decidió qué hacer -- no caer al bloque normal de abajo
        }

        // Confirmado real (10 nov 2026, en la auditoría integral): la IA
        // puede atribuir una fecha real a la etapa EQUIVOCADA, rompiendo
        // el orden cronológico del trámite. Mismo candado que en los
        // demás mecanismos que llenan una etapa sola.
        if (!$yaTieneFecha && !etapa_fecha_es_consistente($resultado['etapa_sugerida'], $fechaEtapa, $etapas)) {
            log_historial(
                $pdo, (int)$doc['expediente_id'], $usuarioLog,
                'auditoria_ia_rechazo_' . $resultado['etapa_sugerida'], null,
                "fecha sugerida $fechaEtapa (de '{$doc['nombre_archivo']}') rechazada por romper el orden cronológico con otras etapas ya registradas"
            );
            $yaTieneFecha = true; // reutiliza la misma rama de "no aplicar" de abajo
        }
        if (!$yaTieneFecha) {
            $pdo->prepare(
                'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha)
                 VALUES (:eid, :key, :fecha)
                 ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)'
            )->execute([':eid' => $doc['expediente_id'], ':key' => $resultado['etapa_sugerida'], ':fecha' => $fechaEtapa]);

            log_historial(
                $pdo, (int)$doc['expediente_id'], $usuarioLog,
                'etapa_' . $resultado['etapa_sugerida'], null,
                "fecha: $fechaEtapa (llenado automático a partir de '{$doc['nombre_archivo']}')"
            );
            $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['expediente_id']]);
            $aplicado = true;
            $aplicados++;
        }
    }

    // Sugerencia de categoría (11 nov 2026, pedido explícito del usuario)
    // -- solo tiene sentido cuando se guardó bajo el cajón genérico 'otro'
    // (si el abogado ya eligió algo específico, no se le molesta con una
    // sugerencia). NUNCA se aplica sola -- a diferencia de una fecha de
    // etapa, hoy no hay forma de deshacer una recategorización mal hecha
    // desde la UI salvo borrando el documento, así que el abogado confirma
    // con un tap en la pestaña "Documentos" (ver documentos_recategorizar.php).
    $categoriaSugerida = ($doc['categoria'] === 'otro') ? $resultado['categoria_sugerida'] : null;

    $pdo->prepare(
        'UPDATE expediente_documentos
         SET ia_resumen = :resumen, ia_etapa_sugerida = :etapa, ia_fecha_mencionada = :fecha, ia_aplicado = :aplicado, ia_categoria_sugerida = :cat_sug, ia_analizado_en = NOW()
         WHERE id = :id'
    )->execute([
        ':resumen' => $resultado['resumen'],
        ':etapa' => $resultado['etapa_sugerida'],
        ':fecha' => $resultado['fecha_mencionada'],
        ':aplicado' => $aplicado ? 1 : 0,
        ':cat_sug' => $categoriaSugerida,
        ':id' => $doc['id'],
    ]);
    if ($categoriaSugerida) $sugerenciasCategoria++;

    // Mismo bump que en cron_analizar_acuerdos_ia.php: el resumen en sí ya
    // es novedad para el Informe Ejecutivo, aunque no se haya llenado
    // ninguna etapa sola.
    if (!$aplicado) {
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['expediente_id']]);
    }
}

echo count($documentos) . " documento(s) en el lote, " . $analizados . " analizado(s) con IA, "
    . $copiados . " copiado(s) de su aviso de boletín, " . $omitidos . " omitido(s) (formato no legible), "
    . $aplicados . " etapa(s) llenada(s) sola(s), " . $sugerenciasCategoria . " sugerencia(s) de categoría pendiente(s) de confirmar, "
    . $fallidos . " fallido(s) (se reintentan después).\n";
