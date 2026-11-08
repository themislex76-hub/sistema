-- Horario de entrada/salida (incluyendo tiempo extra) capturado por
-- expediente, para que la demanda calcule sola las horas extra diarias y
-- semanales y adecúe el texto de jornada laboral (ver calcularJornada() y
-- mergeTags() en app.js). comida_inicio/comida_fin son opcionales -- si no
-- se capturan, el texto generado queda genérico ("una hora para tomar
-- alimentos", sin horario específico), pero para el cálculo SIEMPRE se
-- resta 1 hora de comida.
ALTER TABLE expedientes
  ADD COLUMN hora_entrada TIME NULL,
  ADD COLUMN hora_salida TIME NULL,
  ADD COLUMN comida_inicio TIME NULL,
  ADD COLUMN comida_fin TIME NULL,
  ADD COLUMN dias_laborados VARCHAR(100) NULL,
  ADD COLUMN dias_laborados_num TINYINT UNSIGNED NULL,
  ADD COLUMN tipo_jornada VARCHAR(20) NULL;

-- Jornada máxima legal (Art. 61 LFT) por tipo, en horas -- editable en
-- "Equipo" (Administrador), igual que salario_minimo_diario, para cuando
-- cambie la ley (se prevé una reducción de jornada a partir de 2027) sin
-- tener que tocar código.
INSERT INTO configuracion (clave, valor) VALUES
  ('jornada_maxima_diurna', '8'),
  ('jornada_maxima_nocturna', '7'),
  ('jornada_maxima_mixta', '7.5')
ON DUPLICATE KEY UPDATE valor = valor;
