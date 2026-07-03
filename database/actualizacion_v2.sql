-- =====================================================================
-- Actualización v1 -> v2
-- Ejecute este script SOLO si ya tenía el sistema instalado antes de
-- las siguientes funcionalidades: registro fotográfico por inspección.
--
-- Si es una instalación NUEVA, no necesita este archivo: schema.sql ya
-- incluye la tabla inspeccion_fotos.
-- =====================================================================
USE inspecciones_sismos;

CREATE TABLE IF NOT EXISTS inspeccion_fotos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspeccion_id   INT UNSIGNED NOT NULL,
    categoria       VARCHAR(60) NOT NULL,
    ruta            VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) DEFAULT NULL,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_foto_inspeccion FOREIGN KEY (inspeccion_id) REFERENCES inspecciones(id) ON DELETE CASCADE,
    INDEX idx_foto_inspeccion (inspeccion_id, categoria)
) ENGINE=InnoDB;
