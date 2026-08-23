<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php'; // solo para reusar la constante IA_MODEL

// Buscador de jurisprudencia laboral — versión barata en 2 pasos,
// disponible para cualquier usuario con sesión (la biblioteca de tesis es
// compartida entre todos los despachos, no se divide por despacho).
//
// La versión anterior (ver historial de git) revisaba TODO el catálogo
// (~4,080 tesis) con la IA, en varias llamadas paralelas con razonamiento
// extendido — salía en ~$1.60-3.00 USD por búsqueda, no rentable. Esta
// versión hace la parte de "encontrar candidatas" con una búsqueda de
// texto normal de MySQL (FULLTEXT sobre el rubro/título — gratis e
// instantánea) y solo le pide a Claude que analice ese puñado ya
// preseleccionado, sin razonamiento extendido — mucho más barato.
//
// Contrapartida real: la precisión del primer paso depende de que las
// palabras que use el abogado coincidan con las del rubro/tesis — no es
// una búsqueda semántica de verdad (si describe el caso con puras
// palabras distintas a como está redactada la tesis, puede no aparecer
// como candidata). Si con uso real se ve que se le escapan tesis
// relevantes por esto, el siguiente paso sería usar embeddings en vez de
// FULLTEXT para esa primera etapa — pero eso es otro proveedor/costo
// aparte, se evalúa solo si de verdad hace falta.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
if ($pregunta === '') fail('Describe los hechos del caso.', 400);
if (mb_strlen($pregunta) > 2000) fail('La descripción es demasiado larga.', 400);

$pdo = db();

// Paso 1 (gratis, instantáneo): candidatas por búsqueda de texto normal de
// MySQL sobre el rubro (el título) de cada tesis, ordenadas por qué tanto
// coinciden sus palabras con los hechos del caso. Requiere el índice
// FULLTEXT ft_rubro — ver sql/migraciones/031_jurisprudencia_fulltext_rubro.sql.
$stmtCandidatas = $pdo->prepare(
    "SELECT registro_digital, instancia, epoca, numero_tesis, materias, rubro, texto_completo, fecha_publicacion,
            MATCH(rubro) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevancia
     FROM jurisprudencia_tesis
     WHERE MATCH(rubro) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
     ORDER BY relevancia DESC
     LIMIT 20"
);
$stmtCandidatas->execute([':q' => $pregunta, ':q2' => $pregunta]);
$candidatas = $stmtCandidatas->fetchAll();

if (!$candidatas) {
    respond([
        'respuesta' => 'No encontré ninguna tesis cuyo título coincida con las palabras de los hechos que describiste (esta primera etapa busca por palabras clave, no por significado). Intenta reformular usando los términos jurídicos más específicos del tema — por ejemplo, en vez de contar toda la historia, usa palabras como "sustitución patronal", "abandono de empleo", "carga de la prueba", "salarios caídos", etc.',
        'tesis' => [],
    ]);
}

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

// Paso 2 (barato): una sola llamada a Claude, sin razonamiento extendido,
// que revisa SOLO estas ≤20 candidatas ya preseleccionadas (no las 4,080)
// y descarta con criterio jurídico las que no aplican de verdad.
$bloques = [];
foreach ($candidatas as $t) {
    $bloques[] = "[Registro digital: {$t['registro_digital']}]\n"
        . "Rubro: {$t['rubro']}\n"
        . 'Instancia: ' . ($t['instancia'] ?: '(sin dato)') . ' | Época: ' . ($t['epoca'] ?: '(sin dato)')
        . ' | Tesis: ' . ($t['numero_tesis'] ?: '(sin dato)') . ' | Publicación: ' . ($t['fecha_publicacion'] ?: '(sin dato)') . "\n"
        . 'Texto: ' . mb_strimwidth((string)$t['texto_completo'], 0, 1200, '…');
}
$contexto = implode("\n\n---\n\n", $bloques);

$payload = [
    'model' => IA_MODEL,
    'max_tokens' => 3000,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Eres el asistente jurídico interno de un despacho de derecho laboral en México. Un abogado del '
        . 'despacho te describe los HECHOS de un caso real, y te doy junto con ellos una lista corta de tesis '
        . 'de la SCJN que una búsqueda por palabras clave preseleccionó como posibles candidatas (pueden incluir '
        . 'falsos positivos que solo coinciden en tema general, no en fondo). Tu trabajo es revisarlas con '
        . 'criterio jurídico real y quedarte solo con las que de verdad aplican a los hechos concretos del caso '
        . '— descarta sin miedo las que no aportan nada real. Le hablas a alguien ocupado que va a leer esto por '
        . "encima antes de entrar a una audiencia o redactar un escrito. Responde SIEMPRE con este formato exacto:\n\n"
        . "## Conclusión\n"
        . "2 a 4 líneas, directo al grano: qué le conviene hacer o argumentar al abogado con este caso, dado lo "
        . "que dicen las tesis que sí aplican. Si NINGUNA de la lista aplica de verdad, dilo aquí con honestidad "
        . "(es preferible decir que no hay nada aplicable a forzar una tesis que no ayuda).\n\n"
        . "## Tesis aplicables\n"
        . "Una subsección por cada tesis que SÍ aplica (omite las que no), en este orden exacto: `### [registro "
        . "digital] — [versión corta del rubro, máximo ~12 palabras]`, seguido de 2-4 líneas explicando en "
        . "español claro y concreto cómo se conecta ESA tesis con los hechos específicos del caso, no un resumen "
        . "genérico de qué dice la tesis.\n\n"
        . 'Reglas: NUNCA menciones, cites, ni inventes una tesis que no esté en la lista que te doy. No repitas '
        . 'el rubro completo de cada tesis (ya se ve aparte en pantalla) — ve directo a cómo aplica. Nada de '
        . 'introducciones ni cierres genéricos fuera de estas dos secciones.',
    'messages' => [[
        'role' => 'user',
        'content' => "Hechos del caso: {$pregunta}\n\nTesis candidatas (preseleccionadas por palabras clave, revísalas con criterio):\n\n{$contexto}",
    ]],
];

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
    CURLOPT_TIMEOUT => 90,
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
$texto = trim($texto);
if ($texto === '') fail('La IA no devolvió texto.', 502);

// Log temporal de costo real (tokens de entrada/salida de esta búsqueda) —
// para poder comparar contra la versión anterior con datos reales, no
// adivinando. Se puede quitar cuando ya se confirme que el costo se quedó
// bajo con uso real.
$u = $data['usage'] ?? [];
file_put_contents(__DIR__ . '/ia_debug.log', date('c')
    . " | [jurisprudencia_buscar] candidatas=" . count($candidatas)
    . " | input=" . ($u['input_tokens'] ?? 0) . " | output=" . ($u['output_tokens'] ?? 0) . "\n", FILE_APPEND);

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
