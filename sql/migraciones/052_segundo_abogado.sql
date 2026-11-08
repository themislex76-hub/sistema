-- Un expediente puede asignarse a dos abogados del despacho (no solo uno),
-- porque entre ellos se apoyan en el mismo caso. Ambos tienen el mismo
-- acceso de lectura/escritura al expediente -- no hay un "principal" y un
-- "secundario", los dos son socios asignados por igual.
--
-- NOTA: esta columna ya se agregó manualmente en producción (se corrió
-- como 012_segundo_abogado.sql el 6 de noviembre de 2026) -- esta
-- migración queda aquí solo para que una instalación nueva desde cero
-- quede igual; no hace falta correrla otra vez en la base ya existente.
ALTER TABLE expedientes
  ADD COLUMN abogado_id_2 INT UNSIGNED NULL AFTER abogado_id,
  ADD KEY idx_expedientes_abogado_2 (abogado_id_2),
  ADD CONSTRAINT fk_expedientes_abogado_2 FOREIGN KEY (abogado_id_2) REFERENCES usuarios(id) ON DELETE SET NULL;
