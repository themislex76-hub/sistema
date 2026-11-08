-- Coordinador con IA: además del resumen narrativo, el informe ejecutivo de
-- cada expediente ahora también sugiere una PRÓXIMA ACCIÓN corta y su
-- URGENCIA (alta/media/baja). La IA nunca calcula una fecha límite -- eso
-- lo sigue haciendo el sistema con código normal (ver diasHabilesPorUrgencia
-- en assets/app.js), a partir de cuándo se generó el informe. Se guarda
-- junto con el resumen y se reutiliza la misma marca de tiempo
-- (resumen_ejecutivo_generado_en) que ya existía -- es la misma llamada a
-- la IA, no una nueva.
ALTER TABLE expedientes
  ADD COLUMN accion_sugerida_ia TEXT NULL,
  ADD COLUMN urgencia_ia ENUM('alta','media','baja') NULL;
