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
