-- Registra cada vez que el bot calcula un estimado real de liquidación
-- (calcular_estimado_liquidacion) — es la señal más confiable de que esa
-- persona tiene un caso real y mostró interés, aunque nunca haya llegado a
-- decir "sí, quiero agendar" (por eso no siempre queda como prospecto). Se
-- usa para el seguimiento proactivo: cron_seguimiento_calculadora.php la
-- consulta para saber a quién recordarle que agende la asesoría si se
-- quedó callado.
CREATE TABLE calculos_liquidacion (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(30) NOT NULL,
  monto_total DECIMAL(12,2) NULL,
  primer_seguimiento_en DATETIME NULL,
  segundo_seguimiento_en DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_calculos_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
