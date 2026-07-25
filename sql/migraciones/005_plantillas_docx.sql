-- ============================================================================
-- Migración 005: biblioteca de plantillas de escritos (más allá de la demanda).
--
-- La plantilla de demanda sigue funcionando exactamente igual que antes
-- (api/plantilla.php, pestaña "Formato de demanda"). Esta tabla es adicional:
-- permite subir cualquier número de plantillas .docx más (contestación,
-- promoción, oficio, lo que se necesite), cada una con nombre y descripción,
-- para elegir cuál usar al generar un escrito desde un expediente.
--
-- Los archivos .docx en sí se guardan en disco, en data/plantillas/ (la
-- carpeta data/ ya está protegida con "Require all denied" en su .htaccess).
--
-- Cómo usar: importar sobre la base de datos ya existente (phpMyAdmin →
-- pestaña "Importar", seleccionar este archivo). No borra ni modifica datos.
-- ============================================================================

CREATE TABLE IF NOT EXISTS plantillas_docx (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  nombre_disco VARCHAR(100) NOT NULL,
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_plantillas_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
