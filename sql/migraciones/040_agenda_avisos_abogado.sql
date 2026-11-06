-- Aviso proactivo al abogado (no solo al cliente) un día antes de una
-- audiencia, pago programado, vencimiento de prescripción/amparo o
-- pendiente/atraso. El navegador ya calcula todas esas fechas
-- correctamente (buildAgendaEntries() en assets/app.js, con toda la
-- lógica de días hábiles/inhábiles y suspensión por conciliación) — en
-- vez de reimplementar ese cálculo en PHP (riesgo real de que la copia se
-- desalinee del original, sobre todo en algo tan sensible como la
-- prescripción), el navegador manda una copia de los próximos 3 días a
-- este caché cada vez que alguien abre el sistema, y el cron diario
-- (cron_recordatorio_abogado.php) solo lee de aquí.

CREATE TABLE agenda_avisos_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  -- Identifica el elemento dentro del expediente (p.ej. "audiencia",
  -- "pago:0", "pendiente:2") para poder actualizar/borrar el mismo
  -- renglón en vez de acumular duplicados con cada sincronización.
  clave VARCHAR(40) NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  fecha DATE NOT NULL,
  hora TIME NULL,
  label VARCHAR(255) NOT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_avisos_cache (expediente_id, clave),
  CONSTRAINT fk_avisos_cache_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evita mandar el mismo aviso dos veces al mismo abogado el mismo día si el
-- cron llegara a correr más de una vez (o se reintenta a mano).
CREATE TABLE agenda_avisos_enviados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  abogado_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  enviado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_avisos_enviados (abogado_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
