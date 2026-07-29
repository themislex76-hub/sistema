-- ============================================================================
-- Migración 008: prima vacacional pactada + prestaciones adicionales
-- manuales en el cálculo de liquidación.
--
--   - prima_vacacional_pct_pactada: si el contrato pacta una prima
--     vacacional mayor al mínimo de ley (25%, Art. 80 LFT), aquí se captura
--     el porcentaje real desde "Editar datos". Vacío = se usa el 25%.
--   - expediente_prestaciones_extra: lista de prestaciones adicionales que
--     no forman parte del cálculo estándar de ley (p.ej. fondo de ahorro
--     pactado por contrato) — concepto libre + monto, capturados a mano
--     desde "Cálculo de liquidación" y sumados al total.
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos
-- existentes.
-- ============================================================================

ALTER TABLE expedientes
  ADD COLUMN prima_vacacional_pct_pactada DECIMAL(5,2) NULL AFTER dias_vacaciones_anteriores_reclamados;

CREATE TABLE expediente_prestaciones_extra (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  concepto VARCHAR(150) NOT NULL,
  monto DECIMAL(12,2) NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_prestaciones_extra_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
