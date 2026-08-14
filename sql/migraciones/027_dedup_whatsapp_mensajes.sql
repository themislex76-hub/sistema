-- Evita procesar el mismo mensaje de WhatsApp más de una vez. Meta puede
-- reintentar la entrega del webhook si no le respondemos a tiempo (el
-- retraso natural de la respuesta, el indicador de "escribiendo...", la
-- llamada a la IA — todo suma tiempo dentro de la misma petición) — sin
-- esto, cada reintento generaba una respuesta nueva (gastando saldo de
-- IA de más) y el cliente veía el mismo mensaje contestado varias veces.
ALTER TABLE whatsapp_conversaciones
  ADD COLUMN whatsapp_message_id VARCHAR(100) NULL AFTER texto,
  ADD UNIQUE INDEX idx_whatsapp_message_id (whatsapp_message_id);
