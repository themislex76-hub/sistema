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
// el TÍTULO de TODAS las tesis de la biblioteca. Mandarlas TODAS de un
// jalón en una sola llamada (con o sin razonamiento extendido) se probó y
// funciona, pero sigue siendo una sola lectura de miles de líneas muy
// parecidas entre sí -- hay riesgo real de que se le pase alguna por alto
// en medio de la lista. Por eso el catálogo se divide en partes más chicas
// (ver jurisprudencia_llamar_claude_paralelo) que se revisan EN PARALELO,
// cada una mucho más manejable, y luego una pasada corta junta y afina los
// candidatos de todas las partes. Solo hasta el final se pide el texto
// completo de las que de verdad aplican, para la respuesta final. Nunca
// puede citar una tesis que no esté en la biblioteca, porque son las
// únicas que se le muestran en algún momento del proceso.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

// Esta búsqueda hace varias llamadas a la IA (el catálogo por partes,
// afinado y respuesta final) y puede tardar bastante más que una petición
// normal del sistema -- sin esto, el límite de tiempo por defecto del
// hosting compartido (típicamente 30s) corta el script a la mitad.
set_time_limit(360);

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
if ($pregunta === '') fail('Describe los hechos del caso.', 400);
if (mb_strlen($pregunta) > 2000) fail('La descripción es demasiado larga.', 400);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

// Llamada individual a Claude, reusada varias veces en este archivo (el
// afinado de candidatos y la respuesta final) -- evita repetir el mismo
// bloque de curl cada vez.
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

// Corre varias llamadas a Claude EN PARALELO (curl_multi) -- se usa para
// revisar el catálogo por partes al mismo tiempo, en vez de una tras otra
// (que tardaría N veces más). Si alguna parte falla (red, timeout), esa
// parte simplemente no aporta candidatos -- no se cae toda la búsqueda por
// un problema en una sola parte.
function jurisprudencia_llamar_claude_paralelo(array $payloadsPorClave, int $timeoutSegundos): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($payloadsPorClave as $clave => $payload) {
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
        curl_multi_add_handle($mh, $ch);
        $handles[$clave] = $ch;
    }

    $activos = null;
    do {
        $estado = curl_multi_exec($mh, $activos);
        if ($activos > 0) curl_multi_select($mh);
    } while ($activos > 0 && $estado === CURLM_OK);

    $resultados = [];
    foreach ($handles as $clave => $ch) {
        $raw = curl_multi_getcontent($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw !== null && $raw !== false && $status === 200) {
            $data = json_decode((string)$raw, true);
            $texto = '';
            foreach (($data['content'] ?? []) as $bloque) {
                if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
            }
            $resultados[$clave] = trim($texto);
        } else {
            file_put_contents(__DIR__ . '/ia_debug.log', date('c')
                . " | [jurisprudencia_buscar parte=$clave] status=$status | body=" . mb_substr((string)$raw, 0, 500) . "\n", FILE_APPEND);
            $resultados[$clave] = '';
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $resultados;
}

// Interpreta la respuesta de un paso de selección: toma solo lo que venga
// después de "REGISTROS:" (la última aparición, por si la palabra se
// menciona antes de la línea final) y valida cada número contra el
// catálogo real -- protege contra que la IA invente o transcriba mal un
// registro que no existe. $registrosValidos se define más abajo.
function jurisprudencia_extraer_registros(string $texto, array $registrosValidos, int $maximo = 10): array
{
    $pos = mb_strripos($texto, 'REGISTROS:');
    $listaNumeros = $pos !== false ? mb_substr($texto, $pos + mb_strlen('REGISTROS:')) : $texto;
    preg_match_all('/\d+/', $listaNumeros, $m);
    $elegidos = [];
    foreach ($m[0] as $numStr) {
        $num = (int)$numStr;
        if (isset($registrosValidos[$num]) && !in_array($num, $elegidos, true)) {
            $elegidos[] = $num;
        }
        if (count($elegidos) >= $maximo) break;
    }
    return $elegidos;
}

$pdo = db();

// Se listan TODOS los títulos de la biblioteca (registro + rubro, nunca el
// texto completo en este paso). Límite de seguridad muy por encima de lo
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
$rubroPorRegistro = [];
$listado = [];
foreach ($titulos as $t) {
    $reg = (int)$t['registro_digital'];
    $rubroRecortado = mb_strimwidth((string)$t['rubro'], 0, 300, '…');
    $registrosValidos[$reg] = true;
    $rubroPorRegistro[$reg] = $rubroRecortado;
    $listado[] = $reg . ': ' . $rubroRecortado;
}

// FASE 1 (map): el catálogo se divide en partes de ~700 líneas -- cada
// parte es lo bastante corta para que revisarla con cuidado sea confiable
// (a diferencia de mandar las 4000+ líneas de un jalón), y las partes se
// revisan TODAS AL MISMO TIEMPO (curl_multi), no una tras otra, para que
// dividir el catálogo no multiplique el tiempo de espera.
$TAMANO_PARTE = 700;
$partes = array_chunk($listado, $TAMANO_PARTE);
$totalPartes = count($partes);

$payloadsPartes = [];
foreach ($partes as $i => $lineasParte) {
    $payloadsPartes[$i] = [
        'model' => IA_MODEL,
        'max_tokens' => 700,
        'thinking' => ['type' => 'disabled'],
        'system' => 'Un abogado laboralista mexicano te describe los HECHOS de un caso real (no es una pregunta '
            . 'de tema general). Te doy UNA PARTE (parte ' . ($i + 1) . ' de ' . $totalPartes . ') del catálogo '
            . 'de la biblioteca de tesis y jurisprudencia laboral de la SCJN con la que cuenta el despacho -- '
            . 'cada línea es "registro digital: rubro". Es normal y está bien que en esta parte no haya ninguna '
            . 'tesis aplicable (las demás partes se revisan por separado, en paralelo). Revisa esta parte '
            . 'completa, línea por línea, con criterio jurídico real (no coincidencia de palabras sueltas), e '
            . 'identifica hasta 5 tesis DE ESTA PARTE que de verdad podrían aplicar a los hechos del caso -- '
            . 'ante la duda de si aplica, inclúyela (se van a revisar de nuevo después con más detalle). Un '
            . 'mismo caso puede plantear varias figuras jurídicas a la vez (ej. un despido donde el patrón '
            . 'alega abandono de empleo puede implicar a la vez: abandono de empleo, carga de la prueba del '
            . 'despido, aviso de rescisión, prescripción). Responde con una última línea que empiece '
            . 'exactamente con "REGISTROS:" seguido de los números de registro digital separados por comas, o '
            . '"REGISTROS: NINGUNA" si ninguna de esta parte aplica.',
        'messages' => [[
            'role' => 'user',
            'content' => "Hechos del caso: {$pregunta}\n\nParte del catálogo:\n\n" . implode("\n", $lineasParte),
        ]],
    ];
}

$resultadosPartes = jurisprudencia_llamar_claude_paralelo($payloadsPartes, 100);

$candidatosUnion = [];
foreach ($resultadosPartes as $textoParte) {
    foreach (jurisprudencia_extraer_registros($textoParte, $registrosValidos, 5) as $reg) {
        if (!in_array($reg, $candidatosUnion, true)) $candidatosUnion[] = $reg;
    }
}

if (!$candidatosUnion) {
    respond([
        'respuesta' => 'Revisé el catálogo completo de la biblioteca (en partes) y no encontré ninguna tesis que aplique de verdad a los hechos de este caso. Puede que todavía no se haya cargado jurisprudencia sobre este tema específico, o que el caso no tenga un criterio de la SCJN directamente aplicable.',
        'tesis' => [],
    ]);
}

// FASE 2 (reduce): con los candidatos de todas las partes ya juntos (una
// lista corta), una última pasada elige y ordena los que de verdad
// aplican -- también actúa como filtro de segunda opinión contra algún
// falso positivo que una parte haya propuesto "por si acaso".
$listadoCandidatosTexto = implode("\n", array_map(
    fn($reg) => $reg . ': ' . $rubroPorRegistro[$reg],
    $candidatosUnion
));

$seleccionFinal = jurisprudencia_llamar_claude([
    'model' => IA_MODEL,
    'max_tokens' => 800,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Un abogado laboralista mexicano te describe los HECHOS de un caso real. Te doy una lista corta '
        . 'de tesis candidatas -- ya se preseleccionaron de la biblioteca completa, pero algunas pueden ser '
        . 'falsos positivos ("por si acaso"). Revísalas con cuidado y elige hasta 10 que de verdad aplican a '
        . 'los hechos de este caso concreto -- que le sirvan de verdad al abogado para resolverlo o '
        . 'argumentarlo, no solo que compartan tema general -- en orden de qué tan aplicable es cada una (la '
        . 'más aplicable primero). Descarta cualquiera que en realidad no aplique. Responde con una última '
        . 'línea que empiece exactamente con "REGISTROS:" seguido de los números separados por comas, o '
        . '"REGISTROS: NINGUNA" si ninguna aplica de verdad.',
    'messages' => [[
        'role' => 'user',
        'content' => "Hechos del caso: {$pregunta}\n\nTesis candidatas:\n\n{$listadoCandidatosTexto}",
    ]],
], 60);

$registrosElegidos = jurisprudencia_extraer_registros($seleccionFinal, $registrosValidos, 10);
if (!$registrosElegidos) {
    respond([
        'respuesta' => 'Revisé el catálogo completo de la biblioteca y no encontré ninguna tesis que aplique de verdad a los hechos de este caso. Puede que todavía no se haya cargado jurisprudencia sobre este tema específico, o que el caso no tenga un criterio de la SCJN directamente aplicable.',
        'tesis' => [],
    ]);
}

// FASE 3: ya con la lista corta (hasta 10) de tesis que sí aplican, se trae
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
