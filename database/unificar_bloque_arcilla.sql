-- =====================================================================
-- UNIFICAR BLOQUE DE CONCRETO Y BLOQUE DE ARCILLA
-- ---------------------------------------------------------------------
-- El sistema ya no distingue "bloque de concreto" de "bloque de
-- arcilla" como dos tipos de trabajo separados: de aquí en adelante
-- toda pared se registra como arcilla. Este script:
--
--   1) Agrega la columna `etapa` a rec_receta_trabajo, que es la que
--      ahora usa el Resumen Ejecutivo (pdf_ejecutivo / pdf_materiales)
--      para saber a qué caja (demolición/construcción/revestimiento)
--      pertenece cada material de la receta editable en
--      Admin > Materiales. El código ya la agrega solo la primera vez
--      que se abre cualquier pantalla de Seguimiento, así que este
--      paso es un respaldo por si prefiere aplicarlo a mano primero.
--   2) Migra los levantamientos YA REGISTRADOS con tipo_trabajo
--      "*_concreto" para que cuenten como "*_arcilla" (no se pierde
--      el dato, solo se reclasifica).
--   3) Desactiva los tipos de trabajo y recetas "*_concreto" del
--      catálogo, para que dejen de aparecer como opción nueva.
--
-- Ejecute los bloques EN ORDEN. Si un ALTER da error 1060 (columna ya
-- existe), es que ya se aplicó: ignórelo y siga con el siguiente.
-- =====================================================================

-- 1) Columna etapa (por si esta instalación no la generó todavía).
ALTER TABLE `rec_receta_trabajo`
  ADD COLUMN `etapa` ENUM('demolicion','construccion','revestimiento') DEFAULT NULL
  COMMENT 'A qué caja del resumen ejecutivo pertenece este material';

-- 2) Historial: los trabajos ya registrados en edificaciones pasan a
--    contar como arcilla.
UPDATE `rec_reparacion`
   SET `tipo_trabajo` = REPLACE(`tipo_trabajo`, '_concreto', '_arcilla')
 WHERE `tipo_trabajo` LIKE '%\_concreto';

-- Verificación: no debe quedar ningún renglón con "_concreto".
SELECT COUNT(*) AS reparaciones_aun_en_concreto
  FROM `rec_reparacion`
 WHERE `tipo_trabajo` LIKE '%\_concreto';

-- 3) Revise primero si hay choque de claves antes de fusionar: es
--    normal que YA existan ambas ("pared_completa_concreto" y
--    "pared_completa_arcilla") porque convivían como opciones del
--    formulario.
SELECT clave, nombre, activo FROM `rec_tipo_trabajo` WHERE clave LIKE '%\_concreto';

-- 3a) Si ya existe la contraparte en arcilla, la de concreto solo se
--     desactiva (la de arcilla la reemplaza; no se renombra para no
--     chocar con la UNIQUE KEY de `clave`).
UPDATE `rec_tipo_trabajo` tc
  INNER JOIN `rec_tipo_trabajo` ta
          ON ta.clave = REPLACE(tc.clave, '_concreto', '_arcilla')
   SET tc.activo = 0
 WHERE tc.clave LIKE '%\_concreto';

-- 3b) Si NO existe contraparte en arcilla (caso menos común: el
--     equipo solo había creado la variante de concreto), se renombra
--     esa fila para que pase a ser la de arcilla.
UPDATE `rec_tipo_trabajo` tc
   SET tc.clave  = REPLACE(tc.clave, '_concreto', '_arcilla'),
       tc.nombre = REPLACE(tc.nombre, 'concreto', 'arcilla'),
       tc.descripcion = REPLACE(COALESCE(tc.descripcion, ''), 'concreto', 'arcilla')
 WHERE tc.clave LIKE '%\_concreto'
   AND tc.activo = 1;

-- 4) Recetas de los tipos de concreto que quedaron desactivados: se
--    apagan también. OJO: sus cantidades NO se copian automáticamente
--    a la receta de arcilla porque los rendimientos de concreto y
--    arcilla no son iguales (bloque de concreto 15x20x40 vs bloque de
--    arcilla 10x20x30). Revise en Configuración > Materiales que la
--    receta de cada "*_arcilla" tenga los renglones que necesita, y
--    clasifíquelos por etapa (columna nueva) para que el Resumen
--    Ejecutivo los sume correctamente.
UPDATE `rec_receta_trabajo`
   SET `activo` = 0
 WHERE `tipo_trabajo` LIKE '%\_concreto';

-- Verificación final: no debería quedar ningún tipo/receta "concreto" activo.
SELECT clave, nombre, activo FROM `rec_tipo_trabajo` WHERE clave LIKE '%concreto%';
SELECT tipo_trabajo, material, etapa, activo FROM `rec_receta_trabajo` WHERE tipo_trabajo LIKE '%concreto%';
