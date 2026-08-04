-- Resumen (generado por IA, bajo demanda) de una conversación completa de
-- WhatsApp, para que el abogado no tenga que leer todo el hilo para saber
-- de qué se trató y cómo respondió el bot. Se genera/actualiza a mano
-- desde la vista "Conversaciones (WhatsApp)" — ver api/conversaciones_resumen.php.
CREATE TABLE whatsapp_resumenes (
  telefono VARCHAR(30) NOT NULL PRIMARY KEY,
  resumen TEXT NOT NULL,
  mensajes_contados INT UNSIGNED NOT NULL,
  generado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
