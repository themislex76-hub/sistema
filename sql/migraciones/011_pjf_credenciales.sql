-- Cada abogado puede guardar su propio usuario/contraseña del Portal de
-- Servicios en Línea del PJF desde su perfil, para que el robot de
-- monitoreo Federal revise los expedientes de TODOS los abogados que
-- litigan casos federales, no solo de uno. La contraseña se guarda
-- cifrada (ver pjf_encrypt()/pjf_decrypt() en helpers.php) — nunca en
-- texto plano. Mismo patrón que google_refresh_token, ya existente en
-- esta tabla.
ALTER TABLE usuarios
  ADD COLUMN pjf_usuario VARCHAR(190) NULL AFTER google_conectado_en,
  ADD COLUMN pjf_password_enc TEXT NULL AFTER pjf_usuario,
  ADD COLUMN pjf_actualizado_en DATETIME NULL AFTER pjf_password_enc;
