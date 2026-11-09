-- Hasta ahora un "posible atraso" o una "acción sugerida por IA" se
-- calculaba bien (se veía en el Tablero/Agenda general) pero NUNCA se
-- empujaba por notificación push: syncAvisosAbogado() en app.js solo
-- mandaba al caché lo que tuviera fecha entre hoy y los próximos 3 días,
-- y la fecha de un atraso es la fecha en la que YA DEBIÓ pasar algo --
-- una vez atrasado de verdad esa fecha queda en el pasado para siempre,
-- así que nunca volvía a caer en esa ventana. El cron diario
-- (cron_recordatorio_abogado.php) tampoco podría haberlo agarrado aunque
-- llegara al caché: su consulta exige fecha = CURDATE() + 1, y la fecha
-- guardada de un atraso no avanza día a día. Resultado real confirmado:
-- 40 asuntos atorados que el sistema "sabía" pero nunca avisó a nadie.
--
-- dias_atraso permite además escalar al Administrador cuando un abogado
-- lleva varios días ignorando el aviso (ver cron_recordatorio_abogado.php).
ALTER TABLE agenda_avisos_cache
  ADD COLUMN dias_atraso SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER label;

CREATE TABLE agenda_escalaciones_enviadas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  enviado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_escalaciones_enviadas (admin_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
