-- Marca, por cada pago de convenio ya cobrado, si el socio que lo cobró
-- ya le liquidó a Rubén su parte del honorario (60% del 30% del
-- convenio) -- para llevar control de cobros internos entre socio y
-- despacho. Ver panel "Ingresos por periodo" (columna "¿Socio ya me
-- pagó?" y los totales de despacho liquidado/pendiente).
ALTER TABLE expediente_pagos
  ADD COLUMN socio_liquidado TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha_cobro;
