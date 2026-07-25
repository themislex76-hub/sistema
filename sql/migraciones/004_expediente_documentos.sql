-- ============================================================================
-- Migración 004: gestión documental por expediente.
--
-- Los archivos en sí se guardan en disco, en data/documentos/{expediente_id}/
-- (la carpeta data/ ya está protegida con "Require all denied" en su
-- .htaccess, así que no se pueden descargar directo por URL — solo a través
-- de api/documentos_descargar.php, que valida sesión y permisos primero).
-- Esta tabla solo guarda los metadatos de cada archivo.
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

CREATE TABLE IF NOT EXISTS expediente_documentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  nombre_archivo VARCHAR(255) NOT NULL,
  nombre_disco VARCHAR(100) NOT NULL,
  tipo_mime VARCHAR(100) NULL,
  tamano_bytes INT UNSIGNED NULL,
  categoria ENUM('demanda','contestacion','pruebas','actas','convenio','otro') NOT NULL DEFAULT 'otro',
  subido_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_documentos_expediente (expediente_id),
  CONSTRAINT fk_documentos_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_documentos_subido_por FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
