-- Guarda cada resumen ejecutivo generado (ver api/resumen_ejecutivo.php) para
-- llevar historial y poder comparar cómo van cambiando los temas/patrones a
-- lo largo del tiempo, en vez de tener que regenerarlo siempre desde cero.
CREATE TABLE resumenes_ejecutivos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contenido MEDIUMTEXT NOT NULL,
  num_conversaciones INT UNSIGNED NOT NULL,
  generado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resumen_ejecutivo_usuario FOREIGN KEY (generado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
