<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// (28 nov 2026, pedido del Lic.) Lo llaman los robots Federal, Buzón CDMX
// y Correo Edomex al arrancar: devuelve los avisos que ESE robot reportó
// alguna vez SIN PDF, para que los vuelva a intentar en esta corrida.
// Motivo: el arreglo de reintentos del 24-nov solo recordaba los fallos
// POSTERIORES a esa fecha (memoria local del robot); los avisos viejos
// (ej. Exp. 501/2026, Acuerdo No. 2, 19-nov) quedaban sin PDF para siempre.
// Con esta lista, la fuente de verdad es la base, no el archivo local.
//
// Antes de devolver la lista, si el robot SÍ había subido el PDF pero no se
// pegó al aviso (pasaba antes del 24-nov: el PDF llegaba en una corrida
// posterior y avisos_ingest.php lo trataba como duplicado), se pega aquí
// mismo buscando el nombre de archivo exacto que pone el robot -- así no se
// vuelve a bajar un PDF que ya está en Documentos.
//
// Los avisos "descartado" no se incluyen (ej. coincidencia equivocada de
// expediente): no deben analizarse ni usarse como contexto.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_robot_key();

$fuente = (string)($_GET['fuente'] ?? '');
if (!in_array($fuente, ['federal_laboral', 'cdmx_local', 'edomex_local'], true)) fail('Fuente inválida.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, expediente_id, resumen, fecha_publicacion
     FROM avisos_boletin
     WHERE fuente = :fuente AND origen = 'automatico' AND documento_id IS NULL
       AND estado <> 'descartado'
     ORDER BY creado_en ASC"
);
$stmt->execute([':fuente' => $fuente]);
$avisos = $stmt->fetchAll();

// Nombre de archivo que el robot le pone al PDF de ese aviso (o null si no
// se puede saber solo con el resumen, como en Edomex, que lleva el id del correo).
function nombre_pdf_robot(string $fuente, string $resumen): ?string
{
    if ($fuente === 'federal_laboral'
        && preg_match('#^Acuerdo(?: de amparo)? No\. (\d+) del expediente (.+?), de fecha#u', $resumen, $m)) {
        return 'Acuerdo_No_' . $m[1] . '_Exp_' . str_replace('/', '-', $m[2]) . '.pdf';
    }
    if ($fuente === 'cdmx_local' && preg_match('#^Acuerdo (\d+/\d{4}-\d+) del expediente #u', $resumen, $m)) {
        return 'Acuerdo_' . str_replace(['/', '\\'], '-', $m[1]) . '.pdf';
    }
    return null;
}

$buscarDoc = $pdo->prepare(
    'SELECT d.id FROM expediente_documentos d
     WHERE d.expediente_id = :eid AND d.nombre_archivo = :nombre
       AND NOT EXISTS (SELECT 1 FROM avisos_boletin a2 WHERE a2.documento_id = d.id)
     ORDER BY d.id DESC LIMIT 1'
);
$pegar = $pdo->prepare('UPDATE avisos_boletin SET documento_id = :doc WHERE id = :id AND documento_id IS NULL');
$bump = $pdo->prepare('UPDATE expedientes SET actualizado_en = NOW() WHERE id = :id');

$pendientes = [];
$pegados = 0;
foreach ($avisos as $a) {
    $nombre = nombre_pdf_robot($fuente, (string)$a['resumen']);
    if ($nombre !== null) {
        $buscarDoc->execute([':eid' => $a['expediente_id'], ':nombre' => $nombre]);
        $docId = $buscarDoc->fetchColumn();
        if ($docId) {
            $pegar->execute([':doc' => (int)$docId, ':id' => $a['id']]);
            $bump->execute([':id' => $a['expediente_id']]);
            $pegados++;
            continue;
        }
    }
    $pendientes[] = [
        'aviso_id' => (int)$a['id'],
        'expediente_id' => (int)$a['expediente_id'],
        'resumen' => (string)$a['resumen'],
        'fecha_publicacion' => $a['fecha_publicacion'],
    ];
}

respond(['pendientes' => $pendientes, 'pegados_ya_existentes' => $pegados]);
