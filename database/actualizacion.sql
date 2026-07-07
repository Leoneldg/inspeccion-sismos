-- =====================================================================
-- Actualización: alcance estadal en módulos administrativos +
-- rework del módulo de Seguimiento (consumo de recursos, entes por usuario)
-- =====================================================================
-- SOLO para instalaciones que YA tenían una versión anterior cargada.
-- En una instalación nueva NO se usa: schema.sql ya incluye estas columnas.
--
--   mysql -u root -p inspecciones_sismos < database/actualizacion.sql
--
-- Es idempotente: se puede correr más de una vez sin error.
-- =====================================================================

USE `inspecciones_sismos`;

-- 1) ingenieros.estado (para dar alcance estadal al directorio de profesionales)
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'ingenieros' AND column_name = 'estado');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `ingenieros` ADD COLUMN `estado` VARCHAR(100) DEFAULT NULL COMMENT ''Estado del profesional (alcance nacional)'' AFTER `activo`, ADD KEY `idx_ingeniero_estado` (`estado`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) usuarios.ente_id (pertenencia del usuario a un ente, para Seguimiento)
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'ente_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `usuarios` ADD COLUMN `ente_id` INT(10) UNSIGNED DEFAULT NULL COMMENT ''Ente al que pertenece (para el modulo de seguimiento)'' AFTER `estado_asignado`, ADD KEY `fk_usuario_ente` (`ente_id`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) FK usuarios.ente_id -> entes.id (solo si la tabla entes existe y la FK no)
SET @existeFk := (SELECT COUNT(*) FROM information_schema.table_constraints
                  WHERE table_schema = DATABASE() AND constraint_name = 'fk_usuario_ente');
SET @existeEntes := (SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'entes');
SET @sql := IF(@existeFk = 0 AND @existeEntes = 1,
    'ALTER TABLE `usuarios` ADD CONSTRAINT `fk_usuario_ente` FOREIGN KEY (`ente_id`) REFERENCES `entes` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- =====================================================================
-- 4) inspecciones.ente_id — ente "dueño" de cada inspección, deducido del
--    usuario que la creó. Permite aislar los datos por ente sin recalcular
--    con JOINs en cada consulta.
-- =====================================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'inspecciones' AND column_name = 'ente_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `inspecciones` ADD COLUMN `ente_id` INT(10) UNSIGNED DEFAULT NULL COMMENT ''Ente dueño (del usuario creador)'' AFTER `creado_por`, ADD KEY `idx_inspeccion_ente` (`ente_id`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Rellenar el ente de las inspecciones existentes a partir del ente del
-- usuario que las creó (solo las que aún no lo tienen).
UPDATE `inspecciones` i
  JOIN `usuarios` u ON u.id = i.creado_por
  SET i.ente_id = u.ente_id
  WHERE i.ente_id IS NULL AND u.ente_id IS NOT NULL;

-- 5) ingenieros.ente_id — ente dueño de cada profesional (el del creador).
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'ingenieros' AND column_name = 'ente_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE `ingenieros` ADD COLUMN `ente_id` INT(10) UNSIGNED DEFAULT NULL COMMENT ''Ente dueño del profesional'' AFTER `estado`, ADD KEY `idx_ingeniero_ente` (`ente_id`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE `ingenieros` g
  JOIN `usuarios` u ON u.id = g.creado_por
  SET g.ente_id = u.ente_id
  WHERE g.ente_id IS NULL AND u.ente_id IS NOT NULL;

-- 6) Catálogo de profesiones (para elegir/crear como el selector de inspector
--    y poder filtrar/contar profesionales por profesión).
CREATE TABLE IF NOT EXISTS `profesiones` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profesion_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sembrar el catálogo con las profesiones que ya existen en el directorio.
INSERT IGNORE INTO `profesiones` (`nombre`)
  SELECT DISTINCT TRIM(`profesion`) FROM `ingenieros`
  WHERE `profesion` IS NOT NULL AND TRIM(`profesion`) <> '';

-- =====================================================================
-- 7) Restablecer la configuración del dashboard para que TODOS los widgets
--    (indicadores, gráficos y mapa) vuelvan a estar visibles. Al borrar la
--    clave, el dashboard usa los valores por defecto (todo visible). Esto
--    corrige el estado en el que solo se veía el mapa.
-- =====================================================================
DELETE FROM `panel_config` WHERE `clave` = 'dashboard_widgets';
