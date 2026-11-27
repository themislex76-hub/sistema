<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) cada 15-30 minutos -- NO se abre desde el navegador ni
// tiene sesión. Busca avisos de boletín con documento adjunto que todavía
// no se le hayan mandado a leer a la IA (ia_analizado_en IS NULL), lee el
// PDF/texto real con ia_analizar_acuerdo_boletin() (ver api/ia_helpers.php)
// y guarda qué dice -- si la IA sugiere con claridad una etapa del trámite
// Y esa etapa TODAVÍA no tiene fecha capturada en el expediente, la llena
// sola (nunca sobrescribe una etapa que el abogado ya registró a mano).
//
// Separado de avisos_ingest.php (que es lo que llaman los robots en
// tiempo real) a propósito: leer un PDF con IA tarda varios segundos y no
// debe hacer más lenta -- ni arriesgar que truene -- la corrida de los
// robots de monitoreo. Este cron corre aparte, en su propio tiempo.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: cada 15-30 minutos.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

// Tope por corrida -- si hubiera un backlog grande (ej. recién se activó
// esto) mejor ir repartiendo el trabajo entre varias corridas del cron que
// arriesgar un timeout de PHP o un gasto grande de golpe en la API.
const LOTE_MAXIMO = 15;

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT a.id, a.expediente_id, a.documento_id, a.fecha_publicacion
     FROM avisos_boletin a
     WHERE a.documento_id IS NOT NULL AND a.ia_analizado_en IS NULL
     ORDER BY a.creado_en ASC
     LIMIT " . LOTE_MAXIMO
);
$stmt->execute();
$avisos = $stmt->fetchAll();

if (!$avisos) {
    echo "Sin avisos pendientes de analizar.\n";
    exit;
}

$analizados = 0;
$aplicados = 0;
$fallidos = 0;

foreach ($avisos as $aviso) {
    $docStmt = $pdo->prepare('SELECT nombre_disco, tipo_mime FROM expediente_documentos WHERE id = :id');
    $docStmt->execute([':id' => (int)$aviso['documento_id']]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        // El documento se borró (ej. el robot subió el PDF equivocado y
        // alguien lo eliminó a mano) -- no hay nada que leer, se marca
        // como analizado para no reintentarlo en cada corrida.
        $pdo->prepare('UPDATE avisos_boletin SET ia_analizado_en = NOW() WHERE id = :id')->execute([':id' => $aviso['id']]);
        continue;
    }
    $ruta = documentos_dir((int)$aviso['expediente_id']) . '/' . $doc['nombre_disco'];

    $expStmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $expStmt->execute([':id' => $aviso['expediente_id']]);
    $expediente = $expStmt->fetch();
    if (!$expediente) continue; // expediente borrado -- no debería pasar, pero no truena el lote por esto

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha, fecha_programada FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $aviso['expediente_id']]);
    $etapas = $etapasStmt->fetchAll();

    $resultado = ia_analizar_acuerdo_boletin($ruta, $doc['tipo_mime'], $expediente, $etapas);
    $analizados++;
    if (!$resultado) {
        $fallidos++;
        continue; // se reintenta en la siguiente corrida (ia_analizado_en se queda NULL)
    }

    // Un mismo documento puede confirmar VARIAS etapas a la vez (ej. una
    // constancia del Secretario que certifica tanto el emplazamiento como
    // la contestación, cada una con su propia fecha -- caso real 20 nov
    // 2026, Exp. 463/2026, Anel Yadira Hernández Zaragoza) -- se procesan
    // todas con el mismo candado (ia_aplicar_etapa_sugerida(), en
    // api/expediente_helpers.php: nunca sobrescribe una fecha ya
    // capturada, nunca aplica una fecha futura como "ya celebrada", nunca
    // rompe el orden cronológico).
    $eventosDetectados = [];
    if ($resultado['etapa_sugerida']) {
        $eventosDetectados[] = ['etapa' => $resultado['etapa_sugerida'], 'fecha' => $resultado['fecha_mencionada']];
    }
    foreach (($resultado['eventos_adicionales'] ?? []) as $ev) {
        $eventosDetectados[] = $ev;
    }

    $aplicado = false;
    $usuarioLog = ['id' => null, 'nombre' => 'IA (lectura automática del boletín)'];
    foreach ($eventosDetectados as $ev) {
        // Fecha a capturar: la que el documento menciona literalmente si
        // la hay; si no, la fecha de publicación del boletín (dato real,
        // no inventado) como mejor referencia disponible -- solo este
        // cron usa este respaldo, a diferencia de cron_analizar_
        // documentos_ia.php, que nunca lo usa.
        if (ia_aplicar_etapa_sugerida(
            $pdo, (int)$aviso['expediente_id'], $ev['etapa'], $ev['fecha'],
            $aviso['fecha_publicacion'], $etapas, $usuarioLog, 'a partir del acuerdo del boletín'
        )) {
            $aplicado = true;
            $aplicados++;
        }
    }

    $pdo->prepare(
        'UPDATE avisos_boletin
         SET ia_resumen = :resumen, ia_etapa_sugerida = :etapa, ia_fecha_mencionada = :fecha, ia_aplicado = :aplicado, ia_analizado_en = NOW()
         WHERE id = :id'
    )->execute([
        ':resumen' => $resultado['resumen'],
        ':etapa' => $resultado['etapa_sugerida'],
        ':fecha' => $resultado['fecha_mencionada'],
        ':aplicado' => $aplicado ? 1 : 0,
        ':id' => $aviso['id'],
    ]);

    // Bumpea actualizado_en aunque NO se haya llenado ninguna etapa sola --
    // el resumen de la IA en sí ya es información nueva para el Informe
    // Ejecutivo (que lee avisos_boletin.ia_resumen), y antes solo se
    // bumpeaba cuando $aplicado era true, así que un acuerdo real sin
    // etapa que llenar (la mayoría) nunca llegaba a verse reflejado ahí
    // (caso real 20 nov 2026, Exp. 463/2026: Anel Yadira Hernández
    // Zaragoza -- sus 4 acuerdos nunca aparecieron en el Informe Ejecutivo).
    if (!$aplicado) {
        $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id')->execute([':id' => $aviso['expediente_id']]);
    }
}

echo count($avisos) . " aviso(s) en el lote, " . $analizados . " analizado(s), "
    . $aplicados . " etapa(s) llenada(s) sola(s), " . $fallidos . " fallido(s) (se reintentan después).\n";
