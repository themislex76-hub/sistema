<?php
/**
 * Copia este archivo como robot_credentials.php (mismo directorio) y pon
 * una llave larga y aleatoria — es la contraseña que usan los robots de
 * monitoreo de boletines (Federal, CDMX, Edomex) para hablarle a este
 * sistema sin usar una sesión de usuario. robot_credentials.php NUNCA debe
 * subirse a git (ya está en .gitignore) — solo vive en el servidor y en la
 * configuración de los robots. No lo compartas ni lo pegues en ningún chat.
 *
 * Genera una llave segura, por ejemplo con este comando en la terminal:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */

define('ROBOT_API_KEY', 'CAMBIA_ESTO_POR_UNA_LLAVE_LARGA_Y_ALEATORIA');
