-- ============================================================================
-- Migración 003: calendario de días inhábiles para el cómputo de términos.
--
-- Hasta ahora, todo cálculo de "días hábiles" en el sistema (etapas
-- procesales, amparo, pendientes) solo excluía sábados y domingos. Esta
-- migración agrega una tabla editable de días inhábiles adicionales
-- (descansos obligatorios de Art. 74 LFT + los que cada tribunal declare)
-- para que esos cálculos sean más precisos.
--
-- Se precargan los descansos obligatorios de Art. 74 LFT, fracciones I-VI y
-- VIII, para 2026 y 2027 (fechas ciertas por ley). Los recesos específicos de
-- cada tribunal (Semana Santa, fin de año, "puentes") NO se precargan porque
-- varían por tribunal y año — deben agregarse a mano desde la pantalla
-- "Días inhábiles" en cuanto el despacho confirme el calendario oficial de
-- cada uno con el tribunal correspondiente.
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

CREATE TABLE IF NOT EXISTS dias_inhabiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  descripcion VARCHAR(190) NOT NULL,
  ambito ENUM('federal','cdmx','edomex','todos') NOT NULL DEFAULT 'todos',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dias_inhabiles (fecha, ambito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dias_inhabiles (fecha, descripcion, ambito) VALUES
('2026-01-01', 'Año nuevo (Art. 74-I LFT)', 'federal'),
('2026-02-02', 'Aniversario de la Constitución, 5 de febrero (Art. 74-II LFT)', 'federal'),
('2026-03-16', 'Natalicio de Benito Juárez, 21 de marzo (Art. 74-III LFT)', 'federal'),
('2026-05-01', 'Día del Trabajo (Art. 74-IV LFT)', 'federal'),
('2026-09-16', 'Día de la Independencia (Art. 74-V LFT)', 'federal'),
('2026-11-16', 'Aniversario de la Revolución, 20 de noviembre (Art. 74-VI LFT)', 'federal'),
('2026-12-25', 'Navidad (Art. 74-VIII LFT)', 'federal'),
('2027-01-01', 'Año nuevo (Art. 74-I LFT)', 'federal'),
('2027-02-01', 'Aniversario de la Constitución, 5 de febrero (Art. 74-II LFT)', 'federal'),
('2027-03-15', 'Natalicio de Benito Juárez, 21 de marzo (Art. 74-III LFT)', 'federal'),
('2027-05-01', 'Día del Trabajo (Art. 74-IV LFT)', 'federal'),
('2027-09-16', 'Día de la Independencia (Art. 74-V LFT)', 'federal'),
('2027-11-15', 'Aniversario de la Revolución, 20 de noviembre (Art. 74-VI LFT)', 'federal'),
('2027-12-25', 'Navidad (Art. 74-VIII LFT)', 'federal');
