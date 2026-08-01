-- ============================================================================
-- Bot de WhatsApp (asesoría laboral por IA) + captación de prospectos de
-- despido en CDMX/Edomex.
--
-- prospectos: se crea una fila SOLO cuando la IA detecta, en la
-- conversación de WhatsApp, que la persona relata un despido y que radica
-- en Ciudad de México o Estado de México. A partir de ahí el bot deja de
-- responder automáticamente a ese número (pausado_bot = 1) y el seguimiento
-- lo toma un humano desde la vista "Prospectos" del sistema.
--
-- whatsapp_conversaciones: bitácora de TODOS los mensajes (de cualquier
-- número, sea o no un prospecto calificado) — sirve para darle contexto a
-- la IA en cada respuesta y para que el abogado vea el historial completo
-- de un prospecto en el sistema.
-- ============================================================================

CREATE TABLE prospectos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(30) NOT NULL,
  nombre VARCHAR(150) NULL,
  estado_ubicacion VARCHAR(60) NULL,
  resumen_caso TEXT NULL,
  estatus ENUM('nuevo','contactado','descartado','convertido') NOT NULL DEFAULT 'nuevo',
  pausado_bot TINYINT(1) NOT NULL DEFAULT 1,
  expediente_id INT UNSIGNED NULL,
  notas_internas TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prospectos_telefono (telefono),
  CONSTRAINT fk_prospectos_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_conversaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(30) NOT NULL,
  direccion ENUM('entrante','saliente') NOT NULL,
  texto TEXT NOT NULL,
  respondido_por ENUM('ia','humano') NOT NULL DEFAULT 'ia',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_whatsapp_conv_telefono (telefono, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
