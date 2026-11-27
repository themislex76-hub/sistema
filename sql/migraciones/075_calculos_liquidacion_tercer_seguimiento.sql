-- Tercer y último recordatorio de venta (24 nov 2026) -- hasta hoy, pasadas
-- las 24h de silencio desde que alguien calculó su liquidación, la ventana
-- de WhatsApp se cierra y NADIE lo volvía a contactar nunca, sin importar
-- cuántos días pasaran -- una venta perdida real. Este recordatorio usa una
-- plantilla aprobada de Meta (fuera de la ventana de 24h) para darle un
-- último empujón, 1-7 días después, antes de dejarlo ir del todo.
ALTER TABLE calculos_liquidacion
  ADD COLUMN tercer_seguimiento_en DATETIME NULL AFTER segundo_seguimiento_en;
