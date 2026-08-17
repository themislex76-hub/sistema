// Copia este archivo como config.js (misma carpeta) y llena los datos
// reales. config.js NUNCA debe subirse a git (ya está en .gitignore) —
// solo vive en la computadora donde corre el robot. No lo compartas ni lo
// pegues en ningún chat.

module.exports = {
  sistema: {
    // URL base de la API del sistema, sin diagonal al final.
    apiBase: 'https://sistema.expertoslaborales.com/api',
    // Misma llave que ROBOT_API_KEY en api/robot_credentials.php del
    // servidor (Federal y CDMX usan la llave compartida).
    robotKey: 'CAMBIA_ESTO_POR_LA_MISMA_LLAVE_DE_robot_credentials.php',
  },
};
