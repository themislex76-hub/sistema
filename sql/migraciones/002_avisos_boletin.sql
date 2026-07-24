-- ============================================================================
-- Migración 002 — avisos_boletin
-- Para instalaciones que YA tienen datos (no reimportar schema.sql completo).
-- Cómo aplicar: phpMyAdmin → tu base de datos → pestaña "SQL" → pega este
-- archivo completo → Continuar.
-- ============================================================================

CREATE TABLE IF NOT EXISTS avisos_boletin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  fuente ENUM('cdmx_local','edomex_local','federal_laboral','federal_amparo','otro') NOT NULL DEFAULT 'otro',
  origen ENUM('automatico','manual') NOT NULL DEFAULT 'manual',
  fecha_publicacion DATE NULL,
  resumen TEXT NOT NULL,
  url_verificacion VARCHAR(500) NULL,
  estado ENUM('nuevo','revisado','descartado') NOT NULL DEFAULT 'nuevo',
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revisado_por INT UNSIGNED NULL,
  revisado_en DATETIME NULL,
  KEY idx_avisos_expediente (expediente_id),
  KEY idx_avisos_estado (estado),
  CONSTRAINT fk_avisos_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_avisos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_avisos_revisado_por FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
