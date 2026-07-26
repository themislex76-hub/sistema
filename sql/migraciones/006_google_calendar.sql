-- ============================================================================
-- Migración 006: sincronización de un solo sentido con Google Calendar.
--
-- Cada socio conecta su propia cuenta de Google (OAuth); el sistema guarda
-- únicamente el "refresh token" (una credencial de larga duración que Google
-- entrega para renovar el acceso sin volver a pedir contraseña) — nunca la
-- contraseña de Google del usuario. Con eso, el sistema puede crear/actualizar
-- eventos de todo el día en el calendario de esa persona: audiencias, pagos
-- de convenio, vencimientos de prescripción y de amparo. Nunca al revés — un
-- cambio hecho directo en Google Calendar no se refleja en el sistema.
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

ALTER TABLE usuarios
  ADD COLUMN google_refresh_token TEXT NULL AFTER bloqueado_hasta,
  ADD COLUMN google_calendar_email VARCHAR(190) NULL AFTER google_refresh_token,
  ADD COLUMN google_conectado_en DATETIME NULL AFTER google_calendar_email;

CREATE TABLE IF NOT EXISTS google_calendar_sync (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  evento_id VARCHAR(64) NOT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_google_sync (usuario_id, evento_id),
  CONSTRAINT fk_google_sync_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
