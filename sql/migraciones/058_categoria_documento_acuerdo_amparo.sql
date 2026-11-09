-- Separa los acuerdos del amparo (Juzgados de Distrito y Tribunales de
-- Circuito) de los acuerdos del juicio laboral en la pestaña "Documentos"
-- de cada expediente -- hasta ahora ambos caían en la misma categoría
-- genérica 'acuerdo' y, mezclados en la misma tabla, eran fáciles de
-- confundir para los abogados.
ALTER TABLE expediente_documentos
  MODIFY COLUMN categoria ENUM(
    'constancia_no_conciliacion','demanda','contestacion','pruebas','actas',
    'convenio','acuerdo','acuerdo_amparo','otro'
  ) NOT NULL DEFAULT 'otro';
