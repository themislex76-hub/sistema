<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Llamado por los robots de monitoreo de boletines (no por el frontend) para
// saber qué expedientes activos necesitan revisión diaria. No filtra por
// jurisdicción aquí — cada robot decide qué le corresponde según el texto
// de 'junta'/'tribunal' (Federal/CDMX/Edomex, que SÍ necesitan ese texto
// para reconocer de qué tribunal se trata) o, para robots que matchean
// solo por número de expediente (ej. cdmx_buzon, que ve directo los
// expedientes de la cuenta sin necesitar saber el Tribunal), basta con que
// 'exp' esté capturado -- por eso también se incluyen esos aunque no
// tengan Tribunal/Junta/amparo, para no dejarlos invisibles para ESE tipo
// de robot (bug real detectado 10 nov 2026: un expediente real sin
// Tribunal capturado nunca llegaba aquí, así que cdmx_buzon.js nunca podía
// intentar el match aunque su lógica de comparación fuera correcta).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$pdo = db();
$sql = "SELECT id, exp, actor, demandado, junta, tribunal, amparo_expediente, amparo_tribunal
        FROM expedientes
        WHERE (junta IS NOT NULL AND junta <> '') OR (tribunal IS NOT NULL AND tribunal <> '')
           OR (amparo_expediente IS NOT NULL AND amparo_expediente <> '')
           OR (exp IS NOT NULL AND exp <> '')
        ORDER BY id";
$rows = $pdo->query($sql)->fetchAll();

$expedientes = [];
foreach ($rows as $r) {
    // Los tribunales laborales FEDERALES en México siempre traen la palabra
    // "FEDERAL" en su nombre oficial (ej. "Tribunal Laboral Federal de
    // Asuntos Individuales..."), aunque estén ubicados/con sede en Ciudad
    // de México o cualquier otro estado — por eso NO hay que fijarse en si
    // el texto menciona "Ciudad de México"/"CDMX" (eso puede ser solo la
    // sede física), sino específicamente en la palabra "FEDERAL". Se manda
    // ya calculado para que el robot no tenga que adivinar del texto libre.
    $textoCompleto = ($r['junta'] ?? '') . ' ' . ($r['tribunal'] ?? '');
    $esFederal = stripos($textoCompleto, 'federal') !== false;
    $expedientes[] = [
        'id' => (int)$r['id'],
        'exp' => $r['exp'],
        'actor' => $r['actor'],
        'demandado' => $r['demandado'],
        'junta' => $r['junta'],
        'tribunal' => $r['tribunal'],
        'es_federal' => $esFederal,
        // El amparo directo SIEMPRE lo resuelve un Tribunal Colegiado de
        // Circuito, que es un órgano FEDERAL -- sin importar si el juicio
        // laboral original fue local (CDMX/Edomex) o federal. Por eso el
        // robot debe rastrear este número aunque 'es_federal' de arriba
        // sea false.
        'amparo_expediente' => $r['amparo_expediente'],
        'amparo_tribunal' => $r['amparo_tribunal'],
    ];
}

respond(['expedientes' => $expedientes]);
