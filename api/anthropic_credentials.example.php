<?php
/**
 * Copia este archivo como anthropic_credentials.php (mismo directorio) y
 * pon tu API key real de console.anthropic.com. anthropic_credentials.php
 * NUNCA debe subirse a git (ya está en .gitignore) — solo vive en el
 * servidor.
 */

define('ANTHROPIC_API_KEY', 'CAMBIA_ESTO_POR_TU_API_KEY_DE_ANTHROPIC');

// Opcional -- solo hace falta para el panel "Costos vs. Ingresos" (para que
// el gasto de IA se sume solo en vez de capturarlo a mano cada mes). Es una
// llave DISTINTA a la de arriba: se genera en console.anthropic.com →
// Settings → Admin keys (empieza con "sk-ant-admin01-..."). Solo lee el
// gasto/costo de la organización, no puede usarse para mandar mensajes al
// bot. Si no la configuras, el panel simplemente no muestra la sugerencia
// automática y sigues capturando el costo de IA a mano, sin ningún error.
// define('ANTHROPIC_ADMIN_API_KEY', 'CAMBIA_ESTO_POR_TU_ADMIN_API_KEY_DE_ANTHROPIC');
