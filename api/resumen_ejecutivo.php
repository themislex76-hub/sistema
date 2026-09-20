<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ia_helpers.php';

// Resumen ejecutivo de TODAS las conversaciones de WhatsApp del bot — para
// que el despacho (y quien lo asesore, ej. para optimizar el bot) vea de un
// vistazo de qué habla la gente, qué patrones se repiten, y qué
// oportunidades se están perdiendo. Se guarda cada uno generado para llevar
// historial. Solo Administrador.
//
// GET  -> lista los resúmenes ya generados (id, fecha, num_conversaciones,
//         periodo_desde/periodo_hasta, contenido) para mostrarlos/copiarlos
//         en el sistema.
// POST -> genera uno nuevo (llama a la IA), lo guarda, y lo devuelve. Acepta
//         opcionalmente {desde, hasta} (YYYY-MM-DD) para acotar el resumen a
//         un periodo (hoy, última semana, este mes, un rango a mano, etc.) en
//         vez de siempre las últimas ~400 conversaciones sin importar cuándo
//         pasaron.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) fail('Método no permitido.', 405);
$user = require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT id, contenido, num_conversaciones, periodo_desde, periodo_hasta, creado_en FROM resumenes_ejecutivos ORDER BY id DESC LIMIT 20"
    );
    $resumenes = [];
    foreach ($stmt->fetchAll() as $r) {
        $resumenes[] = [
            'id' => (int)$r['id'],
            'contenido' => $r['contenido'],
            'num_conversaciones' => (int)$r['num_conversaciones'],
            'periodo_desde' => $r['periodo_desde'],
            'periodo_hasta' => $r['periodo_hasta'],
            'creado_en' => $r['creado_en'],
        ];
    }
    respond(['resumenes' => $resumenes]);
}

// POST: generar uno nuevo.
require_csrf();

$in = json_input();
$desde = trim((string)($in['desde'] ?? ''));
$hasta = trim((string)($in['hasta'] ?? ''));
$tienePeriodo = $desde !== '' && $hasta !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta);

// En vez de mandarle a la IA el historial completo de las conversaciones
// (carísimo e impráctico), se le manda el primer mensaje de cada una (el
// motivo original de contacto) más si calificó o no como prospecto —
// suficiente para detectar temas y patrones sin gastar de más. El tope de
// 500 aplica siempre (con o sin periodo) como salvaguarda de costo, no
// solo cuando no se pide un rango.
$sql = "SELECT c.telefono, c.texto AS primer_mensaje, p.tipo AS prospecto_tipo, p.estatus AS prospecto_estatus
     FROM whatsapp_conversaciones c
     INNER JOIN (
         SELECT telefono, MIN(id) AS primer_id FROM whatsapp_conversaciones WHERE direccion = 'entrante' GROUP BY telefono
     ) t ON t.primer_id = c.id
     LEFT JOIN prospectos p ON p.telefono = c.telefono";
$params = [];
if ($tienePeriodo) {
    $sql .= " WHERE c.creado_en >= :desde AND c.creado_en < :hasta";
    $params[':desde'] = $desde . ' 00:00:00';
    $params[':hasta'] = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00';
}
$sql .= " ORDER BY c.creado_en DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll();

if (!$filas) fail($tienePeriodo ? 'No hay conversaciones registradas en ese periodo.' : 'No hay conversaciones registradas todavía.', 400);

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
// base de datos resultaron muy alejados de la realidad. Estos números
// sí están verificados y se le dan como dato duro para que escriba
// sobre ellos, no para que los reinvente.
$telefonosResumen = array_column($filas, 'telefono');
$totalConversacionesResumen = count($filas);
$totalCalificadosResumen = count(array_filter($filas, fn($f) => $f['prospecto_tipo'] !== null));

$saludoSinAvanceResumen = 0;
if ($telefonosResumen) {
    $placeholdersResumen = implode(',', array_fill(0, count($telefonosResumen), '?'));
    $stmtTodosResumen = $pdo->prepare(
        "SELECT telefono, texto FROM whatsapp_conversaciones
         WHERE direccion = 'entrante' AND telefono IN ($placeholdersResumen)
         ORDER BY telefono, id"
    );
    $stmtTodosResumen->execute($telefonosResumen);
    $porTelefonoResumen = [];
    foreach ($stmtTodosResumen->fetchAll() as $r) {
        $porTelefonoResumen[$r['telefono']][] = $r['texto'];
    }
    $palabraSaludoResumen = '(hola+|oigan?|disculpe|se\s+podr[aá]|buenas?|tardes?|noches?|d[ií]as?|buen|lic\.?|licenciado[.,]?|licenciada[.,]?)';
    $patronSaludoResumen = '/^' . $palabraSaludoResumen . '([\s.,!¡¿?]+' . $palabraSaludoResumen . ')*[\s.,!¡¿?]*$/iu';
    foreach ($porTelefonoResumen as $mensajesResumen) {
        if (count($mensajesResumen) > 3) continue;
        $todosSaludoResumen = true;
        foreach ($mensajesResumen as $t) {
            $t = trim((string)$t);
            if ($t === '' || mb_strlen($t) > 25 || preg_match($patronSaludoResumen, $t) !== 1) {
                $todosSaludoResumen = false;
                break;
            }
        }
        if ($todosSaludoResumen) $saludoSinAvanceResumen++;
    }
}

$datosVerificadosResumen = "DATOS VERIFICADOS (calculados directo de la base de datos, no los estimes ni los cambies):\n"
    . "- Total de conversaciones en esta muestra: {$totalConversacionesResumen}\n"
    . "- Calificaron como prospecto: {$totalCalificadosResumen} ("
    . round($totalConversacionesResumen > 0 ? $totalCalificadosResumen / $totalConversacionesResumen * 100 : 0, 1) . "%)\n"
    . "- Conversaciones donde el cliente SOLO mandó saludos genéricos (\"Hola\", \"Buenas tardes\") sin nunca "
    . "explicar su caso: {$saludoSinAvanceResumen} (" . round($totalConversacionesResumen > 0 ? $saludoSinAvanceResumen / $totalConversacionesResumen * 100 : 0, 1) . "%)\n"
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
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
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
    'messages' => [['role' => 'user', 'content' =>
        ($tienePeriodo ? "Periodo: del $desde al $hasta.\n" : "Periodo: sin acotar (las conversaciones más recientes).\n")
        . "{$datosVerificadosResumen}\n\nAquí están las conversaciones (" . count($filas) . " en total, mostrando el primer mensaje de cada una):\n\n{$transcript}"]],
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
        . " | [resumen_ejecutivo] status=$status | curl=$curlError | body=" . (string)$raw . "\n", FILE_APPEND);
    fail('Falló la llamada a la IA. Revisa ia_debug.log.', 502);
}

$data = json_decode($raw, true);
$texto = '';
foreach (($data['content'] ?? []) as $bloque) {
    if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
}
$texto = trim($texto);
if ($texto === '') fail('La IA no devolvió texto.', 502);

// Nota de corrección puesta por CÓDIGO, no por instrucción a la IA --
// se detectó en producción que, pese a darle datos verificados y hechos
// del código en el prompt, el modelo seguía repitiendo "se descartan
// leads fuera de CDMX/Edomex" y "cursos sin explotar" (una instrucción
// en el prompt no es una regla 100% dura para un modelo probabilístico).
// Esto garantiza que la corrección real SIEMPRE quede guardada junto
// con el reporte, sin importar lo que haya escrito la IA arriba.
$texto .= "\n\n---\nNOTA (agregada siempre por el sistema, no por la IA -- verificado en el código): si el "
    . "reporte de arriba dice que se \"descartan\", \"pierden\" o \"filtran\" leads por estar fuera de "
    . "CDMX/Edomex, eso NO es cierto -- el bot ofrece la asesoría de pago igual sin importar el estado. Y si "
    . "dice que el interés en cursos \"no se explota\" o \"se desperdicia\", tampoco es cierto -- el bot ya los "
    . "ofrece activamente y tiene un recordatorio automático para quien no compró. Ignora esas dos "
    . "afirmaciones si aparecen arriba.";

$stmt = $pdo->prepare(
    'INSERT INTO resumenes_ejecutivos (contenido, num_conversaciones, periodo_desde, periodo_hasta, generado_por) VALUES (:c, :n, :pd, :ph, :u)'
);
$stmt->execute([
    ':c' => $texto,
    ':n' => count($filas),
    ':pd' => $tienePeriodo ? $desde : null,
    ':ph' => $tienePeriodo ? $hasta : null,
    ':u' => $user['id'],
]);

respond([
    'id' => (int)$pdo->lastInsertId(),
    'contenido' => $texto,
    'num_conversaciones' => count($filas),
    'periodo_desde' => $tienePeriodo ? $desde : null,
    'periodo_hasta' => $tienePeriodo ? $hasta : null,
    'creado_en' => date('Y-m-d H:i:s'),
], 201);
