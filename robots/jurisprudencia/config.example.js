// Copia este archivo como config.js (misma carpeta) y llena los datos
// reales. config.js NUNCA debe subirse a git (ya está en .gitignore) —
// solo vive en la computadora donde corre el robot. No lo compartas ni
// lo pegues en ningún chat.

module.exports = {
  sistema: {
    // URL base de la API del sistema, sin diagonal al final.
    apiBase: 'https://sistema.expertoslaborales.com/api',
    // Misma llave que ROBOT_API_KEY en api/robot_credentials.php del
    // servidor (Federal y CDMX usan la llave compartida).
    robotKey: 'CAMBIA_ESTO_POR_LA_MISMA_LLAVE_DE_robot_credentials.php',
  },
  // Opcional: si está configurado, cada lote de tesis nuevas que se manda
  // al sistema original TAMBIÉN se manda al multidespacho (control de
  // expedientes), para que su biblioteca de jurisprudencia se mantenga al
  // día sola, sin exportar/importar la tabla a mano. Si se deja sin
  // configurar (o se borra este bloque), el robot sigue funcionando igual
  // que antes, solo que no sincroniza a ningún lado más.
  multidespacho: {
    // URL base de la API del multidespacho, sin diagonal al final.
    apiBase: 'https://controldeexpedientes.mx/api',
    // Misma llave que JURISPRUDENCIA_SYNC_KEY en
    // api/jurisprudencia_sync_credentials.php del multidespacho.
    syncKey: 'CAMBIA_ESTO_POR_LA_MISMA_LLAVE_DE_jurisprudencia_sync_credentials.php',
  },
};
