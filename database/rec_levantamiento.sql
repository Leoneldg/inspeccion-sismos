-- =====================================================================
-- LEVANTAMIENTO TÉCNICO DEL EDIFICIO (Reconstrucción)
-- ---------------------------------------------------------------------
-- Formulario de corroboración de datos del edificio, con jerarquía:
--   edificio -> datos generales
--            -> pisos -> elementos del piso (ascensor, escaleras, etc.)
--                     -> apartamentos -> elementos (habitaciones, sala…)
-- Cada elemento puede requerir reparación y llevar fotos con detalle.
-- Todo se vincula a una inspección (el edificio) por inspeccion_id.
-- =====================================================================

-- 1) DATOS GENERALES DEL EDIFICIO (uno por inspección)
CREATE TABLE IF NOT EXISTS `rec_edificio` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspeccion_id` int(10) unsigned NOT NULL,
  `num_pisos` int(10) unsigned DEFAULT NULL,
  `aptos_por_piso` int(10) unsigned DEFAULT NULL,
  `tiene_areas_comunes` tinyint(1) DEFAULT 0,
  `areas_comunes_desc` text DEFAULT NULL COMMENT 'Descripción de áreas comunes generales',
  -- Situación de elementos globales del edificio
  `azotea_estado` enum('Buena','Regular','Requiere reparación','No aplica') DEFAULT NULL,
  `azotea_obs` varchar(255) DEFAULT NULL,
  `tanques_estado` enum('Buena','Regular','Requiere reparación','No aplica') DEFAULT NULL,
  `tanques_obs` varchar(255) DEFAULT NULL,
  `impermeabilizacion_estado` enum('Buena','Regular','Requiere reparación','No aplica') DEFAULT NULL,
  `impermeabilizacion_obs` varchar(255) DEFAULT NULL,
  `completado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = formulario base terminado',
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rec_edificio_insp` (`inspeccion_id`),
  CONSTRAINT `fk_rec_edificio_insp` FOREIGN KEY (`inspeccion_id`)
    REFERENCES `inspecciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) PISOS
CREATE TABLE IF NOT EXISTS `rec_piso` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `edificio_id` int(10) unsigned NOT NULL,
  `numero_piso` int(11) NOT NULL COMMENT 'Puede ser 0=PB, negativo=sótano',
  `tiene_areas_comunes` tinyint(1) DEFAULT 0,
  `areas_comunes_desc` varchar(255) DEFAULT NULL,
  `completado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_piso` (`edificio_id`,`numero_piso`),
  CONSTRAINT `fk_piso_edificio` FOREIGN KEY (`edificio_id`)
    REFERENCES `rec_edificio` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) ELEMENTOS DE UN PISO (ascensor, bajante de basura, jardinera, escaleras…)
CREATE TABLE IF NOT EXISTS `rec_elemento_piso` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `piso_id` int(10) unsigned NOT NULL,
  `tipo` varchar(60) NOT NULL COMMENT 'ascensor, bajante_basura, jardinera, escaleras, pasillo…',
  `presente` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = el piso tiene este elemento',
  `estado` enum('Bueno','Regular','Requiere reparación','No funciona') DEFAULT NULL,
  `necesita_reparacion` tinyint(1) NOT NULL DEFAULT 0,
  `observaciones` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_elem_piso` (`piso_id`,`tipo`),
  CONSTRAINT `fk_elem_piso` FOREIGN KEY (`piso_id`)
    REFERENCES `rec_piso` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) APARTAMENTOS (todos los de cada piso)
CREATE TABLE IF NOT EXISTS `rec_apartamento` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `piso_id` int(10) unsigned NOT NULL,
  `identificador` varchar(30) NOT NULL COMMENT 'Ej: 1-A, 2-B',
  `num_habitaciones` int(10) unsigned DEFAULT 0,
  `num_salas` int(10) unsigned DEFAULT 0,
  `num_balcones` int(10) unsigned DEFAULT 0,
  `num_cocinas` int(10) unsigned DEFAULT 0,
  `completado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_apto_piso` (`piso_id`),
  CONSTRAINT `fk_apto_piso` FOREIGN KEY (`piso_id`)
    REFERENCES `rec_piso` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) AMBIENTES DE UN APARTAMENTO (cada habitación, sala, balcón, cocina)
CREATE TABLE IF NOT EXISTS `rec_ambiente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `apartamento_id` int(10) unsigned NOT NULL,
  `tipo` enum('Habitación','Sala','Balcón','Cocina') NOT NULL,
  `numero` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Habitación 1, 2, 3…',
  `necesita_reparacion` tinyint(1) NOT NULL DEFAULT 0,
  `observaciones` varchar(255) DEFAULT NULL,
  `completado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_amb_apto` (`apartamento_id`),
  CONSTRAINT `fk_amb_apto` FOREIGN KEY (`apartamento_id`)
    REFERENCES `rec_apartamento` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) FOTOS DEL LEVANTAMIENTO (polimórficas: apuntan a cualquier nivel)
--    Una foto de un elemento de piso, de un ambiente, del edificio, etc.
--    Cuando el ambiente/elemento necesita reparación, la foto lleva
--    'parte' (qué pared, closet, techo, piso…).
CREATE TABLE IF NOT EXISTS `rec_foto` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nivel` enum('edificio','piso','elemento_piso','apartamento','ambiente') NOT NULL,
  `ref_id` int(10) unsigned NOT NULL COMMENT 'id del registro del nivel correspondiente',
  `parte` varchar(60) DEFAULT NULL COMMENT 'pared_norte, closet, techo, piso… (para reparaciones)',
  `ruta` varchar(255) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `subido_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recfoto_ref` (`nivel`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
