-- Permite generar el resumen ejecutivo del bot de WhatsApp acotado a un
-- periodo específico (hoy, última semana, último mes, este mes, o un rango
-- personalizado) en vez de siempre las últimas ~400 conversaciones. Se
-- guarda el rango usado en cada resumen para poder verlo después en el
-- historial.
ALTER TABLE resumenes_ejecutivos
  ADD COLUMN periodo_desde DATE NULL,
  ADD COLUMN periodo_hasta DATE NULL;
