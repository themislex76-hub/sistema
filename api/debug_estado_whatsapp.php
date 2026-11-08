<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: diagnóstico directo de si whatsapp_credentials.php
// quedó bien -- hace una llamada real de SOLO LECTURA (consulta los datos
// del propio número, no manda ningún mensaje) para confirmar que el token
// y el Phone ID funcionan. Solo Administrador. Se puede borrar cuando ya
// no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$credentialsFile = __DIR__ . '/whatsapp_credentials.php';
echo "1) Archivo whatsapp_credentials.php existe: " . (file_exists($credentialsFile) ? 'SÍ' : 'NO — este es el problema, hay que volver a subirlo') . "\n";
if (!file_exists($credentialsFile)) { echo "\nNo se puede seguir sin ese archivo.\n"; exit; }
require_once $credentialsFile;

foreach (['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_ID', 'WHATSAPP_VERIFY_TOKEN', 'WHATSAPP_APP_SECRET'] as $c) {
    echo "2) Constante $c definida: " . (defined($c) ? 'SÍ' : 'NO') . "\n";
}

echo "\n3) Consultando (solo lectura) los datos del número en la API de WhatsApp...\n";
$url = 'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID
    . '?fields=display_phone_number,verified_name,quality_rating';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
    CURLOPT_TIMEOUT => 20,
]);
$respuesta = curl_exec($ch);
$codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($ch);
curl_close($ch);

if ($errorCurl) {
    echo "FALLÓ la conexión: $errorCurl\n";
} elseif ($codigo === 200) {
    echo "ÉXITO (HTTP 200). El token y el Phone ID funcionan:\n$respuesta\n";
} else {
    echo "FALLÓ (HTTP $codigo). Respuesta de Meta:\n$respuesta\n";
}
