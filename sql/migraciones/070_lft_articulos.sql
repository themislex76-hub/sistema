-- Texto oficial vigente de la Ley Federal del Trabajo, artículo por
-- artículo, para que el bot de WhatsApp (y cualquier otra función de IA)
-- consulte el texto REAL de un artículo en vez de citarlo de memoria
-- (errores reales del bot: citar el Art. 804 o el Art. 47 para cosas que
-- no dicen). Se carga desde el PDF oficial de la Cámara de Diputados
-- (Leyes Federales Vigentes); `reforma_dof` es la fecha de "Última
-- reforma publicada" de esa versión. Cuando haya una reforma nueva se
-- vuelve a cargar completa (070b_lft_articulos_datos.sql la reemplaza).
CREATE TABLE IF NOT EXISTS lft_articulos (
    numero VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    titulo VARCHAR(255) NULL,
    capitulo VARCHAR(255) NULL,
    texto MEDIUMTEXT NOT NULL,
    reforma_dof DATE NOT NULL,
    PRIMARY KEY (numero),
    KEY idx_orden (orden),
    FULLTEXT KEY ft_texto (texto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
