-- Auditoría periódica con IA: a diferencia de los disparadores puntuales que
-- ya existen (un acuerdo de boletín nuevo, un documento que sube el abogado,
-- una respuesta a una pregunta del supervisor -- cada uno mira SOLO esa pieza
-- de información), este cron nuevo (cron_auditoria_expediente_ia.php) revisa
-- el expediente COMPLETO de un jalón (todas las etapas, todos los documentos
-- y sus resúmenes de IA, todos los avisos de boletín y sus resúmenes,
-- historial de preguntas/respuestas del supervisor) para detectar una etapa
-- que, cruzando varias piezas de información ya guardadas, debería tener
-- fecha y no la tiene -- algo que ningún disparador puntual alcanza a ver
-- por sí solo. Se guarda cuándo se auditó por última vez para no volver a
-- gastar una llamada a la IA en un expediente que no ha cambiado desde la
-- última revisión (mismo patrón que resumen_ejecutivo_generado_en).
ALTER TABLE expedientes
  ADD COLUMN ultima_auditoria_ia DATETIME NULL;
