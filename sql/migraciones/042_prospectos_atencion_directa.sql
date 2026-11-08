-- Separa "solo quiere hablar con alguien / que le llamen" (sin ninguna
-- queja de por medio) de "reclamo" real (disputa de pago, acusación,
-- persona molesta) -- antes escalar_a_humano metía los dos casos bajo
-- 'reclamo' por igual, aunque uno es un reclamo urgente de verdad y el
-- otro es solo alguien que prefiere que le llamen para su cálculo de
-- liquidación (por ejemplo), diluyendo la señal de los reclamos reales.
ALTER TABLE prospectos
  MODIFY COLUMN tipo ENUM('despido','asesoria_paga','control_expedientes','reclamo','atencion_directa') NOT NULL DEFAULT 'despido';
