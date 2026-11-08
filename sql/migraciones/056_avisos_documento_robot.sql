-- ============================================================================
-- Migración 056: el robot de boletines puede adjuntar el PDF del acuerdo.
--
-- Hasta ahora un aviso de boletín ("nuevo acuerdo") solo traía un resumen de
-- texto -- para ver el documento había que entrar al portal (CDMX/Edomex/
-- PJF) a mano. Ahora el robot Federal puede subir el PDF del acuerdo al
-- mismo almacén de documentos que ya usa cada expediente (pestaña
-- "Documentos"), y el aviso queda ligado a ese documento: el botón "ver
-- fuente" abre el PDF directo desde el sistema (ya con sesión), y hay un
-- botón nuevo para borrar el documento si el robot subió el PDF equivocado.
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

-- El código (api/expediente_helpers.php, CATEGORIA_DOCUMENTO_KEYS) ya
-- esperaba 'constancia_no_conciliacion' como categoría válida, pero esa
-- opción nunca se había agregado aquí en la base de datos -- se incluye de
-- una vez junto con la nueva 'acuerdo', sin quitar ninguna de las que ya
-- existían.
ALTER TABLE expediente_documentos
  MODIFY COLUMN categoria ENUM('constancia_no_conciliacion','demanda','contestacion','pruebas','actas','convenio','acuerdo','otro') NOT NULL DEFAULT 'otro';

ALTER TABLE avisos_boletin
  ADD COLUMN documento_id INT UNSIGNED NULL AFTER url_verificacion,
  ADD CONSTRAINT fk_avisos_documento FOREIGN KEY (documento_id) REFERENCES expediente_documentos(id) ON DELETE SET NULL;
