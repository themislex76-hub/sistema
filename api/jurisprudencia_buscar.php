<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php'; // solo para reusar la constante IA_MODEL

// Buscador de jurisprudencia laboral con IA -- disponible para cualquier
// usuario con sesión (de cualquier despacho, no solo Administrador): la
// biblioteca de tesis (jurisprudencia_tesis) es compartida entre todos los
// despachos del sistema, no se divide por despacho.
//
// POST { pregunta }: el abogado describe los HECHOS de un caso real. En vez
// de que MySQL intente adivinar cuáles tesis son candidatas por coincidencia
// de palabras (un intento anterior con FULLTEXT dio resultados pobres: la
// biblioteca abunda en las mismas palabras -- "trabajador", "despedido" --
// en casi cualquier tesis, y la redacción exacta de un abogado rara vez
// coincide con la redacción exacta de un rubro), aquí se le manda a Claude
// el TÍTULO de TODAS las tesis de la biblioteca (unos miles, pero solo el
// rubro, no el texto completo, así que cabe perfecto en la ventana de
// contexto de 1M tokens del modelo) y es la IA la que decide, con criterio
// jurídico real, cuáles aplican de verdad al caso. Solo hasta ahí se pide
// el texto completo de las que sí aplican, para la respuesta final. Nunca
// puede citar una tesis que no esté en la biblioteca, porque son las
// únicas que se le muestran en algún momento del proceso.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

// Esta búsqueda manda a la IA miles de títulos de tesis (con razonamiento
// extendido, para no perder de vista ninguna línea) y puede tardar bastante
// más que una petición normal del sistema -- sin esto, el límite de tiempo
// por defecto del hosting compartido (típicamente 30s) corta el script a
// la mitad.
set_time_limit(360);

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
if ($pregunta === '') fail('Describe los hechos del caso.', 400);
if (mb_strlen($pregunta) > 2000) fail('La descripción es demasiado larga.', 400);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

// Llamada mínima reusada dos veces en este archivo (elegir tesis candidatas
// y, más abajo, redactar la respuesta final) -- evita repetir el mismo
// bloque de curl dos veces. $timeoutSegundos es más alto para la primera
// llamada (manda miles de títulos) que para la segunda.
function jurisprudencia_llamar_claude(array $payload, int $timeoutSegundos = 60): string
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeoutSegundos,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [jurisprudencia_buscar] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
        fail('Falló la llamada a la IA. Revisa ia_debug.log.', 502);
    }

    $data = json_decode($raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
    }
    return trim($texto);
}

$pdo = db();

// Paso 1: se listan TODOS los títulos de la biblioteca (registro + rubro,
// nunca el texto completo en este paso) y se le pide a Claude que revise
// esa lista entera contra los hechos del caso -- criterio jurídico real,
// no coincidencia de palabras. Límite de seguridad muy por encima de lo
// esperable, por si la biblioteca crece mucho más de lo previsto.
$stmtTitulos = $pdo->query(
    'SELECT registro_digital, rubro FROM jurisprudencia_tesis ORDER BY registro_digital LIMIT 20000'
);
$titulos = $stmtTitulos->fetchAll();
if (!$titulos) {
    respond([
        'respuesta' => 'La biblioteca de jurisprudencia todavía está vacía -- no hay tesis cargadas todavía.',
        'tesis' => [],
    ]);
}

$registrosValidos = [];
$listado = [];
foreach ($titulos as $t) {
    $reg = (int)$t['registro_digital'];
    $registrosValidos[$reg] = true;
    $listado[] = $reg . ': ' . mb_strimwidth((string)$t['rubro'], 0, 300, '…');
}
$listadoTexto = implode("\n", $listado);

// Con miles de líneas casi todas parecidas entre sí, una pasada "rápida"
// (thinking desactivado) se le puede pasar por alto una línea concreta a
// media lista -- se detectó justo eso en pruebas reales (una tesis con el
// nombre de la dependencia LITERAL en el rubro, no encontrada). Activar
// razonamiento adaptativo le da margen para revisar la lista con cuidado
// en vez de una lectura superficial -- tarda más, pero para esto importa
// más la exactitud que la velocidad.
$seleccion = jurisprudencia_llamar_claude([
    'model' => IA_MODEL,
    // Generoso a propósito: revisar linea por linea unas 4000+ lineas del
    // catalogo con cuidado real (no una lectura superficial) puede
    // consumir bastantes tokens de razonamiento antes de llegar a la
    // respuesta final -- si se corta a la mitad, se pierde el "REGISTROS:"
    // final y la busqueda falla por completo.
    'max_tokens' => 16000,
    'thinking' => ['type' => 'adaptive'],
    'system' => 'Un abogado laboralista mexicano te describe los HECHOS de un caso real (no es una pregunta de '
        . 'tema general). Te doy, a continuación, el catálogo COMPLETO de la biblioteca de tesis y jurisprudencia '
        . 'laboral de la SCJN con la que cuenta el despacho -- cada línea es "registro digital: rubro". Revisa '
        . 'TODO el catálogo, línea por línea y con cuidado (son miles de líneas muy parecidas entre sí -- no lo '
        . 'hagas por encima, es fácil que a una lectura rápida se le escape justo la línea más relevante), con '
        . 'criterio jurídico real (no busques coincidencia de palabras sueltas) e identifica hasta 10 tesis que '
        . 'de verdad aplican a los hechos de este caso concreto -- que le sirvan de verdad al abogado para '
        . 'resolverlo o argumentarlo, no solo que compartan tema general. Un mismo caso puede plantear varias '
        . 'figuras jurídicas a la vez (ej. un despido donde el patrón alega abandono de empleo puede implicar a '
        . 'la vez: abandono de empleo, carga de la prueba del despido, aviso de rescisión, prescripción) -- '
        . 'considera todas las que apliquen. Al final de tu revisión, responde con una última línea que empiece '
        . 'exactamente con "REGISTROS:" seguido de los números de registro digital de las tesis que sí aplican, '
        . 'separados por comas, en orden de qué tan aplicable es cada una (la más aplicable primero). Si ninguna '
        . 'aplica de verdad, esa última línea debe decir exactamente "REGISTROS: NINGUNA".',
    'messages' => [[
        'role' => 'user',
        'content' => "Hechos del caso: {$pregunta}\n\nCatálogo completo de la biblioteca:\n\n{$listadoTexto}",
    ]],
], 280);

// Se toma solo lo que venga después de "REGISTROS:" (la última que
// aparezca, por si la palabra se menciona antes de la línea final) -- así
// no se confunden números de otras tesis que la IA haya mencionado de
// pasada en su análisis. Si por algún motivo no sigue el formato pedido,
// se cae en buscar números en todo el texto como respaldo.
$posRegistros = mb_strripos($seleccion, 'REGISTROS:');
$listaNumeros = $posRegistros !== false
    ? mb_substr($seleccion, $posRegistros + mb_strlen('REGISTROS:'))
    : $seleccion;

preg_match_all('/\d+/', $listaNumeros, $m);
$registrosElegidos = [];
foreach ($m[0] as $numStr) {
    $num = (int)$numStr;
    // Solo se aceptan números que de verdad estén en el catálogo que se le
    // mandó -- protege contra que la IA "invente" o transcriba mal un
    // registro que no existe.
    if (isset($registrosValidos[$num]) && !in_array($num, $registrosElegidos, true)) {
        $registrosElegidos[] = $num;
    }
    if (count($registrosElegidos) >= 10) break;
}

if (!$registrosElegidos) {
    respond([
        'respuesta' => 'Revisé el catálogo completo de la biblioteca y no encontré ninguna tesis que aplique de verdad a los hechos de este caso. Puede que todavía no se haya cargado jurisprudencia sobre este tema específico, o que el caso no tenga un criterio de la SCJN directamente aplicable.',
        'tesis' => [],
    ]);
}

// Paso 2: ya con la lista corta (hasta 10) de tesis que sí aplican, se trae
// el texto completo de cada una y se le pide a Claude que redacte la
// respuesta final explicando cómo se conecta cada una con los hechos.
$placeholders = implode(',', array_fill(0, count($registrosElegidos), '?'));
$stmtDetalle = $pdo->prepare(
    "SELECT registro_digital, instancia, epoca, numero_tesis, tipo, materias, rubro, texto_completo, fecha_publicacion
     FROM jurisprudencia_tesis WHERE registro_digital IN ($placeholders)"
);
$stmtDetalle->execute($registrosElegidos);
$porRegistro = [];
foreach ($stmtDetalle->fetchAll() as $t) {
    $porRegistro[(int)$t['registro_digital']] = $t;
}
// Se preserva el orden de relevancia que dio la IA en el paso 1, no el
// orden en que la consulta SQL devolvió las filas.
$candidatas = [];
foreach ($registrosElegidos as $reg) {
    if (isset($porRegistro[$reg])) $candidatas[] = $porRegistro[$reg];
}

// Se manda el texto completo de cada candidata recortado -- ya trae de
// más (encabezado y pie de página del sitio de la SCJN, no solo el cuerpo
// de la tesis, porque se guardó el innerText completo de la ficha), pero
// recortarlo controla el costo y casi siempre el contenido real de la
// tesis ya cabe en ese margen.
$bloques = [];
foreach ($candidatas as $t) {
    $bloques[] = "[Registro digital: {$t['registro_digital']}]\n"
        . "Rubro: {$t['rubro']}\n"
        . 'Instancia: ' . ($t['instancia'] ?: '(sin dato)') . ' | Época: ' . ($t['epoca'] ?: '(sin dato)')
        . ' | Tesis: ' . ($t['numero_tesis'] ?: '(sin dato)') . ' | Publicación: ' . ($t['fecha_publicacion'] ?: '(sin dato)') . "\n"
        . 'Texto: ' . mb_strimwidth((string)$t['texto_completo'], 0, 3000, '…');
}
$contexto = implode("\n\n---\n\n", $bloques);

$texto = jurisprudencia_llamar_claude([
    'model' => IA_MODEL,
    'max_tokens' => 2000,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Eres el asistente jurídico interno de un despacho de derecho laboral en México. Un abogado del '
        . 'despacho te describe los HECHOS de un caso real y te doy, junto con ellos, el texto completo de las '
        . 'tesis de la SCJN que ya se identificaron como aplicables a ese caso (una revisión previa del catálogo '
        . 'completo de la biblioteca ya descartó las que no aplican -- todas las que ves aquí SÍ tienen relación '
        . 'real con el caso). Tu trabajo: '
        . "1) Para cada tesis, explica en español claro y concreto cómo se conecta con los hechos específicos "
        . "del caso -- qué le aporta al abogado (un argumento a su favor, un criterio sobre cómo computar un "
        . "plazo, cómo se distribuye la carga de la prueba, etc.), citando siempre el número de registro digital "
        . "y el rubro.\n"
        . "2) Si al leer el texto completo de alguna te das cuenta de que en realidad no aplica tan bien como "
        . "parecía por el título, puedes decirlo -- no estás obligado a forzar todas.\n"
        . "3) Cierra con una conclusión práctica y breve de cómo usar esto en el caso.\n"
        . "4) NUNCA menciones, cites, ni inventes una tesis que no esté en la lista que te doy -- son las únicas "
        . "que existen para efectos de esta respuesta.\n"
        . 'Sé directo y concreto -- le hablas a un abogado, no hace falta explicar conceptos básicos de derecho laboral.',
    'messages' => [[
        'role' => 'user',
        'content' => "Hechos del caso: {$pregunta}\n\nTesis aplicables:\n\n{$contexto}",
    ]],
]);
if ($texto === '') fail('La IA no devolvió texto.', 502);

// Se manda el texto_completo entero (sin el recorte que sí se le aplicó a
// lo que ve la IA arriba) para que el frontend pueda mostrar la tesis
// completa si el abogado quiere leerla, sin necesitar una segunda llamada.
$tesisConsultadas = array_map(static function ($t) {
    return [
        'registro_digital' => (int)$t['registro_digital'],
        'rubro' => $t['rubro'],
        'instancia' => $t['instancia'],
        'epoca' => $t['epoca'],
        'numero_tesis' => $t['numero_tesis'],
        'fecha_publicacion' => $t['fecha_publicacion'],
        'texto_completo' => $t['texto_completo'],
    ];
}, $candidatas);

respond([
    'respuesta' => $texto,
    'tesis' => $tesisConsultadas,
]);
