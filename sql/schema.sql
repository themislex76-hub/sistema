-- ============================================================================
-- Sistema de Gestión de Litigios Laborales — Expertos Laborales Abogados
-- Esquema MySQL
--
-- Cómo usar: importar este archivo completo en phpMyAdmin (pestaña "Importar")
-- sobre la base de datos vacía que crees en cPanel. Ver docs/DEPLOY_CPANEL.md
-- para los pasos exactos.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- usuarios: abogados y administrador del despacho. Reemplaza al array EQUIPO
-- que antes vivía en localStorage. Las contraseñas se guardan con
-- password_hash() de PHP (bcrypt), nunca en texto plano ni con SHA-256 simple.
-- ----------------------------------------------------------------------------
CREATE TABLE usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('administrador','abogado') NOT NULL DEFAULT 'abogado',
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL DEFAULT NULL,
  google_refresh_token TEXT NULL,
  google_calendar_email VARCHAR(190) NULL,
  google_conectado_en DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expedientes: fusiona lo que antes era CASES_DATA (49 casos importados) +
-- CASES_CUSTOM (altas manuales) + los overrides de meta.campos. Aquí cada
-- columna tiene el valor "efectivo" real: editar un campo hace UPDATE directo,
-- ya no hay distinción entre "dato importado original" y "dato corregido".
-- El campo abogado_id es FK real (antes era texto libre comparado por nombre,
-- lo que se rompía al renombrar a un abogado).
-- ----------------------------------------------------------------------------
CREATE TABLE expedientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exp VARCHAR(60) NULL,
  status VARCHAR(150) NULL,
  instancia VARCHAR(255) NULL,
  ultima_nota TEXT NULL,
  actor VARCHAR(255) NULL,
  demandado VARCHAR(255) NULL,
  abogado_id INT UNSIGNED NULL,
  puesto VARCHAR(255) NULL,
  junta VARCHAR(255) NULL,
  dom_demandado TEXT NULL,
  fecha_ingreso DATE NULL,
  salario_mensual DECIMAL(12,2) NULL,
  salario_diario DECIMAL(12,2) NULL,
  sdi DECIMAL(12,2) NULL,
  fecha_baja DATE NULL,
  curp VARCHAR(30) NULL,
  antiguedad_anios DECIMAL(6,2) NULL,
  telefono VARCHAR(80) NULL,
  correo VARCHAR(190) NULL,
  indemnizacion_90 DECIMAL(12,2) NULL,
  indemnizacion_60 DECIMAL(12,2) NULL,
  prima_antiguedad DECIMAL(12,2) NULL,
  vacaciones_dias DECIMAL(8,2) NULL,
  vacaciones_monto DECIMAL(12,2) NULL,
  prima_vacacional DECIMAL(12,2) NULL,
  aguinaldo_dias DECIMAL(8,2) NULL,
  aguinaldo_monto DECIMAL(12,2) NULL,
  total_90 DECIMAL(12,2) NULL,
  total_60 DECIMAL(12,2) NULL,
  honorario_90 DECIMAL(12,2) NULL,
  honorario_60 DECIMAL(12,2) NULL,
  fecha_inicio_conciliacion DATE NULL,
  firmo_renuncia VARCHAR(60) NULL,
  fecha_constancia DATE NULL,
  tribunal VARCHAR(255) NULL,
  giro_empresa VARCHAR(255) NULL,
  quien_despidio VARCHAR(255) NULL,
  puesto_despidio VARCHAR(255) NULL,
  hora_despido VARCHAR(30) NULL,
  testigos TEXT NULL,
  dom_testigos TEXT NULL,

  -- Datos manuales para el cálculo de liquidación que NO se pueden derivar
  -- de fecha/salario (ver computeLiquidacion() en assets/app.js): aguinaldo
  -- pactado por contrato si es mayor al mínimo de 15 días, salarios ya
  -- trabajados y no pagados (Art. 82), y vacaciones de años anteriores que
  -- el cliente reclama (topadas al máximo no prescrito, Art. 516).
  aguinaldo_dias_pactados DECIMAL(6,2) NULL,
  dias_salarios_devengados SMALLINT UNSIGNED NULL,
  fecha_desde_salarios_devengados DATE NULL,
  dias_vacaciones_anteriores_reclamados SMALLINT UNSIGNED NULL,
  -- Prima vacacional pactada por contrato, en % (Art. 80 LFT exige un mínimo
  -- de 25%; se deja NULL cuando aplica el mínimo, y el sistema usa 25%).
  prima_vacacional_pct_pactada DECIMAL(5,2) NULL,

  -- Antes vivían en meta (gestión interna / amparo / convenio manual):
  notas_internas TEXT NULL,
  cobro_pendiente TINYINT(1) NOT NULL DEFAULT 0,
  concluido_manual TINYINT(1) NOT NULL DEFAULT 0,
  amparo_activo TINYINT(1) NOT NULL DEFAULT 0,
  amparo_presentado TINYINT(1) NOT NULL DEFAULT 0,
  amparo_fecha_notif DATE NULL,
  amparo_notas TEXT NULL,
  convenio_activo TINYINT(1) NOT NULL DEFAULT 0,
  convenio_monto DECIMAL(12,2) NULL,
  convenio_fecha_pago DATE NULL,

  -- Portal de cliente: código de acceso propio del expediente.
  codigo_acceso VARCHAR(20) NULL,
  portal_intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  portal_bloqueado_hasta DATETIME NULL DEFAULT NULL,

  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_expedientes_abogado (abogado_id),
  KEY idx_expedientes_status (status(50)),
  CONSTRAINT fk_expedientes_abogado FOREIGN KEY (abogado_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_etapas: checklist de las 13 etapas procesales (ETAPAS_DEF en el
-- frontend). Una fila por etapa que ya fue tocada; las que no existen se
-- consideran "pendientes" (igual que antes con meta.etapas = {}).
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_etapas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  etapa_key VARCHAR(60) NOT NULL,
  fecha DATE NULL,
  fecha_programada DATE NULL,
  resultado VARCHAR(30) NULL,
  UNIQUE KEY uq_expediente_etapa (expediente_id, etapa_key),
  CONSTRAINT fk_etapas_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_pagos: pestaña "Cobros" (lista de pagos de convenio).
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_pagos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  fecha DATE NULL,
  monto DECIMAL(12,2) NULL,
  cobrado TINYINT(1) NOT NULL DEFAULT 0,
  fecha_cobro DATE NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_pagos_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_prestaciones_extra: prestaciones adicionales capturadas a mano
-- en "Cálculo de liquidación" que no forman parte del cálculo estándar de
-- ley (p.ej. fondo de ahorro pactado por contrato) — concepto libre + monto,
-- se suman al total del cálculo.
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_prestaciones_extra (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  concepto VARCHAR(150) NOT NULL,
  monto DECIMAL(12,2) NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_prestaciones_extra_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_pendientes: términos/pendientes personalizados.
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_pendientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  descripcion VARCHAR(255) NULL,
  fecha_inicio DATE NULL,
  dias SMALLINT NULL,
  tipo ENUM('habiles','naturales','fecha_fija') NOT NULL DEFAULT 'habiles',
  cumplido TINYINT(1) NOT NULL DEFAULT 0,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_pendientes_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_notas: bitácora visible al equipo (y resumida al cliente).
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_notas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  usuario_nombre VARCHAR(150) NOT NULL,
  texto TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notas_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_notas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_historial: bitácora de auditoría (solo Administrador). El
-- usuario que hizo el cambio SIEMPRE se toma de la sesión del servidor, nunca
-- de un valor enviado por el navegador — a diferencia de la versión anterior.
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_historial (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  usuario_nombre VARCHAR(150) NOT NULL,
  campo VARCHAR(100) NOT NULL,
  antes TEXT NULL,
  despues TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_historial_expediente FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- sesiones_log: registro simple de inicios de sesión (auditoría de acceso,
-- ayuda a detectar intentos sospechosos). No es la sesión activa en sí
-- (eso lo maneja PHP nativamente con session_start()).
-- ----------------------------------------------------------------------------
CREATE TABLE sesiones_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  email_intentado VARCHAR(190) NULL,
  exito TINYINT(1) NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sesiones_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- portal_intentos_log: intentos de acceso al portal de cliente (para
-- detectar/limitar adivinanza de expediente+apellido+código).
-- ----------------------------------------------------------------------------
CREATE TABLE portal_intentos_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expediente_id INT UNSIGNED NULL,
  exp_buscado VARCHAR(60) NULL,
  exito TINYINT(1) NOT NULL,
  ip VARCHAR(45) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- configuracion: pares clave/valor simples (p.ej. metadatos de la plantilla
-- de demanda actualmente activa). El archivo .docx en sí se guarda en disco
-- (carpeta data/), no en la base de datos.
-- ----------------------------------------------------------------------------
CREATE TABLE configuracion (
  clave VARCHAR(100) NOT NULL PRIMARY KEY,
  valor TEXT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- dias_inhabiles: calendario editable de días inhábiles adicionales a
-- sábados/domingos (descansos obligatorios Art. 74 LFT + los que declare cada
-- tribunal), usado por el cómputo de términos en todo el sistema.
-- ----------------------------------------------------------------------------
CREATE TABLE dias_inhabiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  descripcion VARCHAR(190) NOT NULL,
  ambito ENUM('federal','cdmx','edomex','todos') NOT NULL DEFAULT 'todos',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dias_inhabiles (fecha, ambito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- expediente_documentos: gestión documental por expediente. Los archivos en
-- sí viven en disco (data/documentos/{expediente_id}/), protegidos por el
-- .htaccess "Require all denied" de data/ — esta tabla solo guarda metadatos.
-- ----------------------------------------------------------------------------
CREATE TABLE expediente_documentos (
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

-- ----------------------------------------------------------------------------
-- plantillas_docx: biblioteca de plantillas de escritos adicionales a la
-- demanda (que sigue viviendo aparte, en data/plantilla_demanda.docx —
-- ver api/plantilla.php). Los archivos de estas viven en disco, en
-- data/plantillas/, protegidos por el mismo .htaccess de data/.
-- ----------------------------------------------------------------------------
CREATE TABLE plantillas_docx (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  nombre_disco VARCHAR(100) NOT NULL,
  creado_por INT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_plantillas_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- tribunales_custom: catálogo nacional de tribunales laborales, que se
-- completa con lo que se escribe en "Otro (especificar)" del selector de
-- Tribunal en EDITABLE_FIELDS, para que a partir de ahí sea una opción
-- normal del selector. Ver api/tribunales_custom_*.php.
-- ----------------------------------------------------------------------------
CREATE TABLE tribunales_custom (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estado VARCHAR(60) NOT NULL,
  nombre VARCHAR(300) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tribunal_custom (estado, nombre(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
