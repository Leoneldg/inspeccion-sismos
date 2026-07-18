-- =====================================================================
-- FRENTES DE TRABAJO NUMERADOS
-- ---------------------------------------------------------------------
-- Nueva estructura:
--   · Frente de Trabajo 1, 2, 3… (numerados, no por nombre)
--   · Cada frente cubre una o varias parroquias
--   · Cada frente tiene un equipo de supervisión
--   · Dentro del frente se crean cuadrillas de trabajo
--   · Las edificaciones se asignan al frente
--
-- La estructura anterior (frente_trabajo con tipos GDC/sistematizador)
-- se conserva intacta: no se borra nada.
-- =====================================================================

-- 1) El frente en sí.
CREATE TABLE IF NOT EXISTS `frente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` int(10) unsigned NOT NULL COMMENT 'Frente de Trabajo N',
  `nombre` varchar(120) DEFAULT NULL COMMENT 'Nombre opcional, ej: Frente Centro',
  `ente_id` int(10) unsigned DEFAULT NULL COMMENT 'Ente al que pertenece',
  `estado` varchar(100) NOT NULL DEFAULT 'Distrito Capital',
  `observaciones` varchar(400) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frente_numero` (`numero`, `estado`),
  KEY `idx_frente_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Parroquias que cubre cada frente (uno a varias).
CREATE TABLE IF NOT EXISTS `frente_parroquia` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `frente_id` int(10) unsigned NOT NULL,
  `estado` varchar(100) NOT NULL DEFAULT 'Distrito Capital',
  `parroquia` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fp` (`frente_id`, `estado`, `parroquia`),
  KEY `idx_fp_parroquia` (`estado`, `parroquia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Equipo de supervisión del frente.
CREATE TABLE IF NOT EXISTS `frente_supervisor` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `frente_id` int(10) unsigned NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `cargo` varchar(80) DEFAULT NULL COMMENT 'Supervisor, Ingeniero residente, Inspector…',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_fs_frente` (`frente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Cuadrillas de trabajo dentro del frente.
CREATE TABLE IF NOT EXISTS `cuadrilla` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `frente_id` int(10) unsigned NOT NULL,
  `numero` int(10) unsigned NOT NULL COMMENT 'Cuadrilla N dentro del frente',
  `nombre` varchar(120) DEFAULT NULL COMMENT 'Nombre opcional',
  `especialidad` varchar(80) DEFAULT NULL COMMENT 'Albañilería, plomería, electricidad…',
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cuadrilla` (`frente_id`, `numero`),
  KEY `idx_cuad_frente` (`frente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Integrantes de cada cuadrilla.
CREATE TABLE IF NOT EXISTS `cuadrilla_integrante` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cuadrilla_id` int(10) unsigned NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `oficio` varchar(80) DEFAULT NULL COMMENT 'Albañil, ayudante, plomero…',
  `es_jefe` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_ci_cuadrilla` (`cuadrilla_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Asignación de edificaciones al frente.
CREATE TABLE IF NOT EXISTS `asignacion_frente_obra` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspeccion_id` int(10) unsigned NOT NULL,
  `frente_id` int(10) unsigned NOT NULL,
  `cuadrilla_id` int(10) unsigned DEFAULT NULL COMMENT 'Opcional: cuadrilla concreta',
  `asignado_por` int(10) unsigned DEFAULT NULL,
  `asignado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_afo_inspeccion` (`inspeccion_id`),
  KEY `idx_afo_frente` (`frente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación
SELECT 'frente' AS tabla, COUNT(*) AS registros FROM `frente`
UNION ALL SELECT 'frente_parroquia', COUNT(*) FROM `frente_parroquia`
UNION ALL SELECT 'frente_supervisor', COUNT(*) FROM `frente_supervisor`
UNION ALL SELECT 'cuadrilla', COUNT(*) FROM `cuadrilla`
UNION ALL SELECT 'cuadrilla_integrante', COUNT(*) FROM `cuadrilla_integrante`;
