-- ============================================================================
-- Segundo tipo de prospecto: además de los leads de despido en CDMX/Edomex,
-- el bot ahora también ofrece a cualquier persona (sin importar el estado)
-- la asesoría personalizada de pago ($299 MXN, 1 hora) y registra el interés
-- cuando la persona acepta o pregunta cómo agendar/pagar.
-- ============================================================================

ALTER TABLE prospectos
  ADD COLUMN tipo ENUM('despido','asesoria_paga') NOT NULL DEFAULT 'despido' AFTER telefono;
