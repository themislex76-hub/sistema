-- ----------------------------------------------------------------------------
-- tribunales_custom: catálogo nacional de tribunales laborales que se va
-- completando con lo que el despacho escribe en "Otro (especificar)" del
-- selector de Tribunal — se guarda una sola vez por (estado, nombre) y a
-- partir de ahí aparece como opción normal del selector para todos, en vez
-- de tener que volver a escribirlo cada vez. Ver EDITABLE_FIELDS ('junta' y
-- 'tribunal') en assets/app.js y api/tribunales_custom_*.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tribunales_custom (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estado VARCHAR(60) NOT NULL,
  nombre VARCHAR(300) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tribunal_custom (estado, nombre(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
