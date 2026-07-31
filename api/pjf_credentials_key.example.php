<?php
/**
 * Copia este archivo como pjf_credentials_key.php (mismo directorio) y pon
 * una llave larga y aleatoria — se usa para cifrar/descifrar las
 * contraseñas del Portal Federal (PJF) que cada abogado guarda desde su
 * perfil. pjf_credentials_key.php NUNCA debe subirse a git (ya está en
 * .gitignore) — solo vive en el servidor. Si esta llave se pierde o
 * cambia, las contraseñas ya guardadas quedan ilegibles y cada abogado
 * tendría que volver a capturarlas.
 *
 * Genera una llave segura, por ejemplo con este comando en la terminal:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */

define('PJF_CRED_ENC_KEY', 'CAMBIA_ESTO_POR_UNA_LLAVE_LARGA_Y_ALEATORIA');
