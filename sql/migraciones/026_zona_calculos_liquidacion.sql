-- Agrega el registro de ubicación/zona a cada cálculo de liquidación, para
-- medir cuántos usuarios de la calculadora son de fuera de la zona de
-- cobertura (candidatos a un futuro cobro pequeño por el cálculo) antes de
-- construir el cobro de verdad. Ver ia_clasificar_zona_cobertura() en
-- ia_helpers.php.
ALTER TABLE calculos_liquidacion
  ADD COLUMN ubicacion_texto VARCHAR(255) NULL AFTER monto_total,
  ADD COLUMN zona ENUM('dentro', 'fuera', 'desconocido') NOT NULL DEFAULT 'desconocido' AFTER ubicacion_texto;
