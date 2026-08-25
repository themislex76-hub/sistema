-- Resumen ejecutivo con IA de cada expediente (informe corto para que un
-- jefe entienda el caso sin abrir todo el expediente). Se guarda en caché
-- junto con la fecha en que se generó -- el endpoint que lo sirve solo
-- vuelve a llamar a la IA cuando expedientes.actualizado_en es más
-- reciente que resumen_ejecutivo_generado_en (o cuando piden regenerarlo a
-- mano), para no pagar una llamada nueva cada vez que alguien abre el
-- expediente.
ALTER TABLE expedientes
  ADD COLUMN resumen_ejecutivo TEXT NULL,
  ADD COLUMN resumen_ejecutivo_generado_en DATETIME NULL;
