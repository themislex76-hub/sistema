-- Revisión automática diaria de las conversaciones del bot de WhatsApp
-- (cron_revisar_conversaciones_bot.php). Un renglón por teléfono y día
-- revisado; solo se muestran en el panel "Conversaciones a revisar" las
-- que la IA revisora marcó con algún problema (veredicto 'revisar').
CREATE TABLE IF NOT EXISTS bot_revisiones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telefono VARCHAR(30) NOT NULL,
    fecha DATE NOT NULL,
    veredicto ENUM('ok','revisar') NOT NULL,
    gravedad ENUM('alta','media','baja') NULL,
    resumen VARCHAR(500) NULL,
    hallazgos MEDIUMTEXT NULL,
    estado ENUM('pendiente','atendida','descartada') NOT NULL DEFAULT 'pendiente',
    resuelto_por INT UNSIGNED NULL,
    resuelto_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bot_revision (telefono, fecha),
    KEY idx_bot_revision_estado (veredicto, estado, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
