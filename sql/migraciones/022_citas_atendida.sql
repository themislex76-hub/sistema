-- Antes, "Próximas asesorías agendadas" dejaba de mostrar una cita en
-- cuanto su fecha ya había pasado (WHERE fecha >= CURDATE()), sin importar
-- si el abogado realmente atendió la llamada o no — una cita pagada podía
-- pasarse sin que nadie se diera cuenta. Ahora se queda visible (marcada
-- como vencida) hasta que alguien la marque como atendida explícitamente.
ALTER TABLE citas_asesoria ADD COLUMN atendida TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;
