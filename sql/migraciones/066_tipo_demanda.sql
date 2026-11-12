-- "Tipo de demanda a presentar" (11 nov 2026, pedido explícito del
-- usuario): campo nuevo, SEPARADO de `tipo_asunto` (ese solo calcula el
-- plazo de prescripción -- Despido/Prestaciones/Otro -- son conceptos
-- legales distintos, no debía reutilizarse para esto). Define qué
-- formato de demanda se va a presentar (Demanda normal / Rescisión
-- Art. 51 / Prima de antigüedad) y, con eso, "Editar datos" muestra
-- solo los campos que ese formato necesita.
ALTER TABLE expedientes
  ADD COLUMN tipo_demanda VARCHAR(60) NULL AFTER tipo_asunto;
