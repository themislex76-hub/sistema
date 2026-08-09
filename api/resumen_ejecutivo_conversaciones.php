<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// Resumen ejecutivo de TODAS las conversaciones de WhatsApp del bot — para
// que el despacho vea de un vistazo de qué habla la gente, qué patrones se
// repiten, y qué oportunidades se están perdiendo. En vez de mandarle a la
// IA el historial completo de las 300+ conversaciones (carísimo e
// impráctico), se le manda el primer mensaje de cada una (el motivo
// original de contacto) más si calificó o no como prospecto — suficiente
// para detectar temas y patrones sin gastar de más.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$stmt = $pdo->query(
    "SELECT c.telefono, c.texto AS primer_mensaje, p.tipo AS prospecto_tipo, p.estatus AS prospecto_estatus
     FROM whatsapp_conversaciones c
     INNER JOIN (
         SELECT telefono, MIN(id) AS primer_id FROM whatsapp_conversaciones WHERE direccion = 'entrante' GROUP BY telefono
     ) t ON t.primer_id = c.id
     LEFT JOIN prospectos p ON p.telefono = c.telefono
     ORDER BY c.creado_en DESC
     LIMIT 400"
);
$filas = $stmt->fetchAll();

if (!$filas) {
    echo "No hay conversaciones registradas todavía.\n";
    exit;
}

$lineas = [];
foreach ($filas as $f) {
    $calif = $f['prospecto_tipo']
        ? "calificó como '{$f['prospecto_tipo']}' (estatus: {$f['prospecto_estatus']})"
        : 'no calificó como prospecto';
    $lineas[] = '- "' . str_replace(["\n", '"'], [' ', "'"], mb_strimwidth($f['primer_mensaje'], 0, 200, '...')) . '" — ' . $calif;
}

$transcript = implode("\n", $lineas);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) {
    echo "Falta anthropic_credentials.php.\n";
    exit;
}
require_once $credentialsFile;

$payload = [
    'model' => IA_MODEL,
    'max_tokens' => 3000,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Eres un asistente interno del despacho de derecho laboral Expertos Laborales Abogados. '
        . 'Te doy el primer mensaje de cada una de las últimas conversaciones de WhatsApp que la gente tuvo con '
        . 'su bot de asesoría automática, junto con si esa persona calificó o no como prospecto (lead). '
        . 'Escribe un RESUMEN EJECUTIVO en español, claro y accionable, para el dueño del despacho (no técnico). '
        . 'Incluye: (1) los 4-6 temas/motivos de contacto más comunes, con aproximadamente cuántos casos de cada '
        . 'uno viste (no exacto, aproximado está bien); (2) patrones que notes sobre por qué mucha gente NO '
        . 'califica como prospecto (por ejemplo: ya renunció, fuera de CDMX/Edomex, ya tiene abogado, etc.); '
        . '(3) cualquier oportunidad de negocio que notes (temas recurrentes que el despacho podría atender '
        . 'mejor, o volumen alto en algo específico); (4) cierra con 2-3 recomendaciones concretas y accionables. '
        . 'Usa encabezados simples con guiones, sin markdown de tablas, en un tono directo. No hagas un ensayo '
        . 'largo — va a leerlo alguien ocupado.',
    'messages' => [['role' => 'user', 'content' => "Aquí están las conversaciones (" . count($filas) . " en total):\n\n{$transcript}"]],
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
    echo "Falló la llamada a la IA. status=$status curl=$curlError body=" . (string)$raw . "\n";
    exit;
}

$data = json_decode($raw, true);
$texto = '';
foreach (($data['content'] ?? []) as $bloque) {
    if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
}

echo "RESUMEN EJECUTIVO — basado en " . count($filas) . " conversaciones\n";
echo str_repeat('=', 60) . "\n\n";
echo trim($texto) !== '' ? trim($texto) : "(la IA no devolvió texto — revisa ia_debug.log)\n";
