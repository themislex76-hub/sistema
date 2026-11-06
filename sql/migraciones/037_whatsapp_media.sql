-- Antes, cualquier imagen o documento que un cliente mandara por WhatsApp
-- (típicamente un comprobante de pago) se perdía por completo: el bot solo
-- contestaba "no puedo leer esto" y nunca se guardaba en ningún lado -- se
-- detectó en producción con un cliente que insistía en haber mandado un
-- comprobante que nadie pudo ver nunca. Estas columnas guardan la ruta del
-- archivo ya descargado (en data/whatsapp_media/) y su tipo, para poder
-- mostrarlo en Conversaciones/Prospectos.
ALTER TABLE whatsapp_conversaciones
  ADD COLUMN media_ruta VARCHAR(255) NULL AFTER texto,
  ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_ruta;
