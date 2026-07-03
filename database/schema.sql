-- =====================================================================
-- Sistema de Inspección de Edificaciones Post-Sismo
-- Esquema de base de datos MySQL
-- =====================================================================
-- Cargar con:  mysql -u root -p < schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS inspecciones_sismos
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inspecciones_sismos;

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS inspeccion_fotos;
DROP TABLE IF EXISTS rol_modulo_permisos;
DROP TABLE IF EXISTS inspecciones;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS modulos;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS log_actividad;

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(60)  NOT NULL UNIQUE,
    descripcion     VARCHAR(255) DEFAULT NULL,
    es_sistema      TINYINT(1)   NOT NULL DEFAULT 0, -- roles base que no se pueden borrar
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Módulos del sistema (extensible)
-- ---------------------------------------------------------------------
CREATE TABLE modulos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(40)  NOT NULL UNIQUE,   -- 'formulario' | 'dashboard' | 'usuarios'
    nombre          VARCHAR(100) NOT NULL,
    icono           VARCHAR(60)  DEFAULT NULL,
    orden           INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Permisos de cada rol sobre cada módulo (ver / crear / editar / eliminar)
-- ---------------------------------------------------------------------
CREATE TABLE rol_modulo_permisos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rol_id          INT UNSIGNED NOT NULL,
    modulo_id       INT UNSIGNED NOT NULL,
    ver             TINYINT(1) NOT NULL DEFAULT 0,
    crear           TINYINT(1) NOT NULL DEFAULT 0,
    editar          TINYINT(1) NOT NULL DEFAULT 0,
    eliminar        TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_rol_modulo (rol_id, modulo_id),
    CONSTRAINT fk_rmp_rol    FOREIGN KEY (rol_id)    REFERENCES roles(id)    ON DELETE CASCADE,
    CONSTRAINT fk_rmp_modulo FOREIGN KEY (modulo_id) REFERENCES modulos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Usuarios
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL,
    usuario         VARCHAR(60)  NOT NULL UNIQUE,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    rol_id          INT UNSIGNED NOT NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_acceso   DATETIME     DEFAULT NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Inspecciones (instrumento de inspección post-sismo)
-- Campos "core" normalizados (usados en el dashboard y filtros) +
-- columnas JSON para secciones extensas del instrumento original,
-- de forma que el formulario pueda capturar el 100% de los campos
-- del instrumento sin explotar el número de columnas físicas.
-- ---------------------------------------------------------------------
CREATE TABLE inspecciones (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo                      VARCHAR(20)  NOT NULL UNIQUE, -- INS-2026-000001

    -- Profesional responsable 1 (obligatorio)
    ing1_nombre                 VARCHAR(150) NOT NULL,
    ing1_cedula                 VARCHAR(20)  NOT NULL,
    ing1_telefono               VARCHAR(20)  DEFAULT NULL,
    ing1_profesion              VARCHAR(100) DEFAULT NULL,
    ing1_inscripcion            VARCHAR(50)  DEFAULT NULL,

    -- Profesional responsable 2 (opcional)
    ing2_nombre                 VARCHAR(150) DEFAULT NULL,
    ing2_cedula                 VARCHAR(20)  DEFAULT NULL,
    ing2_telefono               VARCHAR(20)  DEFAULT NULL,
    ing2_profesion              VARCHAR(100) DEFAULT NULL,
    ing2_inscripcion            VARCHAR(50)  DEFAULT NULL,

    -- Identificación de la edificación
    nombre_edificio             VARCHAR(200) NOT NULL,
    fecha_inspeccion            DATE         NOT NULL,
    hora_inicio                 TIME         DEFAULT NULL,
    hora_culminacion            TIME         DEFAULT NULL,
    cantidad_apartamentos       SMALLINT UNSIGNED DEFAULT 0,
    num_pisos                   SMALLINT UNSIGNED DEFAULT 0,
    num_semisotanos             SMALLINT UNSIGNED DEFAULT 0,
    num_sotanos                 SMALLINT UNSIGNED DEFAULT 0,

    -- Ubicación
    estado                      VARCHAR(100) DEFAULT NULL,
    ciudad                      VARCHAR(100) DEFAULT NULL,
    municipio                   VARCHAR(100) DEFAULT NULL,
    parroquia                   VARCHAR(100) NOT NULL,
    comuna_circuito              VARCHAR(150) DEFAULT NULL,
    urbanizacion                VARCHAR(150) DEFAULT NULL,
    sector                      VARCHAR(150) DEFAULT NULL,
    avenida_calle                VARCHAR(200) DEFAULT NULL,
    nombre_comunidad             VARCHAR(150) DEFAULT NULL,
    coordenadas_utm              VARCHAR(100) DEFAULT NULL,
    huso                        VARCHAR(10)  DEFAULT NULL,
    latitud                      DECIMAL(11,7) DEFAULT NULL,
    longitud                     DECIMAL(11,7) DEFAULT NULL,

    -- Características constructivas
    uso_edificacion              VARCHAR(100) DEFAULT NULL,
    tipo_estructural              VARCHAR(100) DEFAULT NULL,
    material_acero               TINYINT(1) NOT NULL DEFAULT 0,
    material_conexiones          TINYINT(1) NOT NULL DEFAULT 0,
    material_mamposteria         TINYINT(1) DEFAULT 0,
    material_otros                TINYINT(1) NOT NULL DEFAULT 0,
    material_otros_especifique   VARCHAR(255) DEFAULT NULL,

    -- Evaluación de riesgo general
    colapso_estructura           ENUM('No','Parcial','Total') DEFAULT 'No',
    riesgo_edificios_aledanos    VARCHAR(20) DEFAULT NULL, -- Si / No
    amenaza_geologica            VARCHAR(20) DEFAULT NULL,
    asentamiento_edificio        VARCHAR(20) DEFAULT NULL,
    inclinacion_edificio         VARCHAR(20) DEFAULT NULL,
    requiere_inspeccion_interna  ENUM('Si','No') DEFAULT 'No',

    -- Daño en elementos estructurales (columna, viga, muro, nodo, losa, mampostería)
    -- Cada uno con nivel de daño I-V. Guardado como JSON: {"columna":"II", "viga":"I", ...}
    danos_estructurales           JSON DEFAULT NULL,
    requiere_intervencion         ENUM('Si','No') DEFAULT 'No',
    pct_dano_iii                  DECIMAL(5,2) DEFAULT 0,
    pct_dano_iv                   DECIMAL(5,2) DEFAULT 0,
    pct_dano_v                    DECIMAL(5,2) DEFAULT 0,

    -- Daño en elementos no estructurales (paredes, escaleras, tanques/balcones, fachada, etc.)
    danos_no_estructurales        JSON DEFAULT NULL,

    -- Personas y animales afectados (KPIs del dashboard)
    familias                      INT UNSIGNED NOT NULL DEFAULT 0,
    ninos                         INT UNSIGNED NOT NULL DEFAULT 0,
    mujeres                       INT UNSIGNED NOT NULL DEFAULT 0,
    hombres                       INT UNSIGNED NOT NULL DEFAULT 0,
    adultos_tercera_edad          INT UNSIGNED NOT NULL DEFAULT 0,
    gestantes                     INT UNSIGNED NOT NULL DEFAULT 0,
    movilidad_reducida            INT UNSIGNED NOT NULL DEFAULT 0,
    mascotas                      INT UNSIGNED NOT NULL DEFAULT 0,

    -- Decisión final (etiqueta ATC-20 / semaforización del dashboard)
    decision_final ENUM(
        'Edificación Inspeccionada - Acceso Permitido',
        'Acceso Restringido - Precaución al Entrar',
        'Edificación Insegura - Acceso No Permitido'
    ) NOT NULL DEFAULT 'Edificación Inspeccionada - Acceso Permitido',

    inspeccion_previa_etiqueta    VARCHAR(100) DEFAULT NULL,
    inspeccion_especializada     VARCHAR(20) DEFAULT NULL,
    intervencion_de               VARCHAR(150) DEFAULT NULL,
    medidas_seguridad             TEXT,
    m2_losas                      DECIMAL(10,2) DEFAULT NULL,
    muros_reconstruir             INT UNSIGNED DEFAULT NULL,
    lugares_medidas               TEXT,
    observaciones                 TEXT,
    recomendaciones                TEXT,

    -- Resto de secciones del instrumento original (ascensores, tanques,
    -- servicios afectados, propiedad horizontal, reparaciones, valoraciones
    -- detalladas por elemento, profesionales adicionales, etc.)
    datos_adicionales              JSON DEFAULT NULL,

    -- Auditoría
    creado_por                    INT UNSIGNED DEFAULT NULL,
    actualizado_por                INT UNSIGNED DEFAULT NULL,
    creado_en                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_insp_creado_por     FOREIGN KEY (creado_por)     REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_insp_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,

    INDEX idx_parroquia (parroquia),
    INDEX idx_decision (decision_final),
    INDEX idx_fecha (fecha_inspeccion)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Registro fotográfico por inspección
-- ---------------------------------------------------------------------
CREATE TABLE inspeccion_fotos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspeccion_id   INT UNSIGNED NOT NULL,
    categoria       VARCHAR(60) NOT NULL, -- ver catalogoCategoriasFoto() en functions.php
    ruta            VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) DEFAULT NULL,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_foto_inspeccion FOREIGN KEY (inspeccion_id) REFERENCES inspecciones(id) ON DELETE CASCADE,
    INDEX idx_foto_inspeccion (inspeccion_id, categoria)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Bitácora de actividad (auditoría de acciones sensibles)
-- ---------------------------------------------------------------------
CREATE TABLE log_actividad (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED DEFAULT NULL,
    accion      VARCHAR(100) NOT NULL,
    detalle     VARCHAR(255) DEFAULT NULL,
    ip          VARCHAR(45) DEFAULT NULL,
    creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- DATOS INICIALES (seed)
-- =====================================================================

INSERT INTO roles (nombre, descripcion, es_sistema) VALUES
('Administrador', 'Acceso total al sistema y gestión de usuarios', 1),
('Inspector',      'Puede registrar y editar inspecciones (formulario)', 1),
('Supervisor',     'Solo consulta del dashboard y reportes', 1);

INSERT INTO modulos (clave, nombre, icono, orden) VALUES
('dashboard',  'Dashboard',        'bi-bar-chart-line', 1),
('formulario', 'Formulario de Inspección', 'bi-clipboard-check', 2),
('usuarios',   'Usuarios y Permisos', 'bi-people-fill', 3);

-- Módulo para importación y exportación de datos (agregado por feature import/export)
INSERT IGNORE INTO modulos (clave, nombre, icono, orden) VALUES
('import_export', 'Importar / Exportar', 'bi-upload', 4);

-- Administrador: todo en todos los módulos
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Administrador'), id, 1, 1, 1, 1 FROM modulos;

-- Asegurar que el nuevo módulo import_export tenga permisos para Administrador (por si no existía en el momento anterior)
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT (SELECT id FROM roles WHERE nombre='Administrador'), (SELECT id FROM modulos WHERE clave='import_export'), 1,1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol_modulo_permisos rmp JOIN modulos m ON m.id=rmp.modulo_id WHERE rmp.rol_id=(SELECT id FROM roles WHERE nombre='Administrador') AND m.clave='import_export');

-- Inspector: formulario completo, dashboard solo lectura, sin usuarios
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES
((SELECT id FROM roles WHERE nombre='Inspector'), (SELECT id FROM modulos WHERE clave='formulario'), 1,1,1,0),
((SELECT id FROM roles WHERE nombre='Inspector'), (SELECT id FROM modulos WHERE clave='dashboard'),  1,0,0,0),
((SELECT id FROM roles WHERE nombre='Inspector'), (SELECT id FROM modulos WHERE clave='usuarios'),   0,0,0,0);

-- Supervisor: solo dashboard
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES
((SELECT id FROM roles WHERE nombre='Supervisor'), (SELECT id FROM modulos WHERE clave='formulario'), 0,0,0,0),
((SELECT id FROM roles WHERE nombre='Supervisor'), (SELECT id FROM modulos WHERE clave='dashboard'),  1,0,0,0),
((SELECT id FROM roles WHERE nombre='Supervisor'), (SELECT id FROM modulos WHERE clave='usuarios'),   0,0,0,0);

-- Usuario administrador inicial -> usuario: admin  /  clave: Admin#2026
-- (hash bcrypt generado con password_hash(), cámbiela después del primer ingreso)
INSERT INTO usuarios (nombre_completo, usuario, email, password_hash, rol_id, activo) VALUES
('Administrador del Sistema', 'admin', 'admin@inspecciones.local',
 '$2y$10$JIugW.MdGP0EV0AhuLK6P.8YzFwc9zMQ666RrO8ygs7QXIOOEBdzK', -- clave: Admin#2026
 (SELECT id FROM roles WHERE nombre='Administrador'), 1);

-- Usuarios de demostración (opcional, puede eliminarlos desde el módulo de Usuarios)
INSERT INTO usuarios (nombre_completo, usuario, email, password_hash, rol_id, activo) VALUES
('Ingeniero Inspector Demo', 'inspector', 'inspector@inspecciones.local',
 '$2y$10$Z4qcGsIalrkKIFWivvQfD.sXsWca7f8r11CBUTVqtNzv1T/gVyM8W', -- clave: Inspector#2026
 (SELECT id FROM roles WHERE nombre='Inspector'), 1),
('Supervisor de Sala Demo', 'supervisor', 'supervisor@inspecciones.local',
 '$2y$10$.K60.qM946mzmV5BKjq6mOgdFk9Gen1MZZslW8xLf4UFO8XeJsXO.', -- clave: Supervisor#2026
 (SELECT id FROM roles WHERE nombre='Supervisor'), 1);
