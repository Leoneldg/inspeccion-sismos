-- =====================================================================
-- REPRESENTANTES POR PARROQUIA (Fase 1 de Reconstrucción)
-- ---------------------------------------------------------------------
-- Sustituye el modelo anterior de "asignar un ente a cada edificio".
-- Ahora los representantes se asignan a la PARROQUIA, y puede haber
-- UNO O MÁS por parroquia. Todos los edificios de esa parroquia quedan
-- bajo la responsabilidad de esos representantes.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `representantes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(180) NOT NULL,
  `cedula` varchar(30) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `cargo` varchar(120) DEFAULT NULL COMMENT 'Cargo o rol del representante',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rep_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación representante <-> parroquia (muchos a muchos).
-- Una parroquia puede tener varios representantes y viceversa.
CREATE TABLE IF NOT EXISTS `representante_parroquia` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `representante_id` int(10) unsigned NOT NULL,
  `estado` varchar(100) NOT NULL COMMENT 'Estado de la parroquia',
  `municipio` varchar(120) DEFAULT NULL,
  `parroquia` varchar(120) NOT NULL,
  `asignado_por` int(10) unsigned DEFAULT NULL,
  `asignado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rep_parroquia` (`representante_id`,`estado`,`parroquia`),
  KEY `idx_rp_parroquia` (`estado`,`parroquia`),
  CONSTRAINT `fk_rp_representante` FOREIGN KEY (`representante_id`)
    REFERENCES `representantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
