-- =====================================================================
-- FASE 2 · INTERVENCIÓN
--
-- Bitácora de ejecución del plan que dejó el levantamiento técnico.
-- La aplicación crea esta tabla sola la primera vez que se usa el Modo
-- campo (intvAsegurarTablas), así que correr este archivo es opcional:
-- sirve para instalaciones donde el usuario de la base no tiene permiso
-- de CREATE, o para revisar el esquema.
--
-- Por qué NO se guarda rec_reparacion.id
-- --------------------------------------
-- recGuardarReparaciones() borra TODAS las filas de una partida y las
-- vuelve a insertar cada vez que alguien guarda el levantamiento. Los
-- ids cambian en cada guardado, así que enganchar el historial ahí lo
-- dejaría huérfano a la primera corrección. Se usa la clave natural:
-- nivel + ref_id + tipo_superficie + tipo_trabajo.
--
-- Las fotos de cada asiento van en rec_foto con:
--   nivel = 'reporte_intervencion', ref_id = rec_interv_reporte.id,
--   parte = 'durante' | 'despues'
-- =====================================================================

CREATE TABLE IF NOT EXISTS rec_interv_reporte (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    edificio_id     INT UNSIGNED NOT NULL,
    nivel           ENUM('ambiente','area_comun','elemento_piso') NOT NULL DEFAULT 'ambiente',
    ref_id          INT UNSIGNED NOT NULL,
    tipo_superficie VARCHAR(20)  NOT NULL DEFAULT '',
    tipo_trabajo    VARCHAR(60)  NOT NULL DEFAULT '',
    fase            ENUM('durante','despues') NOT NULL,
    fecha           DATE NOT NULL,
    observaciones   VARCHAR(400) DEFAULT NULL,
    reportado_por   INT UNSIGNED DEFAULT NULL,
    creado_en       DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    -- Un asiento por partida, fase y día: varias fotos del mismo día
    -- entran al mismo registro de la bitácora.
    UNIQUE KEY uq_interv_dia (nivel, ref_id, tipo_superficie, tipo_trabajo, fase, fecha),
    KEY idx_interv_ed (edificio_id),
    KEY idx_interv_partida (nivel, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
