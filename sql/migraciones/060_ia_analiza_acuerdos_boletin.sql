-- Hasta ahora el resumen de un aviso de boletín era genérico ("Acuerdo
-- No. X del expediente Y, de fecha Z") -- nadie, ni la IA, sabía qué
-- decía el acuerdo de verdad. Esto agrega la lectura real del documento
-- por IA: qué pasó, y si corresponde, actualiza sola la bitácora de
-- "Etapas del juicio" (solo cuando esa etapa todavía no tenía fecha
-- capturada -- nunca sobrescribe algo que el abogado ya registró a mano).
ALTER TABLE avisos_boletin
  ADD COLUMN ia_resumen TEXT NULL AFTER resumen,
  ADD COLUMN ia_etapa_sugerida VARCHAR(40) NULL AFTER ia_resumen,
  ADD COLUMN ia_fecha_mencionada DATE NULL AFTER ia_etapa_sugerida,
  ADD COLUMN ia_aplicado TINYINT(1) NOT NULL DEFAULT 0 AFTER ia_fecha_mencionada,
  ADD COLUMN ia_analizado_en DATETIME NULL AFTER ia_aplicado;
