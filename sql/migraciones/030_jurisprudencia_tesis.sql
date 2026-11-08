-- Biblioteca local de tesis/jurisprudencia en materia laboral, alimentada
-- por el robot que revisa el Semanario Judicial de la Federación (SCJN).
-- Es la base tanto para el aviso de "tesis nueva esta semana" como para el
-- buscador con IA (la IA solo puede responder con tesis que de verdad
-- estén guardadas aquí, nunca inventadas).

CREATE TABLE jurisprudencia_tesis (
    registro_digital INT UNSIGNED NOT NULL,
    instancia VARCHAR(120) NULL,
    epoca VARCHAR(60) NULL,
    numero_tesis VARCHAR(60) NULL,
    tipo VARCHAR(30) NULL,
    materias VARCHAR(255) NULL,
    rubro TEXT NOT NULL,
    texto_completo MEDIUMTEXT NOT NULL,
    fecha_publicacion DATE NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (registro_digital),
    FULLTEXT KEY ft_busqueda (rubro, texto_completo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
