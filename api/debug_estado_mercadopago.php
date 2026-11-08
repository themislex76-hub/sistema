<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Herramienta temporal: diagnóstico directo de si mercadopago_credentials.php
// quedó bien -- hace una llamada real de SOLO LECTURA (/users/me, consulta
// los datos de la cuenta dueña del token) para confirmar que el Access
// Token funciona, sin crear ningún cobro ni preferencia. Solo
// Administrador. Se puede borrar cuando ya no haga falta.
require_admin();
header('Content-Type: text/plain; charset=utf-8');

$credentialsFile = __DIR__ . '/mercadopago_credentials.php';
echo "1) Archivo mercadopago_credentials.php existe: " . (file_exists($credentialsFile) ? 'SÍ' : 'NO — este es el problema, hay que volver a subirlo') . "\n";
if (!file_exists($credentialsFile)) { echo "\nNo se puede seguir sin ese archivo.\n"; exit; }
require_once $credentialsFile;

echo "2) Constante MERCADOPAGO_ACCESS_TOKEN definida: " . (defined('MERCADOPAGO_ACCESS_TOKEN') ? 'SÍ' : 'NO') . "\n";
if (defined('MERCADOPAGO_ACCESS_TOKEN')) {
    $t = MERCADOPAGO_ACCESS_TOKEN;
    echo "   Empieza con: " . substr($t, 0, 12) . "... (largo: " . strlen($t) . " caracteres)\n";
}

echo "\n3) Consultando (solo lectura) los datos de la cuenta en la API de Mercado Pago...\n";
$ch = curl_init('https://api.mercadopago.com/users/me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MERCADOPAGO_ACCESS_TOKEN],
    CURLOPT_TIMEOUT => 20,
]);
$respuesta = curl_exec($ch);
$codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($ch);
curl_close($ch);

if ($errorCurl) {
    echo "FALLÓ la conexión: $errorCurl\n";
} elseif ($codigo === 200) {
    $datos = json_decode($respuesta, true);
    // /users/me no trae un campo confiable de "modo prueba/producción" --
    // mejor mostrar el correo e id reales de la cuenta: si es tu correo de
    // verdad (no un "test_user_XXXXX@testuser.com" generado por Mercado
    // Pago), es tu cuenta real y el token sirve para cobrar de verdad.
    echo "ÉXITO (HTTP 200). El Access Token funciona.\n"
        . "Cuenta: " . ($datos['email'] ?? '?') . " (id: " . ($datos['id'] ?? '?') . ")\n"
        . "Si ese correo es el real del negocio (no un \"test_user_...@testuser.com\"), es tu cuenta de producción.\n";
} else {
    echo "FALLÓ (HTTP $codigo). Respuesta de Mercado Pago:\n$respuesta\n";
}
