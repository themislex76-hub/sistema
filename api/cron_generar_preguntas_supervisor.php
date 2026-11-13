<?php
declare(strict_types=1);

// Script pensado para correr solo, vía un Cron Job (Tareas programadas en
// DonWeb/cPanel) una vez al día -- NO se abre desde el navegador ni tiene
// sesión. Busca los "posibles atrasos" que el navegador ya calculó y
// sincronizó a agenda_avisos_cache (ver syncAvisosAbogado() en app.js, y
// el arreglo de la migración 059 -- un atraso sigue ahí mientras no se
// resuelva, sin importar cuántos días lleve) y, por cada uno que todavía
// no tenga una pregunta generada, le pide a la IA que redacte UNA
// pregunta concreta para el abogado a cargo -- sin dar clases de
// derecho, buscando el dato que falta (normalmente una fecha) para
// poder seguir el plazo legal. Al principio se pidió explícitamente SIN
// push (que la pregunta solo apareciera al entrar al sistema) -- el
// usuario cambió de opinión el 10 nov 2026: ahora cada pregunta nueva
// manda un push al abogado a cargo del expediente, con un link que abre
// el sistema directo en esa pregunta lista para contestar (ver
// abrirPreguntaDesdeNotificacion() en assets/app.js).
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez al día (por ejemplo junto con cron_recordatorio_abogado.php).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';
require_once __DIR__ . '/push_helpers.php';

const LOTE_MAXIMO = 15;

$pdo = db();

// clave = 'atraso:<etapa_key>' (ver assets/app.js, estancadoInfo() +
// buildAgendaEntries()) -- un expediente solo puede tener UN atraso a la
// vez (el siguiente paso sin registrar), así que esta clave ya es única
// por expediente mientras el atraso siga vigente.
$stmt = $pdo->prepare(
    "SELECT c.expediente_id, c.clave, c.label
     FROM agenda_avisos_cache c
     LEFT JOIN supervisor_preguntas sp ON sp.expediente_id = c.expediente_id AND sp.clave = c.clave
     WHERE c.tipo = 'atraso' AND c.clave LIKE 'atraso:%' AND sp.id IS NULL
     ORDER BY c.expediente_id
     LIMIT " . LOTE_MAXIMO
);
$stmt->execute();
$atrasos = $stmt->fetchAll();

if (!$atrasos) {
    echo "Sin atrasos nuevos pendientes de generar pregunta.\n";
    exit;
}

$generadas = 0;
$fallidas = 0;

foreach ($atrasos as $atraso) {
    $etapaKey = substr($atraso['clave'], strlen('atraso:'));
    if (!in_array($etapaKey, ETAPA_KEYS, true)) continue; // formato inesperado -- no adivinar, se omite

    $expStmt = $pdo->prepare('SELECT * FROM expedientes WHERE id = :id');
    $expStmt->execute([':id' => $atraso['expediente_id']]);
    $expediente = $expStmt->fetch();
    if (!$expediente) continue;

    $etapasStmt = $pdo->prepare('SELECT etapa_key, fecha FROM expediente_etapas WHERE expediente_id = :id');
    $etapasStmt->execute([':id' => $atraso['expediente_id']]);
    $etapas = $etapasStmt->fetchAll();

    // Contexto adicional pedido por el usuario: que la IA revise lo que ya
    // hay en el sistema (documentos subidos, boletines ya leídos) antes de
    // redactar la pregunta, no solo la bitácora de etapas.
    $docsStmt = $pdo->prepare('SELECT categoria, nombre_archivo, creado_en FROM expediente_documentos WHERE expediente_id = :id ORDER BY creado_en DESC LIMIT 8');
    $docsStmt->execute([':id' => $atraso['expediente_id']]);
    $documentosRecientes = $docsStmt->fetchAll();

    // Excluir 'descartado' -- mismo motivo que en cron_auditoria_expediente_ia.php
    // y cron_qa_irregularidades.php: un aviso descartado es de OTRO caso que
    // compartía el mismo número de expediente por coincidencia (bug real
    // encontrado 13 nov 2026).
    $avisosStmt = $pdo->prepare("SELECT ia_resumen FROM avisos_boletin WHERE expediente_id = :id AND estado != 'descartado' AND ia_resumen IS NOT NULL AND ia_resumen <> '' ORDER BY creado_en DESC LIMIT 5");
    $avisosStmt->execute([':id' => $atraso['expediente_id']]);
    $avisosRecientes = $avisosStmt->fetchAll();

    $pregunta = ia_generar_pregunta_supervisor($expediente, $etapas, $atraso['label'], $documentosRecientes, $avisosRecientes);
    if (!$pregunta) {
        $fallidas++;
        continue; // se reintenta en la siguiente corrida (no se inserta nada)
    }

    $pdo->prepare(
        'INSERT INTO supervisor_preguntas (expediente_id, clave, etapa_key, pregunta)
         VALUES (:eid, :clave, :etapa, :pregunta)'
    )->execute([
        ':eid' => $atraso['expediente_id'],
        ':clave' => $atraso['clave'],
        ':etapa' => $etapaKey,
        ':pregunta' => $pregunta,
    ]);
    $preguntaId = (int)$pdo->lastInsertId();
    $generadas++;

    // Push al abogado (o a los 2, si el expediente tiene segundo abogado
    // asignado) con link directo a contestar esta pregunta -- pedido
    // explícito del usuario el 10 nov 2026, revirtiendo la decisión
    // original de "sin push". El link lo abre abrirPreguntaDesdeNotificacion()
    // en assets/app.js.
    $caso = trim(($expediente['actor'] ?? '') . ' vs ' . ($expediente['demandado'] ?? ''), ' vs');
    $abogadosDestino = array_filter([(int)($expediente['abogado_id'] ?? 0), (int)($expediente['abogado_id_2'] ?? 0)]);
    foreach (array_unique($abogadosDestino) as $abogadoId) {
        push_enviar_a_usuario(
            $pdo, $abogadoId, '🧑‍⚖️ Pregunta del supervisor',
            "$caso: $pregunta", '/sistema/?pregunta=' . $preguntaId
        );
    }
}

echo count($atrasos) . " atraso(s) revisado(s), " . $generadas . " pregunta(s) nueva(s) generada(s), "
    . $fallidas . " fallida(s) (se reintentan después).\n";
