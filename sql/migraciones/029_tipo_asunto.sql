-- Agrega el tipo de asunto a cada expediente, para que el plazo de
-- prescripción se calcule según el artículo de la LFT que corresponde
-- (Art. 518 despido, Art. 517 rescisión, Art. 519 riesgo de
-- trabajo/beneficiarios, Art. 516 regla general) en vez de asumir
-- siempre despido.
--
-- Los expedientes existentes se dejan en NULL a propósito: el sistema los
-- sigue tratando como "Despido" (ver tipoAsuntoPlazo() en assets/app.js),
-- que es el único tipo que manejaba hasta ahora, así que no cambia el
-- plazo que ya se les venía calculando.

ALTER TABLE expedientes
  ADD COLUMN tipo_asunto VARCHAR(150) NULL AFTER exp;
