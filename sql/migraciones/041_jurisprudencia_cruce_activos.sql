-- Cruce automático de jurisprudencia nueva contra expedientes activos (ver
-- api/jurisprudencia_cruce_helpers.php, llamado desde jurisprudencia_ingest.php).

CREATE TABLE jurisprudencia_expediente_match (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  registro_digital INT NOT NULL,
  interpretacion TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_jurisprudencia_match (expediente_id, registro_digital),
  CONSTRAINT fk_jur_match_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
