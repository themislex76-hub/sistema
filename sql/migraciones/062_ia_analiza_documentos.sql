-- Resumen de IA para CUALQUIER documento que suba un abogado (no solo los
-- acuerdos que ya suben solos los robots de boletín, que ya tienen su
-- propio resumen en avisos_boletin desde la migración 060). Pedido del
-- usuario: ver de un vistazo de qué trata un documento sin tener que
-- abrirlo, directo en la pestaña "Documentos". Mismo patrón que esa
-- migración: si la IA sugiere con claridad una etapa Y el documento
-- menciona una fecha literal, también puede llenar sola esa etapa (nunca
-- sobrescribe una que el abogado ya registró a mano).
ALTER TABLE expediente_documentos
  ADD COLUMN ia_resumen TEXT NULL AFTER categoria,
  ADD COLUMN ia_etapa_sugerida VARCHAR(40) NULL AFTER ia_resumen,
  ADD COLUMN ia_fecha_mencionada DATE NULL AFTER ia_etapa_sugerida,
  ADD COLUMN ia_aplicado TINYINT(1) NOT NULL DEFAULT 0 AFTER ia_fecha_mencionada,
  ADD COLUMN ia_analizado_en DATETIME NULL AFTER ia_aplicado;
