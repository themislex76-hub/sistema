<?php
/**
 * Copia este archivo como whatsapp_credentials.php (mismo directorio) y
 * llena tus datos reales de la app de Meta / WhatsApp Cloud API.
 * whatsapp_credentials.php NUNCA debe subirse a git (ya está en
 * .gitignore) — solo vive en el servidor.
 *
 * Ver docs/DEPLOY_CPANEL.md, sección "WhatsApp con IA", para los pasos
 * exactos de dónde sacar estos datos en developers.facebook.com.
 */

// Token de acceso permanente (system user token) de tu app de Meta.
define('WHATSAPP_TOKEN', 'CAMBIA_ESTO_POR_TU_TOKEN_DE_ACCESO');

// El "Phone number ID" (no el número en sí) que asigna Meta al número
// dedicado del bot, visible en developers.facebook.com dentro de tu app.
define('WHATSAPP_PHONE_ID', 'CAMBIA_ESTO_POR_TU_PHONE_NUMBER_ID');

// Cadena que tú inventas y pegas también en el campo "Verify token" al
// configurar el webhook en developers.facebook.com — confirma que la
// petición de verificación viene de Meta.
define('WHATSAPP_VERIFY_TOKEN', 'CAMBIA_ESTO_POR_UNA_CADENA_LARGA_Y_UNICA');

// "App secret" de tu app de Meta (Configuración básica de la app). Se usa
// para verificar la firma X-Hub-Signature-256 de cada webhook entrante y
// así rechazar mensajes falsificados que no vengan realmente de Meta.
define('WHATSAPP_APP_SECRET', 'CAMBIA_ESTO_POR_EL_APP_SECRET_DE_TU_APP_META');
