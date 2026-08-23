<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php'; // solo para reusar la constante IA_MODEL

// Buscador de jurisprudencia laboral — versión barata en 3 pasos,
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
// Paso 0 — expandir términos (barato, ~1-2 centavos): traduce la
// descripción coloquial del abogado a términos jurídicos probables antes
// de buscar (ver jurisprudencia_expandir_terminos) — sin esto, preguntas
// tan normales como "cómo se integra el salario" no encontraban la tesis
// general del tema, solo resoluciones muy específicas que compartían la
// palabra "salario" pero no el concepto. Esto no es búsqueda semántica de
// verdad (si el abogado y la tesis usan vocabulario MUY distinto, puede
// seguir sin aparecer) — si con uso real se ve que sigue sin bastar, el
// siguiente paso sería usar embeddings en vez de FULLTEXT, pero eso es
// otro proveedor/costo aparte, se evalúa solo si de verdad hace falta.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
require_login();
require_csrf();

$in = json_input();
$pregunta = trim((string)($in['pregunta'] ?? ''));
if ($pregunta === '') fail('Describe los hechos del caso.', 400);
if (mb_strlen($pregunta) > 2000) fail('La descripción es demasiado larga.', 400);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

// Paso 0 (barato, ~1-2 centavos): antes de buscar, se le pide a Claude que
// traduzca los hechos del caso (lenguaje coloquial del abogado) a los
// TÉRMINOS jurídicos con los que probablemente está redactado el rubro de
// la tesis real -- sin esto, una pregunta tan normal como "cómo se integra
// el salario" no encontraba la tesis general del tema (Art. 84 LFT), solo
// resoluciones muy específicas que sí compartían la palabra "salario"
// pero no el concepto general. El texto original del abogado NO se
// descarta -- los términos se agregan aparte, para no perder ninguna
// palabra específica que ya haya usado bien.
function jurisprudencia_expandir_terminos(string $pregunta): string
{
    $payload = [
        'model' => IA_MODEL,
        'max_tokens' => 200,
        'thinking' => ['type' => 'disabled'],
        'system' => 'Un abogado laboralista mexicano te describe, en lenguaje coloquial, los hechos de un caso '
            . 'real. Tu única tarea es traducir esa descripción a los términos y frases jurídicas EXACTAS con '
            . 'las que probablemente esté redactado el RUBRO (título) de una tesis o jurisprudencia real de la '
            . "SCJN sobre ese tema.\n\n"
            . "Reglas:\n"
            . "- Da de 5 a 8 términos o frases, ordenados del más específico/probable al más general.\n"
            . '- Usa nombres técnicos reales de figuras e instituciones jurídicas laborales mexicanas (ej. '
            . '"presunción de relación laboral", "sustitución patronal", "responsabilidad solidaria", "falta de '
            . 'inscripción ante el IMSS"), no descripciones genéricas de lo que pasó.'
            . "\n"
            . '- Evita términos sueltos demasiado amplios que por sí solos aparecen en cientos de tesis de temas '
            . 'no relacionados (ej. nunca des solo "prestaciones", "salario" o "seguridad social" sueltos) -- si '
            . 'hace falta un concepto así de amplio, combínalo con lo específico del caso en la misma frase (ej. '
            . '"prestaciones de seguridad social por riesgo de trabajo", no "prestaciones de seguridad social").'
            . "\n"
            . '- Si el caso apunta claramente a un artículo específico de la LFT o la LSS, inclúyelo (ej. '
            . "\"artículo 47 LFT\").\n"
            . '- No repitas el texto original tal cual ni des explicaciones. Responde SOLO con los términos '
            . 'separados por comas, sin numerar.',
        'messages' => [['role' => 'user', 'content' => "Hechos del caso: {$pregunta}"]],
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
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status !== 200) {
        file_put_contents(__DIR__ . '/ia_debug.log', date('c')
            . " | [jurisprudencia_buscar expandir_terminos] FALLÓ status=$status | curl=$curlError | body="
            . mb_strimwidth((string)$raw, 0, 500, '…') . "\n", FILE_APPEND);
        return '';
    }

    $data = json_decode($raw, true);
    $texto = '';
    foreach (($data['content'] ?? []) as $bloque) {
        if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
    }
    $u = $data['usage'] ?? [];
    file_put_contents(__DIR__ . '/ia_debug.log', date('c')
        . " | [jurisprudencia_buscar expandir_terminos] input=" . ($u['input_tokens'] ?? 0)
        . " | output=" . ($u['output_tokens'] ?? 0) . " | terminos=" . trim($texto) . "\n", FILE_APPEND);
    return trim($texto);
}

$terminosExpandidos = jurisprudencia_expandir_terminos($pregunta);
// Si este paso falla por cualquier motivo (red, la IA no contestó, etc.),
// simplemente no hay términos que sumar y se sigue solo con el texto
// original del abogado, como antes de este cambio.

$pdo = db();

// Paso 1 (gratis, instantáneo): candidatas por búsqueda de texto normal de
// MySQL sobre el rubro (el título) de cada tesis. Requiere el índice
// FULLTEXT ft_rubro — ver sql/migraciones/031_jurisprudencia_fulltext_rubro.sql.
//
// Importante: el texto original y los términos expandidos del Paso 0 se
// buscan CADA UNO POR SEPARADO (no concatenados en una sola consulta) y
// luego se juntan los resultados sin duplicar. Se probó concatenarlos en
// un solo MATCH ... AGAINST() y salió peor -- MySQL en NATURAL LANGUAGE
// MODE no exige que coincidan todas las palabras, solo suma puntos por
// cada una que aparezca, así que meter 10 términos de golpe hacía que
// tesis que no tenían nada que ver con el caso real (pero sí coincidían
// en varios términos sueltos como "aguinaldo" + "prima vacacional" +
// "artículo 84") le ganaran en relevancia a la tesis correcta, que solo
// coincidía fuerte en un término específico ("vales de despensa"). Buscar
// cada texto aparte y unir resultados deja que el texto original siga
// encontrando lo que ya encontraba bien, y los términos expandidos solo
// SUMAN candidatas nuevas para preguntas genéricas, nunca le quitan lugar
// a las que ya iban a aparecer.
function jurisprudencia_buscar_candidatas(PDO $pdo, string $texto): array
{
    if (trim($texto) === '') return [];
    $stmt = $pdo->prepare(
        "SELECT registro_digital, instancia, epoca, numero_tesis, materias, rubro, texto_completo, fecha_publicacion,
                MATCH(rubro) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevancia
         FROM jurisprudencia_tesis
         WHERE MATCH(rubro) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
         ORDER BY relevancia DESC
         LIMIT 20"
    );
    $stmt->execute([':q' => $texto, ':q2' => $texto]);
    return $stmt->fetchAll();
}

$candidatas = jurisprudencia_buscar_candidatas($pdo, $pregunta);
$yaIncluidas = array_column($candidatas, 'registro_digital');
foreach (jurisprudencia_buscar_candidatas($pdo, $terminosExpandidos) as $t) {
    if (!in_array($t['registro_digital'], $yaIncluidas, true)) {
        $candidatas[] = $t;
        $yaIncluidas[] = $t['registro_digital'];
    }
}

if (!$candidatas) {
    respond([
        'respuesta' => 'No encontré ninguna tesis cuyo título coincida con las palabras de los hechos que describiste (esta primera etapa busca por palabras clave, no por significado). Intenta reformular usando los términos jurídicos más específicos del tema — por ejemplo, en vez de contar toda la historia, usa palabras como "sustitución patronal", "abandono de empleo", "carga de la prueba", "salarios caídos", etc.',
        'tesis' => [],
    ]);
}

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

// PRUEBA TEMPORAL de costo: este Paso 2 es ~90% del costo de la búsqueda
// (candidatas=40 mete mucho contexto). Se prueba aquí con Haiku 4.5 (~1/3
// del precio de Sonnet 5) para ver si el criterio jurídico se sostiene lo
// suficiente -- si la calidad se nota peor con casos reales, revertir a
// IA_MODEL (Sonnet 5).
$modeloPaso2 = 'claude-haiku-4-5-20251001';
$payload = [
    'model' => $modeloPaso2,
    'max_tokens' => 3000,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Eres el asistente jurídico interno de un despacho de derecho laboral en México. Un abogado del '
        . 'despacho (no un cliente, no alguien que necesita que le digan qué hacer) te describe los HECHOS de un '
        . 'caso real, y te doy junto con ellos una lista corta de tesis de la SCJN que una búsqueda por palabras '
        . 'clave preseleccionó como posibles candidatas (pueden incluir falsos positivos que solo coinciden en '
        . 'tema general, no en fondo). '
        . 'Tu trabajo NO es decirle al abogado qué estrategia seguir ni qué le conviene hacer -- eso ya lo sabe '
        . 'de sobra, es abogado. Tu trabajo es (1) descartar sin miedo las tesis que no aplican de verdad a los '
        . 'hechos, y (2) para cada una que sí aplica, darle una INTERPRETACIÓN jurídica real: qué es lo que esa '
        . "tesis establece de fondo (el criterio, no el rubro repetido) y cómo conecta con los hechos concretos "
        . "del caso. Responde SIEMPRE con este formato exacto:\n\n"
        . "## Tesis aplicables\n"
        . "Una subsección por cada tesis que SÍ aplica (omite las que no), en este orden exacto: `### [registro "
        . "digital] — [versión corta del rubro, máximo ~12 palabras]`, seguido de una interpretación de 3-5 "
        . "líneas en español claro y concreto: qué criterio jurídico fija realmente esa tesis (no repitas el "
        . "rubro ni des un resumen genérico) y en qué parte exacta de los hechos descritos aplica ese criterio. "
        . "No le digas al abogado qué hacer con la tesis -- explícasela, él decide cómo usarla.\n\n"
        . "## Sin aplicación directa\n"
        . 'Si NINGUNA tesis de la lista aplica de verdad a los hechos (o si los hechos son demasiado escuetos '
        . 'para saberlo), dilo aquí en 1-2 líneas con honestidad, sin forzar una tesis que no ayuda -- no des '
        . "consejo de qué hacer, solo señala qué falta (ej. qué prestación, qué patrón, qué contrato).\n\n"
        . 'Reglas: NUNCA menciones, cites, ni inventes una tesis que no esté en la lista que te doy. No repitas '
        . 'el rubro completo de cada tesis (ya se ve aparte en pantalla) — ve directo a la interpretación. Nada '
        . 'de introducciones ni cierres genéricos fuera de estas secciones, y nunca frases del tipo "te conviene", '
        . '"deberías", "la mejor estrategia es" -- describe el criterio de la tesis, no una recomendación.',
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
