-- Permite turnarle cada prospecto de WhatsApp a un abogado específico del
-- equipo (o dejarlo sin asignar mientras el Administrador decide). Al
-- convertir el prospecto en expediente, el abogado asignado aquí pasa a
-- ser el abogado_id del expediente nuevo.
ALTER TABLE prospectos
  ADD COLUMN asignado_a INT UNSIGNED NULL AFTER pausado_bot,
  ADD CONSTRAINT fk_prospectos_asignado FOREIGN KEY (asignado_a) REFERENCES usuarios(id) ON DELETE SET NULL;
