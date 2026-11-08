-- Nuevo tipo de prospecto: interés real en uno de los cursos en línea
-- (Nuevo Procedimiento Laboral Mexicano, El Juicio de Amparo, Actas
-- Administrativas Laborales) -- antes esto no se guardaba en ningún
-- lado, la única forma de verlo era buscando "curso" a mano en
-- whatsapp_conversaciones. curso_interes guarda cuál de los 3 le
-- interesó (para el mensaje de seguimiento y para que el abogado sepa
-- qué ofrecer). seguimiento_en marca cuándo se le mandó el recordatorio
-- automático de seguimiento -- se manda UNA sola vez, nunca se repite
-- (ver cron_seguimiento_cursos.php), para no hostigar a la persona.
ALTER TABLE prospectos
  MODIFY COLUMN tipo ENUM('despido','asesoria_paga','control_expedientes','reclamo','atencion_directa','interes_curso') NOT NULL DEFAULT 'despido',
  ADD COLUMN curso_interes VARCHAR(100) NULL AFTER resumen_caso,
  ADD COLUMN seguimiento_en DATETIME NULL AFTER actualizado_en;
