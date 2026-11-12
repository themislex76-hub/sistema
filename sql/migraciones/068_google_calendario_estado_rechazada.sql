ALTER TABLE google_calendario_sugerencias
  MODIFY estado ENUM('pendiente','aplicada','descartada','sin_match','rechazada') NOT NULL DEFAULT 'pendiente';
