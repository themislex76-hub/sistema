-- ============================================================================
-- Migración 057: cuenta de correo de notificaciones electrónicas (Edomex).
--
-- El Poder Judicial del Estado de México notifica los acuerdos de los
-- juicios laborales (Tlalnepantla) por correo electrónico institucional
-- (mail.pjedomex.gob.mx, webmail Carbonio -- NO tiene acceso IMAP, solo por
-- la página). Cada abogado puede guardar su propio usuario/contraseña de
-- ese correo desde su perfil, para que el nuevo robot (robots/edomex_correo)
-- revise la bandeja y reporte los acuerdos sin depender del boletín público
-- con captcha. Mismo patrón que pjf_usuario/pjf_password_enc -- la
-- contraseña se guarda cifrada con la misma llave (pjf_encrypt()/
-- pjf_decrypt() en helpers.php, a pesar del nombre ya genérico para
-- cualquier credencial de robot).
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

ALTER TABLE usuarios
  ADD COLUMN edomex_correo_usuario VARCHAR(190) NULL AFTER pjf_actualizado_en,
  ADD COLUMN edomex_correo_password_enc TEXT NULL AFTER edomex_correo_usuario,
  ADD COLUMN edomex_correo_actualizado_en DATETIME NULL AFTER edomex_correo_password_enc;
