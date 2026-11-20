-- Guarda la última vez que cada robot local (Node/Playwright, corre en la
-- máquina de Windows del usuario -- Federal, CDMX boletín, Buzón CDMX,
-- Edomex correo, Jurisprudencia) reportó al sistema, con éxito o con
-- error. Pedido explícito del usuario (20 nov 2026) tras descubrir que 3
-- de los 5 robots llevaban sin correr en automático por un archivo
-- lanzador (run_diario.bat) faltante en su máquina, y nadie lo notó hasta
-- que se investigó a mano con el Programador de tareas de Windows -- ver
-- CLAUDE.md. Con esta tabla, el sistema mismo puede avisar si alguno deja
-- de reportarse, sin depender de revisar `schtasks` manualmente.
CREATE TABLE IF NOT EXISTS robot_heartbeats (
  robot VARCHAR(40) NOT NULL PRIMARY KEY,
  ultima_corrida_en DATETIME NOT NULL,
  ultimo_ok TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_resumen VARCHAR(255) NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
