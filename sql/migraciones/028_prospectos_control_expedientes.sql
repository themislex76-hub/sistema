-- Tercer tipo de prospecto: además de despido y asesoría de pago, el bot
-- ahora también reconoce cuando quien escribe es un despacho interesado
-- en contratar Control de Expedientes (el sistema multidespacho) para sí
-- mismo — típicamente porque llegó desde la landing de venta
-- (controldeexpedientes.mx). Es un lead de venta B2B, no un trabajador
-- con un problema laboral.

ALTER TABLE prospectos
  MODIFY COLUMN tipo ENUM('despido','asesoria_paga','control_expedientes') NOT NULL DEFAULT 'despido';
