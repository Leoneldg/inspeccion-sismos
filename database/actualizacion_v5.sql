-- =====================================================================
-- Actualización v5
-- Agrega un rol "Superadministrador" con acceso a un nuevo módulo de
-- Configuración del Sistema, desde donde puede:
--   a) activar/desactivar secciones opcionales del formulario, y
--   b) controlar qué "widgets" del dashboard se muestran, su orden,
--      su color y si usan degradado.
-- Solo AGREGA; no modifica ni elimina nada de lo existente.
-- =====================================================================

USE inspecciones_sismos;

-- ---------------------------------------------------------------------
-- 1. Nuevo módulo: Configuración del Sistema
-- ---------------------------------------------------------------------
INSERT IGNORE INTO modulos (clave, nombre, icono, orden) VALUES
('configuracion', 'Configuración del Sistema', 'bi-sliders', 5);

-- ---------------------------------------------------------------------
-- 2. Nuevo rol: Superadministrador
--    (rol base, no eliminable; con control total incluyendo personalización)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO roles (nombre, descripcion, es_sistema) VALUES
('Superadministrador', 'Control total del sistema, incluida la personalización del formulario y el dashboard', 1);

-- El Superadministrador recibe acceso total a TODOS los módulos existentes
-- (incluido el nuevo "configuracion"), igual que Administrador.
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Superadministrador'), m.id, 1, 1, 1, 1
FROM modulos m
WHERE NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp
    WHERE rmp.rol_id = (SELECT id FROM roles WHERE nombre='Superadministrador') AND rmp.modulo_id = m.id
);

-- Por si Administrador ya existía sin el módulo "configuracion" en su matriz
-- de permisos (los administradores igual reciben acceso automático a todos
-- los módulos por código, pero se deja explícito aquí también).
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Administrador'), (SELECT id FROM modulos WHERE clave='configuracion'), 1,1,1,1
WHERE NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp
    WHERE rmp.rol_id = (SELECT id FROM roles WHERE nombre='Administrador')
      AND rmp.modulo_id = (SELECT id FROM modulos WHERE clave='configuracion')
);

-- ---------------------------------------------------------------------
-- 3. Tabla de configuración del panel (clave/valor JSON, genérica)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS panel_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(60)  NOT NULL UNIQUE,
    valor           JSON         NOT NULL,
    actualizado_por INT UNSIGNED DEFAULT NULL,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_panelconfig_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Valores por defecto: todas las secciones del formulario activas,
-- y los widgets del dashboard visibles en su orden original sin color
-- personalizado (se completan también en código si faltara alguna clave).
INSERT IGNORE INTO panel_config (clave, valor) VALUES
('formulario_secciones', JSON_OBJECT(
    'planilla_header', TRUE,
    'anio_personas', TRUE,
    'materiales_extendidos', TRUE,
    'riesgo_externo', TRUE,
    'piso_critico', TRUE,
    'dano_moderado_piso_critico', TRUE,
    'riesgo_componentes', TRUE,
    'acciones_recomendadas', TRUE
)),
('dashboard_widgets', JSON_ARRAY(
    JSON_OBJECT('id','kpi_inspecciones','visible',TRUE,'orden',1,'color',NULL,'color2',NULL,'gradiente',FALSE),
    JSON_OBJECT('id','kpi_personas','visible',TRUE,'orden',2,'color',NULL,'color2',NULL,'gradiente',FALSE),
    JSON_OBJECT('id','kpi_grid','visible',TRUE,'orden',3,'color',NULL,'color2',NULL,'gradiente',FALSE),
    JSON_OBJECT('id','chart_decision','visible',TRUE,'orden',4,'color',NULL,'color2',NULL,'gradiente',FALSE),
    JSON_OBJECT('id','mapa','visible',TRUE,'orden',5,'color',NULL,'color2',NULL,'gradiente',FALSE),
    JSON_OBJECT('id','chart_parroquia','visible',TRUE,'orden',6,'color',NULL,'color2',NULL,'gradiente',FALSE)
));
