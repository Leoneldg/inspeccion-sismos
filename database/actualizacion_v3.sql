-- Actualización v3: deduplicación de envíos del formulario.
--
-- Soluciona el caso en que, en producción, un envío del formulario tarda
-- más de lo que el navegador está dispuesto a esperar (o se corta la señal
-- justo después de que el servidor ya guardó todo): el modo offline lo
-- reintenta más tarde, y sin esto se podía crear una inspección duplicada.
--
-- Ejecutar sobre una instalación existente:
--   mysql -u root -p inspecciones_sismos < database/actualizacion_v3.sql

CREATE TABLE IF NOT EXISTS envios_formulario (
    client_submission_id VARCHAR(64) NOT NULL,
    inspeccion_id         INT UNSIGNED NOT NULL,
    creado_en              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_submission_id),
    KEY idx_envios_inspeccion (inspeccion_id),
    CONSTRAINT fk_envios_inspeccion FOREIGN KEY (inspeccion_id)
        REFERENCES inspecciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
