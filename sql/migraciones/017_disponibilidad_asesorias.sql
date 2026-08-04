-- Horarios semanales recurrentes en los que cada abogado puede atender
-- asesorías telefónicas de pago. Cada fila es un bloque de un día de la
-- semana (dia_semana: 1=lunes ... 7=domingo, ISO-8601) con hora de inicio
-- y fin. Un abogado puede tener varios bloques (incluso varios en el mismo
-- día). Es la base para poder ofrecer horarios reales de agendado desde el
-- bot de WhatsApp más adelante.
CREATE TABLE disponibilidad_asesorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  dia_semana TINYINT UNSIGNED NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  KEY idx_disponibilidad_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
