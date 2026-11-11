-- IA sugiere la categoría de un documento al analizarlo (11 nov 2026,
-- pedido explícito del usuario tras ver funcionando el resumen de la
-- pieza 4: "me gusta"). Pensado SOLO para documentos guardados bajo el
-- cajón genérico 'otro' -- nunca se aplica sola, el abogado la confirma
-- con un tap en la pestaña "Documentos" (api/documentos_recategorizar.php),
-- porque a diferencia de una fecha de etapa, hoy no hay forma de deshacer
-- una recategorización mal aplicada desde la UI salvo borrando el
-- documento entero.
ALTER TABLE expediente_documentos
  ADD COLUMN ia_categoria_sugerida VARCHAR(40) NULL AFTER ia_analizado_en;
