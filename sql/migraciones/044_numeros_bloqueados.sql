-- Números que el despacho decide bloquear (ej. alguien que hizo un
-- reclamo injustificado, insultó, etc.) -- el bot deja de contestarle
-- solo, pero si escribe de nuevo SÍ se avisa al despacho (igual que un
-- reclamo), para no perder de vista el caso por completo. Ver
-- procesar_mensaje_entrante() en whatsapp_procesar.php.
CREATE TABLE numeros_bloqueados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(20) NOT NULL,
  motivo VARCHAR(255) NULL,
  bloqueado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_numeros_bloqueados_telefono (telefono),
  FOREIGN KEY (bloqueado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
