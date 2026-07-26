<?php
/**
 * Copia este archivo como google_credentials.php (mismo directorio) y llena
 * los datos reales que te dio Google Cloud Console al crear el cliente de
 * OAuth. google_credentials.php NUNCA debe subirse a git (ya está en
 * .gitignore) — solo vive en el servidor. No lo compartas ni lo pegues en
 * ningún chat.
 */

define('GOOGLE_CLIENT_ID', 'CAMBIA_ESTO_POR_TU_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'CAMBIA_ESTO_POR_TU_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', 'https://sistema.expertoslaborales.com/sistema/api/google_oauth_callback.php');
