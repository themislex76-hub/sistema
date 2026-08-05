-- ============================================================================
-- Marca si ya se le mandó al cliente el recordatorio de "tu asesoría es en 1
-- hora" — evita mandarlo dos veces si el cron corre más seguido de lo
-- necesario, y evita que quede sin marcar si el cron corre menos seguido
-- (la ventana de búsqueda del cron es de 50 a 70 minutos antes).
-- ============================================================================

ALTER TABLE citas_asesoria ADD COLUMN recordatorio_enviado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;
