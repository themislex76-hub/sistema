<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: le pregunta DIRECTO a Meta cómo tiene guardada
// cada plantilla (nombre, idioma exacto, estado) de la cuenta de
// WhatsApp Business dueña del número del bot -- para diagnosticar el
// error 132001 ("template name does not exist in es_MX") sin tener que
// ir cazando el WABA ID a mano en la interfaz de Meta. Usa el mismo
// WHATSAPP_PHONE_ID de siempre para encontrar la cuenta dueña. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$credentialsFile = __DIR__ . '/whatsapp_credentials.php';
if (!file_exists($credentialsFile)) {
    echo "Falta api/whatsapp_credentials.php.\n";
    exit;
}
require_once $credentialsFile;

function llamar_graph(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $raw];
}

// Paso 1: confirmar que el token/PHONE_ID sí funcionan (con campos que sí
// existen en este nodo -- "whatsapp_business_account" no es un campo válido
// aquí, así que se dejó fuera).
[$status1, $raw1] = llamar_graph(
    'https://graph.facebook.com/v23.0/' . WHATSAPP_PHONE_ID . '?fields=id,display_phone_number,verified_name'
);
echo "=== Paso 1: datos del número (PHONE_ID={" . WHATSAPP_PHONE_ID . "}) ===\n";
echo "status=$status1\n$raw1\n\n";

// Paso 1b: el business_id que se ve en la URL del Administrador de
// WhatsApp (916653940845141) es el ID de la EMPRESA en Meta Business
// Manager -- de ahí se puede listar qué cuentas de WhatsApp Business
// (WABA) son dueñas de esa empresa, y sacar el ID real de cada una.
$businessId = '916653940845141';
[$status1b, $raw1b] = llamar_graph(
    "https://graph.facebook.com/v23.0/{$businessId}/owned_whatsapp_business_accounts?fields=id,name"
);
echo "=== Paso 1b: WABAs de la empresa (business_id={$businessId}) ===\n";
echo "status=$status1b\n$raw1b\n\n";

$data1b = json_decode((string)$raw1b, true);
$wabaId = $data1b['data'][0]['id'] ?? null;

if (!$wabaId) {
    echo "No se pudo sacar el WABA ID de esa respuesta -- copia todo este texto y mándamelo, ahí seguimos.\n";
    exit;
}

// Paso 2: con el WABA ID, listar las plantillas y cómo las tiene
// guardadas Meta exactamente (nombre, idioma, estado).
[$status2, $raw2] = llamar_graph(
    "https://graph.facebook.com/v23.0/{$wabaId}/message_templates?fields=name,language,status,category&limit=50"
);
echo "=== Paso 2: plantillas de la cuenta (WABA_ID={$wabaId}) ===\n";
echo "status=$status2\n$raw2\n";
