-- Documento oficial membretado del despacho (PDF del cálculo de
-- liquidación con membrete, para presentar a RH/patrón/Centro de
-- Conciliación), $49 MXN, pago único -- distinto del cálculo en texto,
-- que sigue siendo gratis siempre. Se guarda el cálculo completo en JSON
-- para poder regenerar el PDF exacto en el webhook de Mercado Pago, sin
-- depender de que la conversación siga activa al momento del pago.
CREATE TABLE compras_documento_calculo (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(20) NOT NULL,
  nombre_cliente VARCHAR(150) NULL,
  calculo_json TEXT NOT NULL,
  salario_diario DECIMAL(10,2) NOT NULL,
  monto DECIMAL(10,2) NOT NULL DEFAULT 49.00,
  estado ENUM('pendiente', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente',
  mp_preference_id VARCHAR(100) NULL,
  mp_payment_id VARCHAR(100) NULL,
  link_pago VARCHAR(255) NULL,
  pagado_en DATETIME NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
