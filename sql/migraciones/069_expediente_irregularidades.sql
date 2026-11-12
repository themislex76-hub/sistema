-- Panel "Posibles irregularidades" (12 nov 2026) -- pedido explícito del
-- usuario tras el bug real de Alan Campuzano (ver CLAUDE.md): "tenemos que
-- hacer que la ia sea muy efectiva revisando los juicios para detectar
-- irregularidades, atrasos, términos, llenar de manera correcta el
-- sistema". A diferencia de los mecanismos que ya existen (que llenan
-- huecos sin fecha), este corre una vez por semana y revisa lo que YA
-- está registrado buscando 2 tipos de problema, SIN corregir nada solo:
--   - 'orden_cronologico': 2 etapas ya registradas cuyas fechas rompen la
--     secuencia real del trámite -- candado que ya existía para llenados
--     automáticos (etapa_fecha_es_consistente()), aplicado aquí por
--     primera vez de forma retroactiva sobre captura manual también.
--   - 'fecha_sin_respaldo': una etapa que un mecanismo de IA llenó sola
--     (usuario_id IS NULL en expediente_historial) y que, revisada de
--     nuevo con el prompt reforzado de hoy, podría no tener respaldo
--     literal real (mismo patrón del bug de Alan Campuzano).
-- Pedido explícito del usuario: "muy práctico... no quitarles mucho
-- tiempo" -- por eso cada fila es un tap (Corregir -> abre Etapas del
-- juicio; Está bien -> descarta), nunca un formulario que llenar, y el
-- cron se auto-cierra solo cuando el abogado ya corrigió el dato (ver
-- cron_qa_irregularidades.php).
CREATE TABLE expediente_irregularidades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  etapa_key VARCHAR(60) NULL,
  tipo ENUM('orden_cronologico','fecha_sin_respaldo') NOT NULL,
  motivo TEXT NOT NULL,
  fecha_detectada DATE NULL,
  estado ENUM('pendiente','descartada','corregida') NOT NULL DEFAULT 'pendiente',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en DATETIME NULL,
  resuelto_por INT UNSIGNED NULL,
  UNIQUE KEY uq_irregularidad (expediente_id, etapa_key, tipo),
  KEY idx_irregularidad_estado (estado),
  CONSTRAINT fk_irregularidad_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_irregularidad_resuelto_por FOREIGN KEY (resuelto_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca cuándo una fila de historial (un llenado automático de IA, campo
-- LIKE 'etapa_%' con usuario_id NULL) ya pasó por la verificación semanal
-- de "fecha_sin_respaldo" -- para no volver a pagar la misma llamada de IA
-- sobre el mismo llenado una y otra vez.
ALTER TABLE expediente_historial
  ADD COLUMN qa_verificado_en DATETIME NULL AFTER creado_en;
