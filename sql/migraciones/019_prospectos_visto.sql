-- ============================================================================
-- Indicador de "mensaje nuevo" en Prospectos: guarda cuándo fue la última
-- vez que un abogado abrió el chat de ese prospecto, para poder comparar
-- contra el último mensaje ENTRANTE del cliente y marcar los que tienen
-- algo pendiente de leer/contestar sin tener que abrir cada uno.
--
-- Se inicializa a NOW() para los prospectos que ya existen, así el
-- indicador solo prende con mensajes que lleguen de aquí en adelante (no
-- marca como "nuevo" todo lo que ya se veía antes de este cambio).
-- ============================================================================

ALTER TABLE prospectos ADD COLUMN visto_en DATETIME NULL AFTER pausado_bot;
UPDATE prospectos SET visto_en = NOW();
