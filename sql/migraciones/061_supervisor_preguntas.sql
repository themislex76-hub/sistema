-- Supervisor por IA: en vez de solo listar pasivamente los "posibles
-- atrasos" (ya resuelto en la migración 059), el sistema ahora genera una
-- pregunta concreta por cada asunto atorado y se la manda al abogado --
-- cuando contesta, la IA interpreta la respuesta para llenar sola la
-- etapa correspondiente (mismo principio que ya usa
-- cron_analizar_acuerdos_ia.php con los acuerdos de boletín: nunca
-- sobrescribe una etapa que ya tiene fecha capturada).
CREATE TABLE supervisor_preguntas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  -- Misma clave que agenda_avisos_cache.clave (el "atraso" del que nació
  -- esta pregunta) -- evita generar una pregunta nueva cada día mientras
  -- la anterior siga sin contestarse.
  clave VARCHAR(40) NOT NULL,
  etapa_key VARCHAR(40) NOT NULL,
  pregunta TEXT NOT NULL,
  respuesta TEXT NULL,
  fecha_aplicada DATE NULL,
  estado ENUM('pendiente','respondida','aplicada') NOT NULL DEFAULT 'pendiente',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  respondido_por INT UNSIGNED NULL,
  respondido_en DATETIME NULL,
  UNIQUE KEY uq_supervisor_pregunta (expediente_id, clave),
  KEY idx_supervisor_estado (estado),
  CONSTRAINT fk_supervisor_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_supervisor_respondido_por FOREIGN KEY (respondido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
