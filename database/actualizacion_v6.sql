-- =====================================================================
-- Actualización v6
-- 1) Módulo "Ingenieros/Inspectores" (directorio de profesionales
--    registrados: foto, cédula, nombre, teléfono, colegio de ingenieros).
-- 2) El formulario ahora referencia a un ingeniero registrado (ing1_id,
--    ing2_id) en vez de solo texto libre; se conservan las columnas de
--    texto existentes (ing1_nombre, etc.) por compatibilidad con lo ya
--    guardado y con el resto del sistema (PDF, vistas, etc.), que se
--    siguen llenando automáticamente al elegir el ingeniero.
-- 3) Campo "¿Tiene tanque de agua?" (para poder filtrar la lista de
--    inspecciones), separado del checkbox de "daños en aguas".
-- Solo AGREGA; no modifica ni elimina nada de lo existente.
-- =====================================================================

USE inspecciones_sismos;

-- ---------------------------------------------------------------------
-- 1. Directorio de ingenieros/inspectores/arquitectos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingenieros (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre_completo     VARCHAR(150) NOT NULL,
    cedula              VARCHAR(20)  NOT NULL UNIQUE,
    telefono            VARCHAR(20)  DEFAULT NULL,
    profesion           VARCHAR(100) DEFAULT NULL,
    colegio_inscripcion VARCHAR(50)  DEFAULT NULL, -- opcional
    foto                VARCHAR(255) DEFAULT NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    creado_por          INT UNSIGNED DEFAULT NULL,
    creado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ingeniero_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_ingeniero_activo (activo)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Módulo y permisos para el nuevo directorio de ingenieros
-- ---------------------------------------------------------------------
INSERT IGNORE INTO modulos (clave, nombre, icono, orden) VALUES
('ingenieros', 'Ingenieros / Inspectores', 'bi-person-vcard-fill', 6);

-- Administrador y Superadministrador: acceso total
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, (SELECT id FROM modulos WHERE clave='ingenieros'), 1, 1, 1, 1
FROM roles r
WHERE r.nombre IN ('Administrador', 'Superadministrador')
  AND NOT EXISTS (
      SELECT 1 FROM rol_modulo_permisos rmp
      WHERE rmp.rol_id = r.id AND rmp.modulo_id = (SELECT id FROM modulos WHERE clave='ingenieros')
  );

-- Inspector: puede ver y agregar ingenieros (los necesita para el formulario),
-- y editarlos, pero no eliminarlos
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Inspector'), (SELECT id FROM modulos WHERE clave='ingenieros'), 1, 1, 1, 0
WHERE NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp
    WHERE rmp.rol_id = (SELECT id FROM roles WHERE nombre='Inspector')
      AND rmp.modulo_id = (SELECT id FROM modulos WHERE clave='ingenieros')
);

-- Supervisor: sin acceso
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Supervisor'), (SELECT id FROM modulos WHERE clave='ingenieros'), 0, 0, 0, 0
WHERE NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp
    WHERE rmp.rol_id = (SELECT id FROM roles WHERE nombre='Supervisor')
      AND rmp.modulo_id = (SELECT id FROM modulos WHERE clave='ingenieros')
);

-- ---------------------------------------------------------------------
-- 3. Referencia desde la inspección al ingeniero registrado (además de
--    las columnas de texto que ya existían, que se siguen llenando).
-- ---------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspecciones' AND COLUMN_NAME = 'ing1_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE inspecciones
        ADD COLUMN ing1_id INT UNSIGNED DEFAULT NULL AFTER ing1_inscripcion,
        ADD COLUMN ing2_id INT UNSIGNED DEFAULT NULL AFTER ing2_inscripcion,
        ADD COLUMN tiene_tanque_agua TINYINT(1) DEFAULT NULL AFTER datos_adicionales',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Las FK se agregan en un paso aparte porque no se puede meter "ADD
-- CONSTRAINT ... FOREIGN KEY" dentro del IF anterior sin repetir toda la
-- lógica; MySQL sí permite agregarla directo con IF NOT EXISTS a partir
-- de 8.0.29 en columnas, pero las constraints de FK no tienen esa forma,
-- así que se protege por nombre de constraint.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inspecciones' AND CONSTRAINT_NAME = 'fk_insp_ing1'
);
SET @sql2 := IF(@fk_exists = 0,
    'ALTER TABLE inspecciones
        ADD CONSTRAINT fk_insp_ing1 FOREIGN KEY (ing1_id) REFERENCES ingenieros(id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_insp_ing2 FOREIGN KEY (ing2_id) REFERENCES ingenieros(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
