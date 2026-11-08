-- Campos para 2 escritos nuevos generables desde "Generar escrito" (ver
-- "Otras plantillas de escritos" en Formato de demanda): Rescisión Art. 51
-- y Prima de Antigüedad (estilo jubilación/apartado B). Todos opcionales --
-- si quedan vacíos, el documento generado deja los placeholders de siempre.

ALTER TABLE expedientes
  -- Rescisión Art. 51 (causa imputable al patrón)
  ADD COLUMN rescision_fraccion VARCHAR(10) NULL,
  ADD COLUMN rescision_motivo_breve VARCHAR(255) NULL,
  ADD COLUMN fecha_rescision DATE NULL,
  ADD COLUMN hecho_causa_narrativa TEXT NULL,
  ADD COLUMN periodo_vacaciones_anterior VARCHAR(20) NULL,
  ADD COLUMN periodo_vacaciones_actual VARCHAR(20) NULL,
  ADD COLUMN banco1_nombre VARCHAR(150) NULL,
  ADD COLUMN banco1_cuenta VARCHAR(50) NULL,
  ADD COLUMN banco2_nombre VARCHAR(150) NULL,
  ADD COLUMN banco2_cuenta VARCHAR(50) NULL,
  ADD COLUMN banco3_nombre VARCHAR(150) NULL,
  ADD COLUMN banco3_cuenta VARCHAR(50) NULL,
  ADD COLUMN inspeccion_extremos_texto TEXT NULL,
  -- Prima de antigüedad (estilo jubilación / apartado B)
  ADD COLUMN monto_prima_antiguedad DECIMAL(12,2) NULL,
  ADD COLUMN fecha_solicitud_interna DATE NULL,
  ADD COLUMN hora_solicitud_interna TIME NULL,
  ADD COLUMN folio_solicitud_interna VARCHAR(50) NULL,
  ADD COLUMN funcionario_rh_nombre VARCHAR(150) NULL,
  ADD COLUMN funcionario_rh_puesto VARCHAR(150) NULL,
  ADD COLUMN numero_oficio_respuesta VARCHAR(100) NULL,
  ADD COLUMN fecha_oficio_respuesta DATE NULL,
  ADD COLUMN director_firma_nombre VARCHAR(150) NULL,
  ADD COLUMN coordinador_archivo_nombre VARCHAR(150) NULL,
  ADD COLUMN gerente_gestion_nombre VARCHAR(150) NULL,
  ADD COLUMN folio_fiscal_cfdi VARCHAR(50) NULL,
  ADD COLUMN periodo_recibo_desde DATE NULL,
  ADD COLUMN periodo_recibo_hasta DATE NULL,
  ADD COLUMN salario_quincenal_num DECIMAL(12,2) NULL,
  ADD COLUMN rfc_emisor_demandado VARCHAR(20) NULL;
