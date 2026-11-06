-- Recordatorio de pago pendiente: muchos clientes piden el link de pago de
-- la asesoría y nunca completan el pago dentro de los 30 minutos que tienen
-- (se detectó una tasa de abandono alta). link_pago guarda la URL real del
-- checkout de Mercado Pago (antes no se guardaba, solo el preference_id) y
-- recordatorio_pago_enviado evita mandar el recordatorio dos veces.
ALTER TABLE citas_asesoria
  ADD COLUMN link_pago VARCHAR(500) NULL AFTER mp_preference_id,
  ADD COLUMN recordatorio_pago_enviado TINYINT(1) NOT NULL DEFAULT 0 AFTER atendida;
