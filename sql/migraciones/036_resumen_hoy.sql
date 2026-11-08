-- Caché por usuario de "Qué hacer hoy": un resumen corto generado por IA
-- (Haiku) que prioriza pendientes, vencimientos y alertas ya calculados
-- por el frontend (buildAgendaEntries() en app.js -- la IA no calcula
-- ninguna fecha ni plazo, solo organiza y redacta). Se cachea por hash del
-- listado de pendientes que se le mandó: mientras no cambien los
-- pendientes reales de un día a otro, no se vuelve a llamar a la IA.

ALTER TABLE usuarios
  ADD COLUMN resumen_hoy_texto TEXT NULL AFTER pjf_actualizado_en,
  ADD COLUMN resumen_hoy_hash VARCHAR(64) NULL AFTER resumen_hoy_texto,
  ADD COLUMN resumen_hoy_generado_en DATETIME NULL AFTER resumen_hoy_hash;
