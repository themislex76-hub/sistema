-- Nueva categoría de prospecto para escalaciones urgentes (disputas de
-- pago, quejas, "conversación atorada") -- antes se mezclaban con
-- "Asesoría $299" normal, dificultando encontrarlas entre los interesados
-- reales que sí van avanzando bien.
ALTER TABLE prospectos
  MODIFY COLUMN tipo ENUM('despido','asesoria_paga','control_expedientes','reclamo') NOT NULL DEFAULT 'despido';
