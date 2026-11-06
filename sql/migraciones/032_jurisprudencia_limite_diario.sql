-- Control del límite gratis de búsquedas de jurisprudencia (piloto: 5 al
-- día, compartidas entre todo el despacho, no por abogado individual).
-- Un renglón por día con el contador -- se resetea solo porque cada día
-- nuevo simplemente no tiene renglón todavía (se crea con INSERT ...
-- ON DUPLICATE KEY UPDATE la primera vez que alguien busca ese día).
CREATE TABLE jurisprudencia_uso_diario (
  fecha DATE NOT NULL PRIMARY KEY,
  busquedas INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
