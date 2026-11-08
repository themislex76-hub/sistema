-- Costos mensuales del despacho (IA, hosting, WhatsApp Business API,
-- comisión de Mercado Pago) capturados a mano por el Administrador cada
-- mes, para poder comparar contra los ingresos que el sistema ya sabe
-- automático (asesorías + cursos) y saber si el negocio vale la pena.
-- Ver api/costos_mensuales_resumen.php.
CREATE TABLE costos_mensuales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mes CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  costo_ia DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_hosting DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_whatsapp DECIMAL(10,2) NOT NULL DEFAULT 0,
  comision_mercadopago DECIMAL(10,2) NOT NULL DEFAULT 0,
  notas VARCHAR(255) NULL,
  actualizado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_costos_mensuales_mes (mes),
  FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
