-- =====================================================================
-- CONSTANCIA DE EDIFICACIÓN SIN ETIQUETA
-- ---------------------------------------------------------------------
-- Algunas edificaciones no tienen la etiqueta pegada en la fachada.
-- Estas columnas permiten dejarlo asentado, para distinguir
-- "no tiene etiqueta" de "se les olvidó tomar la foto".
--
-- El sistema crea estas columnas solo si hace falta, así que ejecutar
-- este archivo es opcional. Si alguna ya existe, MySQL dará error 1060:
-- ignórelo y siga con la siguiente.
-- =====================================================================

ALTER TABLE `rec_edificio`
  ADD COLUMN `sin_etiqueta` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'La edificación no tiene etiqueta en la fachada';

ALTER TABLE `rec_edificio`
  ADD COLUMN `etiqueta_motivo` varchar(60) DEFAULT NULL
  COMMENT 'Por qué no tiene etiqueta';

ALTER TABLE `rec_edificio`
  ADD COLUMN `etiqueta_obs` varchar(300) DEFAULT NULL
  COMMENT 'Observación sobre la etiqueta';

-- Verificación
SELECT COUNT(*) AS edificaciones_sin_etiqueta
  FROM `rec_edificio`
 WHERE `sin_etiqueta` = 1;
