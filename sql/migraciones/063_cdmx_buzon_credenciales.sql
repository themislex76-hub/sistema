-- ============================================================================
-- Migración 063: cuenta del Buzón Electrónico Laboral del PJCDMX.
--
-- El Poder Judicial de la Ciudad de México notifica los acuerdos de los
-- juicios laborales por un portal propio con login
-- (buzonelectronicolaboral.poderjudicialcdmx.gob.mx:2083) -- confirmado
-- real por el usuario el 9 nov 2026: cada cuenta (correo + contraseña) ve
-- todos los expedientes donde el abogado está dado de alta como apoderado,
-- con lista de acuerdos y PDF descargable por acuerdo. Reemplaza/complementa
-- al robot viejo de boletín público (robots/cdmx/cdmx.js, que se queda
-- intacto) con un mecanismo mucho más confiable -- mismo patrón que
-- edomex_correo_usuario/edomex_correo_password_enc (migración 057): cada
-- abogado guarda su propia cuenta desde "Mi cuenta", la contraseña se
-- cifra con pjf_encrypt()/pjf_decrypt() (helpers.php).
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

ALTER TABLE usuarios
  ADD COLUMN cdmx_buzon_usuario VARCHAR(190) NULL AFTER edomex_correo_actualizado_en,
  ADD COLUMN cdmx_buzon_password_enc TEXT NULL AFTER cdmx_buzon_usuario,
  ADD COLUMN cdmx_buzon_actualizado_en DATETIME NULL AFTER cdmx_buzon_password_enc;
