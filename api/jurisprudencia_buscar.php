<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php'; // solo para reusar la constante IA_MODEL

// Buscador de jurisprudencia laboral con IA -- disponible para cualquier
// usuario con sesión (de cualquier despacho, no solo Administrador): la
// biblioteca de tesis (jurisprudencia_tesis) es compartida entre todos los
// despachos del sistema, no se divide por despacho.
//
// POST { pregunta }: busca en la biblioteca las tesis mas relacionadas por
// texto (MySQL FULLTEXT), y le pide a la IA que redacte una respuesta
// usando SOLO esas tesis -- nunca puede inventar una tesis ni citar un
// registro que no venga en la lista que se le manda, porque son las unicas
// que conoce.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
if ($pregunta === '') fail('Escribe tu pregunta.', 400);
if (mb_strlen($pregunta) > 800) fail('La pregunta es demasiado larga.', 400);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

// Llamada minima reusada dos veces en este archivo (extraer palabras clave
// y, mas abajo, redactar la respuesta final) -- evita repetir el mismo
// bloque de curl dos veces.
function jurisprudencia_llamar_claude(array $payload): string
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
        CURLOPT_TIMEOUT => 60,
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

// Paso 1: convertir la pregunta (lenguaje natural, con palabras muy
// comunes en cualquier tesis laboral -- "trabajador", "despedido",
// "patrón") en 4-8 términos jurídicos precisos. Buscar con la pregunta
// completa tal cual daba resultados pobres: MATCH...AGAINST en modo
// natural le resta peso a las palabras que aparecen en casi todas las
// tesis, así que con una pregunta larga casi no le quedaban palabras
// realmente distintivas con las que discriminar.
$palabrasClave = jurisprudencia_llamar_claude([
    'model' => IA_MODEL,
    'max_tokens' => 100,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Convierte la pregunta de un abogado laboralista mexicano en 4 a 8 términos/frases jurídicas '
        . 'precisas para buscar en un motor de búsqueda de texto sobre jurisprudencia y tesis de la SCJN '
        . '(ej. "abandono de empleo", "aviso de rescisión", "prescripción despido injustificado"). Responde '
        . 'ÚNICAMENTE con los términos separados por espacios, sin numerarlos, sin explicación, sin comillas.',
    'messages' => [['role' => 'user', 'content' => $pregunta]],
]);
if ($palabrasClave === '') $palabrasClave = $pregunta;

$pdo = db();

// IN NATURAL LANGUAGE MODE, pero ya con los términos precisos del paso 1
// en vez de la pregunta completa -- cada palabra pesa como término
// distintivo real, no se diluye entre puras palabras comunes. :q se manda
// dos veces (SELECT + WHERE) porque PDO no deja reusar un named
// placeholder dos veces en modo no emulado.
$stmt = $pdo->prepare(
    'SELECT registro_digital, instancia, epoca, numero_tesis, tipo, materias, rubro, texto_completo, fecha_publicacion,
            MATCH(rubro, texto_completo) AGAINST (:q1 IN NATURAL LANGUAGE MODE) AS relevancia
     FROM jurisprudencia_tesis
     WHERE MATCH(rubro, texto_completo) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
     ORDER BY relevancia DESC
     LIMIT 10'
);
$stmt->execute([':q1' => $palabrasClave, ':q2' => $palabrasClave]);
$candidatas = $stmt->fetchAll();

if (!$candidatas) {
    respond([
        'respuesta' => 'No encontré tesis en la biblioteca relacionadas con tu pregunta. Intenta describirla con otras palabras, o puede que todavía no se haya cargado jurisprudencia sobre ese tema específico.',
        'tesis' => [],
    ]);
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
        . 'despacho te hace una pregunta y te doy, junto con ella, una lista de tesis/jurisprudencia de la SCJN '
        . 'que una búsqueda de texto encontró como posiblemente relacionadas -- la búsqueda es por palabras, así '
        . 'que ALGUNAS de estas tesis pueden en realidad no aplicar a la pregunta. Tu trabajo: '
        . "1) De la lista que te doy, identifica cuáles tesis SÍ aplican de verdad a la pregunta.\n"
        . "2) Redacta una respuesta clara en español explicando cómo aplican, citando siempre el número de "
        . "registro digital y el rubro de cada tesis que uses.\n"
        . "3) Si NINGUNA de las tesis que te doy aplica realmente, dilo con claridad en vez de forzar una "
        . "relación que no existe.\n"
        . "4) NUNCA menciones, cites, ni inventes una tesis que no esté en la lista que te doy -- son las únicas "
        . "que existen para efectos de esta respuesta. Si hace falta más contexto (fechas, datos del caso) para "
        . "orientar mejor, puedes pedirlo, pero primero da la mejor respuesta posible con lo que ya tienes.\n"
        . 'Sé directo y concreto -- le hablas a un abogado, no hace falta explicar conceptos básicos de derecho laboral.',
    'messages' => [[
        'role' => 'user',
        'content' => "Pregunta: {$pregunta}\n\nTesis encontradas por la búsqueda:\n\n{$contexto}",
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
