-- ============================================================================
-- Migración 007: datos manuales para completar el cálculo de liquidación.
--
-- La mayoría de los conceptos de liquidación (indemnización, prima de
-- antigüedad, vacaciones proporcionales, prima vacacional, aguinaldo
-- proporcional) ya se calculan solos a partir de fecha de ingreso, fecha de
-- baja y salario (ver computeLiquidacion() en assets/app.js). Estas 4
-- columnas cubren lo que SÍ hay que capturar a mano porque no se puede
-- derivar de esos datos:
--
--   - aguinaldo_dias_pactados: si el contrato da más de los 15 días mínimos
--     de ley (Art. 87), aquí se captura el número real.
--   - dias_salarios_devengados / fecha_desde_salarios_devengados: días ya
--     trabajados y no pagados antes de la baja (Art. 82), y desde cuándo
--     (para avisar si parte podría estar prescrita, Art. 516).
--   - dias_vacaciones_anteriores_reclamados: días de vacaciones de años
--     previos que el cliente reclama — el sistema los topa automáticamente
--     al máximo que no ha prescrito (Art. 516, criterio SCJN 2a./J. 1/97).
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos
-- existentes; las columnas nuevas quedan en NULL en todos los expedientes
-- ya capturados (el sistema las trata como "no aplica").
-- ============================================================================

ALTER TABLE expedientes
  ADD COLUMN aguinaldo_dias_pactados DECIMAL(6,2) NULL AFTER dom_testigos,
  ADD COLUMN dias_salarios_devengados SMALLINT UNSIGNED NULL AFTER aguinaldo_dias_pactados,
  ADD COLUMN fecha_desde_salarios_devengados DATE NULL AFTER dias_salarios_devengados,
  ADD COLUMN dias_vacaciones_anteriores_reclamados SMALLINT UNSIGNED NULL AFTER fecha_desde_salarios_devengados;
