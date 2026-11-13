<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

// Informe ejecutivo con IA de un expediente (ver ia_generar_resumen_expediente()
// en ia_helpers.php) -- para que un socio/jefe entienda el asunto sin abrir
// todo el expediente. Se cachea en expedientes.resumen_ejecutivo: solo se
// vuelve a llamar a la IA cuando el expediente cambió desde la última vez
// que se generó (actualizado_en más reciente que resumen_ejecutivo_generado_en)
// o cuando se pide regenerar a mano -- así el costo real es una llamada por
// cambio de expediente, no una por cada vez que alguien lo abre.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
$forzar = !empty($in['forzar']);
if ($id <= 0) fail('Falta el id del expediente.', 400);

$pdo = db();
$row = guard_expediente_access($pdo, $user, $id);
$row = cast_numeric_fields($row);

$necesitaRegenerar = $forzar
    || empty($row['resumen_ejecutivo'])
    || empty($row['resumen_ejecutivo_generado_en'])
    || strtotime((string)$row['actualizado_en']) > strtotime((string)$row['resumen_ejecutivo_generado_en']);

if (!$necesitaRegenerar) {
    respond([
        'resumen' => $row['resumen_ejecutivo'],
        'accion' => $row['accion_sugerida_ia'],
        'urgencia' => $row['urgencia_ia'],
        'generado_en' => $row['resumen_ejecutivo_generado_en'],
        'regenerado' => false,
    ]);
}

$etapasStmt = $pdo->prepare('SELECT etapa_key, fecha, fecha_programada, resultado FROM expediente_etapas WHERE expediente_id = :id');
$etapasStmt->execute([':id' => $id]);
$etapas = $etapasStmt->fetchAll();

// Últimas notas de seguimiento del abogado (bitácora real del trámite, ver
// ia_generar_resumen_expediente() -- sin esto la IA solo veía el campo
// congelado "Última nota" importado del sistema viejo y podía recomendar
// algo que ya se hizo hace meses).
$notasStmt = $pdo->prepare('SELECT texto, creado_en FROM expediente_notas WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 8');
$notasStmt->execute([':id' => $id]);
$notasBitacora = $notasStmt->fetchAll();

// Documentos del expediente y avisos de boletín, con su resumen de IA --
// agregado 13 nov 2026 (mismo patrón que cron_auditoria_expediente_ia.php):
// sin esto el informe ejecutivo no se enteraba de hechos reales documentados
// (ej. un incidente de competencia levantado de oficio por el Tribunal) que
// explican por qué el trámite no sigue la secuencia normal -- ver caso real
// de Mir Castro Ma Claudia, Exp. 242/2026.
$docsStmt = $pdo->prepare('SELECT nombre_archivo, categoria, ia_resumen FROM expediente_documentos WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 12');
$docsStmt->execute([':id' => $id]);
$documentosRecientes = $docsStmt->fetchAll();

$avisosStmt = $pdo->prepare("SELECT resumen, ia_resumen, creado_en FROM avisos_boletin WHERE expediente_id = :id AND estado != 'descartado' ORDER BY creado_en DESC LIMIT 10");
$avisosStmt->execute([':id' => $id]);
$avisosRecientes = $avisosStmt->fetchAll();

$resultado = ia_generar_resumen_expediente($row, $etapas, $notasBitacora, $documentosRecientes, $avisosRecientes);

if ($resultado === null) {
    // La IA falló (red, sin crédito, etc.) -- si ya había un resumen viejo
    // guardado, mejor mostrar ese que dejar al usuario sin nada.
    if (!empty($row['resumen_ejecutivo'])) {
        respond([
            'resumen' => $row['resumen_ejecutivo'],
            'accion' => $row['accion_sugerida_ia'],
            'urgencia' => $row['urgencia_ia'],
            'generado_en' => $row['resumen_ejecutivo_generado_en'],
            'regenerado' => false,
            'aviso' => 'No se pudo actualizar el resumen ahora mismo, se muestra el último generado.',
        ]);
    }
    fail('No se pudo generar el resumen ejecutivo. Revisa ia_debug.log.', 502);
}

$ahora = date('Y-m-d H:i:s');
$pdo->prepare('UPDATE expedientes SET resumen_ejecutivo = :r, accion_sugerida_ia = :a, urgencia_ia = :u, resumen_ejecutivo_generado_en = :f WHERE id = :id')
    ->execute([
        ':r' => $resultado['resumen'],
        ':a' => $resultado['accion'],
        ':u' => $resultado['urgencia'],
        ':f' => $ahora,
        ':id' => $id,
    ]);

respond([
    'resumen' => $resultado['resumen'],
    'accion' => $resultado['accion'],
    'urgencia' => $resultado['urgencia'],
    'generado_en' => $ahora,
    'regenerado' => true,
]);
