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

// Números REALES calculados por SQL, no estimados por la IA -- se
// detectó en producción que, con solo el primer mensaje de cada
// conversación, la IA "adivinaba" porcentajes y patrones (ej. "casi 100
// conversaciones mueren en un saludo") que al verificarlos contra la
// base de datos resultaron muy alejados de la realidad (8 casos reales,
// no ~100). Estos números sí están verificados y se le dan como dato
// duro para que escriba sobre ellos, no para que los reinvente.
$telefonos = array_column($filas, 'telefono');
$totalConversaciones = count($filas);
$totalCalificados = count(array_filter($filas, fn($f) => $f['prospecto_tipo'] !== null));

$saludoSinAvance = 0;
if ($telefonos) {
    $placeholders = implode(',', array_fill(0, count($telefonos), '?'));
    $stmtTodos = $pdo->prepare(
        "SELECT telefono, texto FROM whatsapp_conversaciones
         WHERE direccion = 'entrante' AND telefono IN ($placeholders)
         ORDER BY telefono, id"
    );
    $stmtTodos->execute($telefonos);
    $porTelefono = [];
    foreach ($stmtTodos->fetchAll() as $r) {
        $porTelefono[$r['telefono']][] = $r['texto'];
    }
    $palabraSaludo = '(hola+|oigan?|disculpe|se\s+podr[aá]|buenas?|tardes?|noches?|d[ií]as?|buen|lic\.?|licenciado[.,]?|licenciada[.,]?)';
    $patronSaludo = '/^' . $palabraSaludo . '([\s.,!¡¿?]+' . $palabraSaludo . ')*[\s.,!¡¿?]*$/iu';
    foreach ($porTelefono as $mensajes) {
        if (count($mensajes) > 3) continue;
        $todosSaludo = true;
        foreach ($mensajes as $t) {
            $t = trim((string)$t);
            if ($t === '' || mb_strlen($t) > 25 || preg_match($patronSaludo, $t) !== 1) {
                $todosSaludo = false;
                break;
            }
        }
        if ($todosSaludo) $saludoSinAvance++;
    }
}

$datosVerificados = "DATOS VERIFICADOS (calculados directo de la base de datos, no los estimes ni los cambies):\n"
    . "- Total de conversaciones en esta muestra: {$totalConversaciones}\n"
    . "- Calificaron como prospecto: {$totalCalificados} ("
    . round($totalConversaciones > 0 ? $totalCalificados / $totalConversaciones * 100 : 0, 1) . "%)\n"
    . "- Conversaciones donde el cliente SOLO mandó saludos genéricos (\"Hola\", \"Buenas tardes\") sin nunca "
    . "explicar su caso: {$saludoSinAvance} (" . round($totalConversaciones > 0 ? $saludoSinAvance / $totalConversaciones * 100 : 0, 1) . "%)\n"
    . "\nHECHOS VERIFICADOS SOBRE EL BOT (revisado directo en su código -- si tu análisis de las conversaciones "
    . "parece contradecir esto, EL CÓDIGO ES EL QUE MANDA, no lo que tú infieras):\n"
    . "- El bot NO filtra ni descarta a nadie por ubicación/estado -- atiende y ofrece la asesoría de pago "
    . "exactamente igual a alguien en Jalisco, Oaxaca o Sonora que a alguien en CDMX. Si ves mensajes de gente "
    . "fuera de CDMX/Edomex que no calificó, la ubicación NO fue la causa -- no la menciones como motivo ni "
    . "como oportunidad de negocio \"nueva\" (el despacho ya atiende foráneos igual que a los locales).\n"
    . "- El bot SÍ ofrece activamente los cursos en línea cuando detecta interés, y tiene un recordatorio "
    . "automático para quien mostró interés y no compró -- no es un interés que se esté desperdiciando sin "
    . "atender.";

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
        . 'su bot de asesoría automática, junto con si esa persona calificó o no como prospecto (lead), y unos '
        . 'DATOS VERIFICADOS calculados directo de la base de datos. '
        . 'Escribe un RESUMEN EJECUTIVO en español, claro y accionable, para el dueño del despacho (no técnico). '
        . 'Incluye: (1) los 4-6 temas/motivos de contacto más comunes, con aproximadamente cuántos casos de cada '
        . 'uno viste (no exacto, aproximado está bien, y solo de LO QUE VES en los primeros mensajes, no '
        . 'inventes categorías); (2) patrones que notes sobre por qué mucha gente NO califica como prospecto; '
        . '(3) cualquier oportunidad de negocio que notes; (4) cierra con 2-3 recomendaciones concretas y '
        . 'accionables. Usa encabezados simples con guiones, sin markdown de tablas, en un tono directo. No '
        . 'hagas un ensayo largo — va a leerlo alguien ocupado. '
        . 'REGLA DURA: solo tienes el PRIMER mensaje de cada conversación, no el resto del hilo -- NO sabes si '
        . 'el bot dio seguimiento, si la persona contestó después, ni el motivo real por el que alguien no '
        . 'calificó (podría ser ubicación, podría ser precio, podría ser que ya tiene abogado, podría ser que '
        . 'nunca volvió a escribir). Nunca afirmes una causa específica de abandono o de no-calificación si no '
        . 'la puedes ver literalmente en el texto del primer mensaje -- en vez de inventar un motivo, dilo como '
        . 'lo que es: "no califican, pero con solo el primer mensaje no se puede saber por qué sin revisar la '
        . 'conversación completa". Usa los DATOS VERIFICADOS tal cual te los doy (no los cambies, no calcules '
        . 'los tuyos) para cualquier cifra que menciones sobre calificación o abandono en saludo -- para '
        . 'cualquier OTRA cifra que menciones (temas, volúmenes por tipo de caso), dejala clara como estimado '
        . 'aproximado tuyo, no como dato duro.',
    'messages' => [['role' => 'user', 'content' => "{$datosVerificados}\n\nAquí están las conversaciones (" . count($filas) . " en total, mostrando el primer mensaje de cada una):\n\n{$transcript}"]],
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

// Nota de corrección puesta por CÓDIGO, no por instrucción a la IA --
// se detectó en producción que, pese a decirle explícitamente en el
// prompt que el bot no filtra por ubicación y sí ofrece los cursos
// activamente, el modelo seguía repitiendo esas dos afirmaciones falsas
// en el reporte (una "regla dura" en el prompt no es 100% dura para un
// modelo probabilístico). En vez de seguir peleando con el wording del
// prompt, esto garantiza que la corrección real SIEMPRE aparezca,
// sin importar lo que haya escrito la IA arriba.
echo "\n\n" . str_repeat('-', 60) . "\n";
echo "NOTA (agregada siempre por el sistema, no por la IA -- verificado en el código):\n";
echo "Si el reporte de arriba dice que se \"descartan\", \"pierden\" o \"filtran\" leads por estar fuera de\n";
echo "CDMX/Edomex, eso NO es cierto: el bot ofrece la asesoría de pago exactamente igual sin importar el\n";
echo "estado. Y si dice que el interés en cursos \"no se explota\" o \"se desperdicia\", tampoco es cierto:\n";
echo "el bot ya los ofrece activamente y tiene un recordatorio automático para quien no compró. Ignora\n";
echo "esas dos afirmaciones si aparecen arriba.\n";
