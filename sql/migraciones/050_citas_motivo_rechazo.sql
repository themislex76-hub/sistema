-- Guarda la razón real por la que un intento de pago de asesoría no se
-- confirma (tarjeta rechazada, fondos insuficientes, etc. -- el
-- "status_detail" que manda Mercado Pago en cada webhook). Antes se
-- tiraba esa información en cuanto el pago no era "approved", así que no
-- había forma de saber POR QUÉ fallaban los números que insistían varias
-- veces sin lograr pagar nunca -- solo que habían fallado.
ALTER TABLE citas_asesoria
  ADD COLUMN ultimo_estado_pago VARCHAR(30) NULL AFTER mp_payment_id,
  ADD COLUMN ultimo_motivo_rechazo VARCHAR(60) NULL AFTER ultimo_estado_pago,
  ADD COLUMN intentos_fallidos INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultimo_motivo_rechazo;
