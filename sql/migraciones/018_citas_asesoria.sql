-- Citas de asesoría telefónica de pago agendadas desde el bot de WhatsApp.
-- Cada fila es un horario apartado con un abogado; "pendiente_pago" es un
-- apartado temporal mientras el cliente paga (se libera solo si no paga a
-- tiempo — ver citas_helpers.php), "confirmada" es un pago ya verificado
-- con Mercado Pago.
CREATE TABLE citas_asesoria (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(20) NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  estado ENUM('pendiente_pago','confirmada','cancelada','expirada') NOT NULL DEFAULT 'pendiente_pago',
  monto DECIMAL(10,2) NOT NULL DEFAULT 299.00,
  mp_preference_id VARCHAR(80) NULL,
  mp_payment_id VARCHAR(40) NULL,
  nombre_cliente VARCHAR(150) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en DATETIME NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  KEY idx_citas_usuario_fecha (usuario_id, fecha, hora_inicio),
  KEY idx_citas_telefono (telefono),
  KEY idx_citas_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
