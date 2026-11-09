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

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :id');
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
        if (!$yaTieneFecha) {
            $pdo->prepare(
                'INSERT INTO expediente_etapas (expediente_id, etapa_key, fecha)
                 VALUES (:eid, :key, :fecha)
                 ON DUPLICATE KEY UPDATE fecha = VALUES(fecha)'
            )->execute([':eid' => $doc['expediente_id'], ':key' => $resultado['etapa_sugerida'], ':fecha' => $resultado['fecha_mencionada']]);

            log_historial(
                $pdo, (int)$doc['expediente_id'], ['id' => null, 'nombre' => 'IA (lectura automática de documento)'],
                'etapa_' . $resultado['etapa_sugerida'], null,
                "fecha: {$resultado['fecha_mencionada']} (llenado automático a partir de '{$doc['nombre_archivo']}')"
            );
            $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $doc['expediente_id']]);
            $aplicado = true;
            $aplicados++;
        }
    }

    $pdo->prepare(
        'UPDATE expediente_documentos
         SET ia_resumen = :resumen, ia_etapa_sugerida = :etapa, ia_fecha_mencionada = :fecha, ia_aplicado = :aplicado, ia_analizado_en = NOW()
         WHERE id = :id'
    )->execute([
        ':resumen' => $resultado['resumen'],
        ':etapa' => $resultado['etapa_sugerida'],
        ':fecha' => $resultado['fecha_mencionada'],
        ':aplicado' => $aplicado ? 1 : 0,
        ':id' => $doc['id'],
    ]);
}

echo count($documentos) . " documento(s) en el lote, " . $analizados . " analizado(s) con IA, "
    . $copiados . " copiado(s) de su aviso de boletín, " . $omitidos . " omitido(s) (formato no legible), "
    . $aplicados . " etapa(s) llenada(s) sola(s), " . $fallidos . " fallido(s) (se reintentan después).\n";
