-- Estadísticas diarias de los lives de TikTok, capturadas a mano por el
-- Administrador desde "Embudo del negocio" (TikTok no da estas cifras por
-- API). Son los mismos datos que muestra TikTok Live Center → Estadísticas
-- al pasar el cursor sobre cada día. Una fila por día (upsert por fecha).
-- Es la 1ª etapa del embudo: espectadores del live → conversaciones nuevas
-- de WhatsApp → pagaron asesoría → se volvieron juicio.
CREATE TABLE tiktok_lives (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  visualizaciones INT UNSIGNED NOT NULL DEFAULT 0,
  espectadores_unicos INT UNSIGNED NOT NULL DEFAULT 0,
  espectadores_activos INT UNSIGNED NOT NULL DEFAULT 0,
  duracion_media_min DECIMAL(6,2) NULL,
  pico_simultaneos INT UNSIGNED NULL,
  media_simultaneos INT UNSIGNED NULL,
  duracion_live_min INT UNSIGNED NULL,
  notas VARCHAR(255) NULL,
  actualizado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tiktok_lives_fecha (fecha),
  FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
