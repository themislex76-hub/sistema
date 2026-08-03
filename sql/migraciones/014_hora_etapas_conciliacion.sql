-- Agrega columna hora a expediente_etapas, para las nuevas etapas de
-- citatorio de conciliación prejudicial (primer y segundo citatorio), que
-- necesitan mostrarle al cliente no solo el día sino también la hora de su
-- cita ante el Centro de Conciliación.
ALTER TABLE expediente_etapas ADD COLUMN hora TIME NULL AFTER fecha;
