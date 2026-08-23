-- Índice FULLTEXT solo sobre el rubro (título) de cada tesis, aparte del
-- que ya existe combinado con texto_completo (ft_busqueda). Se usa para el
-- primer paso, gratis, del buscador de jurisprudencia barato: MySQL no
-- deja usar MATCH(rubro) si no hay un índice FULLTEXT que cubra
-- exactamente esa columna sola -- buscar solo en el rubro (más corto y
-- específico que el texto completo de la tesis) da una relevancia más
-- limpia que buscar contra el cuerpo entero, que abunda en palabras
-- genéricas repetidas en casi cualquier tesis laboral.
ALTER TABLE jurisprudencia_tesis
  ADD FULLTEXT KEY ft_rubro (rubro);
