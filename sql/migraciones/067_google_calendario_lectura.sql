-- Google Calendar → sistema (11 nov 2026, pedido explícito del usuario):
-- cada abogado agenda sus audiencias/citatorios directo en su propio
-- sub-calendario de Google (ej. "Audiencias Lindsay"), todos visibles
-- desde UNA sola cuenta de Google (la del Administrador, que ya la
-- conectó antes para el sync de salida que ya existía). Esto agrega la
-- lectura en sentido contrario: un cron revisa esos calendarios y
-- sugiere a qué expediente/etapa corresponde cada evento -- nunca lo
-- aplica solo (texto libre, puede haber tocayos/errores de dedo), el
-- abogado confirma con un tap.

-- Qué sub-calendario de Google corresponde a qué abogado. usuario_lector_id
-- es quién tiene la cuenta de Google conectada con visibilidad sobre ESTE
-- calendario (hoy, siempre el mismo Administrador que los vio todos en
-- "Mis calendarios", pero se guarda explícito por si cambia más adelante).
CREATE TABLE google_calendarios_vigilados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendar_id VARCHAR(255) NOT NULL,
  nombre_calendario VARCHAR(255) NOT NULL,
  abogado_id INT UNSIGNED NOT NULL,
  usuario_lector_id INT UNSIGNED NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_calendar_id (calendar_id),
  CONSTRAINT fk_gcv_abogado FOREIGN KEY (abogado_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_gcv_lector FOREIGN KEY (usuario_lector_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un renglón por evento de Google ya revisado -- la clave única
-- (calendar_id, google_event_id) es el candado contra duplicados: si el
-- cron vuelve a ver el mismo evento en la siguiente corrida, ya no se
-- vuelve a procesar ni a sugerir de nuevo.
CREATE TABLE google_calendario_sugerencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendar_id VARCHAR(255) NOT NULL,
  google_event_id VARCHAR(255) NOT NULL,
  expediente_id INT UNSIGNED NULL,
  etapa_key VARCHAR(40) NULL,
  fecha DATE NOT NULL,
  hora TIME NULL,
  titulo_evento VARCHAR(500) NOT NULL,
  estado ENUM('pendiente','aplicada','descartada','sin_match') NOT NULL DEFAULT 'pendiente',
  google_updated DATETIME NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en DATETIME NULL,
  UNIQUE KEY uq_evento (calendar_id, google_event_id),
  KEY idx_estado (estado),
  CONSTRAINT fk_gcs_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
