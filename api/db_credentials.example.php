<?php
/**
 * Copia este archivo como db_credentials.php (mismo directorio) y llena tus
 * datos reales de la base de datos de cPanel. db_credentials.php NUNCA debe
 * subirse a git (ya está en .gitignore) — solo vive en el servidor.
 *
 * Ver docs/DEPLOY_CPANEL.md para los pasos exactos de dónde sacar estos datos.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'usuariocp_expedientes');   // nombre real de tu base de datos en cPanel
define('DB_USER', 'usuariocp_expedientes');   // usuario MySQL de cPanel
define('DB_PASS', 'CAMBIA_ESTA_CONTRASENA');  // contraseña que le pusiste al usuario MySQL

// Clave secreta única para esta instalación (usada para el token CSRF de sesión).
// Genera una cadena aleatoria larga y pégala aquí — no la compartas ni la subas a git.
define('APP_SECRET', 'CAMBIA_ESTO_POR_UNA_CADENA_ALEATORIA_LARGA_Y_UNICA');
