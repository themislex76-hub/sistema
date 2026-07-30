-- ----------------------------------------------------------------------------
-- avisos_boletin: registro de avisos de boletín/lista de acuerdos por
-- expediente, capturados manualmente o por los robots de monitoreo
-- automático (Federal, CDMX, Edomex). Ver api/avisos_*.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS avisos_boletin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  fuente ENUM('cdmx_local','edomex_local','federal_laboral','otro') NOT NULL DEFAULT 'otro',
  origen ENUM('automatico','manual') NOT NULL DEFAULT 'manual',
  fecha_publicacion DATE NULL,
  resumen TEXT NOT NULL,
  url_verificacion VARCHAR(500) NULL,
  estado ENUM('nuevo','revisado','descartado') NOT NULL DEFAULT 'nuevo',
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revisado_por INT UNSIGNED NULL,
  revisado_en DATETIME NULL,
  KEY idx_avisos_expediente (expediente_id),
  KEY idx_avisos_estado (estado),
  CONSTRAINT fk_avisos_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_avisos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_avisos_revisado_por FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- edomex_captcha_pendiente: cola de un solo captcha a la vez que el robot de
-- Edomex (corre en la computadora del despacho, no en la nube, por el
-- bloqueo de IP de centros de datos) publica para que alguien lo resuelva
-- desde el propio sistema. Ver api/edomex_captcha_*.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS edomex_captcha_pendiente (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NULL,
  imagen_base64 MEDIUMTEXT NOT NULL,
  respuesta VARCHAR(20) NULL,
  estado ENUM('pendiente','resuelto','expirado') NOT NULL DEFAULT 'pendiente',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en DATETIME NULL,
  KEY idx_captcha_estado (estado),
  CONSTRAINT fk_captcha_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
