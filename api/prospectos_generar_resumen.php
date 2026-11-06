<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Botón "Generar resumen" del modal de Prospectos -- útil sobre todo para
// las asesorías pagadas, cuyo "Resumen del caso" solo se llena con un
// texto genérico de pago/horario al momento de confirmarse (ver
// mercadopago_webhook.php), sin nada del caso real. Lee la conversación
// completa de WhatsApp y le pide a Claude un resumen breve y real -- nunca
// inventa nada que no esté en los mensajes.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);
$user = require_login();
require_csrf();

$in = json_input();
$id = (int)($in['id'] ?? 0);
if ($id <= 0) fail('Falta el id del prospecto.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, telefono, asignado_a FROM prospectos WHERE id = :id');
$stmt->execute([':id' => $id]);
$prospecto = $stmt->fetch();
if (!$prospecto) fail('Prospecto no encontrado.', 404);

$esAdmin = $user['rol'] === 'administrador';
if (!$esAdmin && (int)$prospecto['asignado_a'] !== (int)$user['id']) {
    fail('No tienes acceso a este prospecto.', 403);
}

$stmt = $pdo->prepare(
    "SELECT direccion, texto FROM whatsapp_conversaciones WHERE telefono = :t ORDER BY creado_en ASC LIMIT 60"
);
$stmt->execute([':t' => $prospecto['telefono']]);
$mensajes = $stmt->fetchAll();
if (!$mensajes) fail('No hay conversación de WhatsApp con este número todavía.', 400);

$credentialsFile = __DIR__ . '/anthropic_credentials.php';
if (!file_exists($credentialsFile)) fail('Falta anthropic_credentials.php.', 500);
require_once $credentialsFile;

$transcripcion = implode("\n", array_map(
    fn($m) => ($m['direccion'] === 'entrante' ? 'Cliente: ' : 'Despacho: ') . $m['texto'],
    $mensajes
));

$payload = [
    'model' => 'claude-haiku-4-5-20251001',
    'max_tokens' => 200,
    'thinking' => ['type' => 'disabled'],
    'system' => 'Te doy la transcripción completa de una conversación de WhatsApp entre un cliente y el asistente '
        . 'de un despacho de derecho laboral mexicano. Tu única tarea es escribir un resumen de 1-3 líneas, en '
        . 'español, de cuál es el caso o duda real del cliente -- para que un abogado que nunca leyó la '
        . 'conversación sepa de qué le va a hablar. Solo usa datos que de verdad estén en la conversación, nunca '
        . 'inventes ni asumas nada. Si el cliente solo agendó/pagó sin contar su caso, dilo así explícitamente '
        . '("No contó los detalles de su caso antes de pagar"). No agregues introducciones ni cierres, solo el '
        . 'resumen directo.',
    'messages' => [['role' => 'user', 'content' => $transcripcion]],
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
curl_close($ch);
if ($raw === false || $status !== 200) fail('No se pudo generar el resumen (falló la IA).', 502);

$data = json_decode($raw, true);
$texto = '';
foreach (($data['content'] ?? []) as $bloque) {
    if (($bloque['type'] ?? '') === 'text') $texto .= $bloque['text'];
}
$texto = trim($texto);
if ($texto === '') fail('La IA no devolvió texto.', 502);

$pdo->prepare('UPDATE prospectos SET resumen_caso = :r WHERE id = :id')->execute([':r' => $texto, ':id' => $id]);

respond(['resumen_caso' => $texto]);
