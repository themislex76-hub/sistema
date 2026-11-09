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
// poder seguir el plazo legal. SIN notificación push a propósito (pedido
// del usuario): la pregunta solo aparece cuando el abogado entra al
// sistema, no le manda nada a su celular.
//
// Config del Cron Job: comando "php /ruta/completa/a/este/archivo.php",
// frecuencia: una vez al día (por ejemplo junto con cron_recordatorio_abogado.php).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/expediente_helpers.php';
require_once __DIR__ . '/ia_helpers.php';

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

    $pregunta = ia_generar_pregunta_supervisor($expediente, $etapas, $atraso['label']);
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
    $generadas++;
}

echo count($atrasos) . " atraso(s) revisado(s), " . $generadas . " pregunta(s) nueva(s) generada(s), "
    . $fallidas . " fallida(s) (se reintentan después).\n";
