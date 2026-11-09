<?php
declare(strict_types=1);

// Reporte de diagnóstico, pensado para abrirse directo en el navegador
// (ya logueado en el sistema) -- NO es un endpoint JSON normal. Pedido del
// usuario (9 nov 2026): "ayudar a hacer un reporte de los asuntos en donde
// esta mal capturados los datos" después de notar que los robots de
// Federal/Edomex no estaban encontrando acuerdos de asuntos que claramente
// sí los tenían.
//
// Reutiliza EXACTAMENTE la misma lógica de reconocimiento que usan los
// robots (ver api/expedientes_monitorear.php y el regex /FEDERAL/i de
// robots/federal/federal.js, /ciudad de m[eé]xico|cdmx/i de
// robots/cdmx/cdmx.js, y /tlalnepantla/i de robots/edomex_correo/edomex_correo.js)
// para encontrar expedientes activos que, por cómo quedó capturado su
// Tribunal/Junta, NINGÚN robot puede estar revisando.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/expediente_helpers.php';

require_admin();
$pdo = db();

$rows = $pdo->query(
    "SELECT id, exp, actor, demandado, junta, tribunal, amparo_activo, amparo_expediente, abogado_id, abogado_id_2
     FROM expedientes
     WHERE concluido_manual = 0
     ORDER BY actor"
)->fetchAll();

$abogadosStmt = $pdo->query("SELECT id, nombre FROM usuarios");
$nombresPorId = [];
foreach ($abogadosStmt->fetchAll() as $u) $nombresPorId[(int)$u['id']] = $u['nombre'];
$abogadoNombre = function ($r) use ($nombresPorId) {
    $nombres = [];
    if (!empty($r['abogado_id']) && isset($nombresPorId[(int)$r['abogado_id']])) $nombres[] = $nombresPorId[(int)$r['abogado_id']];
    if (!empty($r['abogado_id_2']) && isset($nombresPorId[(int)$r['abogado_id_2']])) $nombres[] = $nombresPorId[(int)$r['abogado_id_2']];
    return $nombres ? implode(' / ', $nombres) : 'Sin asignar';
};

// Mismas palabras clave que usan los robots de verdad -- si un cambio se
// hace allá, hay que reflejarlo aquí también para que el reporte no mienta.
const PALABRAS_EDOMEX_REGION = ['ecatepec', 'texcoco', 'nezahualc[oó]yotl', 'teotihuac[aá]n', 'tlalnepantla', 'naucalpan', 'cuautitl[aá]n', 'toluca', 'xonacatl[aá]n', 'itinerante'];

$sinDatos = [];
$noReconocido = [];
$edomexFueraTlalnepantla = [];
$amparoSinNumero = [];

foreach ($rows as $r) {
    $texto = trim(($r['junta'] ?? '') . ' ' . ($r['tribunal'] ?? ''));
    $tieneTribunal = $texto !== '';
    $tieneAmparo = !empty($r['amparo_expediente']);

    if (!empty($r['amparo_activo']) && !$tieneAmparo) {
        $amparoSinNumero[] = $r;
    }

    if (!$tieneTribunal && !$tieneAmparo) {
        $sinDatos[] = $r;
        continue; // ya quedó clasificado, no hace falta evaluar lo demás
    }
    if (!$tieneTribunal) continue; // solo tiene amparo -- eso sí lo rastrea el robot Federal

    $esFederal = stripos($texto, 'federal') !== false;
    $esCdmx = preg_match('/ciudad de m[eé]xico|cdmx/i', $texto) === 1;
    $esTlalnepantla = stripos($texto, 'tlalnepantla') !== false;
    $esOtraRegionEdomex = false;
    foreach (PALABRAS_EDOMEX_REGION as $patron) {
        if ($patron === 'tlalnepantla') continue;
        if (preg_match('/' . $patron . '/i', $texto) === 1) { $esOtraRegionEdomex = true; break; }
    }

    if (!$esFederal && !$esCdmx && !$esTlalnepantla && !$esOtraRegionEdomex) {
        $noReconocido[] = $r;
    } elseif (!$esFederal && $esOtraRegionEdomex && !$esTlalnepantla) {
        $edomexFueraTlalnepantla[] = $r;
    }
}

header('Content-Type: text/html; charset=utf-8');
$fila = function ($r) use ($abogadoNombre) {
    $tribunal = htmlspecialchars(trim(($r['tribunal'] ?: $r['junta']) ?: '(vacío)'), ENT_QUOTES, 'UTF-8');
    return '<tr>'
        . '<td>' . htmlspecialchars($r['exp'] ?? '—', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . htmlspecialchars($r['actor'] ?? '', ENT_QUOTES, 'UTF-8') . ' vs ' . htmlspecialchars($r['demandado'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td>' . $tribunal . '</td>'
        . '<td>' . htmlspecialchars($abogadoNombre($r), ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr>';
};
$tabla = function (array $lista) use ($fila) {
    if (!$lista) return '<p style="color:#2a7; font-weight:600;">Ninguno -- bien.</p>';
    return '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; font-size:13px;">'
        . '<tr style="background:#eee;"><th>Expediente</th><th>Actor vs Demandado</th><th>Tribunal/Junta capturado</th><th>Abogado</th></tr>'
        . implode('', array_map($fila, $lista))
        . '</table>';
};
?>
<!doctype html>
<html lang="es-MX"><head><meta charset="utf-8"><title>Reporte: datos incompletos para los robots</title></head>
<body style="font-family: Arial, sans-serif; max-width: 1100px; margin: 24px auto; padding: 0 16px;">
<h1>Reporte: por qué un robot podría no estar viendo un asunto</h1>
<p>Generado <?= date('d/m/Y H:i') ?>. Solo incluye asuntos <strong>activos</strong> (no marcados como concluidos).</p>

<h2>1. Sin NINGÚN dato de tribunal/junta ni amparo (<?= count($sinDatos) ?>)</h2>
<p>Ningún robot puede revisar estos -- no tienen de dónde partir. Hay que capturar el Tribunal en "Editar datos" (o el expediente de amparo en la pestaña "Amparo" si aplica).</p>
<?= $tabla($sinDatos) ?>

<h2>2. Tribunal/Junta capturado, pero el texto no se reconoce como Federal, CDMX ni Estado de México (<?= count($noReconocido) ?>)</h2>
<p>Seguramente es texto libre o de un capturado antiguo que no coincide con el catálogo -- ningún robot lo identifica como "suyo". Revisa y vuelve a capturar el Tribunal usando el selector normal (no texto libre), o avísame el texto exacto si crees que sí debería reconocerse.</p>
<?= $tabla($noReconocido) ?>

<h2>3. Estado de México, pero NO Tlalnepantla (<?= count($edomexFueraTlalnepantla) ?>)</h2>
<p><strong>Esta es la causa más probable de lo que reportaste sobre Edomex.</strong> El robot de correo de notificaciones (edomex_correo.js) hoy solo está programado para reconocer tribunales de <strong>Tlalnepantla</strong> (incluye Naucalpan y Cuautitlán Izcalli, que son residencias de esa misma región judicial). Estos asuntos están en Ecatepec, Texcoco, Toluca u otra región de Edomex -- el robot nunca los va a revisar aunque el abogado ya haya guardado su correo en "Mi cuenta", porque el código ni siquiera los está buscando. Esto se puede arreglar en el código (ampliar el reconocimiento a las demás regiones), pero antes hay que confirmar que el mismo sistema de correo de notificaciones (mail.pjedomex.gob.mx) funcione igual para esas regiones -- avísame y lo reviso.</p>
<?= $tabla($edomexFueraTlalnepantla) ?>

<h2>4. Amparo marcado como activo, pero sin número de expediente de amparo capturado (<?= count($amparoSinNumero) ?>)</h2>
<p>El robot Federal rastrea amparos por el número de expediente -- si no está capturado, no hay nada que buscar aunque el checkbox "activo" esté marcado. Captúralo en la pestaña "Amparo".</p>
<?= $tabla($amparoSinNumero) ?>

<hr>
<p style="color:#777; font-size:12px;">Nota: esto NO revisa si el abogado de PJF correcto (buerhend/maluli20) tiene acceso real a cada expediente en el portal -- un Tribunal bien capturado y reconocido como "Federal" aquí puede aún así no aparecer si el caso está registrado en el portal bajo la cuenta de un abogado distinto a esas 2.</p>
</body></html>
