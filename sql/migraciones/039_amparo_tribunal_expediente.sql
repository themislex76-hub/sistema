ALTER TABLE expedientes
  ADD COLUMN amparo_tribunal VARCHAR(190) NULL AFTER amparo_fecha_notif,
  ADD COLUMN amparo_expediente VARCHAR(60) NULL AFTER amparo_tribunal;
