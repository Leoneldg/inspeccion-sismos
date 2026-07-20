-- =====================================================================
-- CLASIFICAR RECETAS POR ETAPA (para que el Resumen Ejecutivo sume bien)
-- ---------------------------------------------------------------------
-- Verificación hecha contra el dump real (inspecciones_sismos.sql):
--
--   pared_completa_arcilla · Cemento gris = 0,45 saco/m²
--     = 0,14 (pega, igual que mamposteria_bloque_arcilla)
--     + 0,31 (friso dos caras, igual que friso_completo_dos_caras)
--   demolicion_parcial_arcilla · Cemento gris = 0,52 saco/m²
--     = 0,14 (pega) + 0,31 (friso dos caras) + 0,07 (empate, igual a
--       friso_reparacion) — el "empate" es el resane de la costura
--       entre lo nuevo y lo viejo, así que cuenta como revestimiento.
--
-- Los renglones de "Cemento gris" y "Arena lavada" de estos tres
-- trabajos traen la pega y el friso YA SUMADOS EN UN SOLO NÚMERO. Para
-- que el resumen ejecutivo pueda mostrar "tanto cemento va en
-- construcción" y "tanto va en revestimiento" por separado, hay que
-- partir ese renglón en dos, cada uno con su etapa.
--
-- Nota sobre "Agua": en demolicion_parcial_* el total no cuadra exacto
-- con pega+friso+empate (sobran ~4 L que no logro atribuir a un
-- componente conocido). Como el PDF ejecutivo nunca mostró agua en el
-- material (ni antes ni ahora es parte de esa caja), dejo esas filas
-- sin clasificar a propósito para que usted decida si las reparte o
-- las deja fuera. No afecta al cemento, la arena, los bloques ni la
-- pintura.
--
-- Ejecutar DESPUÉS de unificar_bloque_arcilla.sql (necesita la columna
-- `etapa` y que ya se haya decidido qué claves de concreto se
-- desactivaron).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) PARTIR "Cemento gris" y "Arena lavada" en pared_completa_arcilla,
--    demoler_pared_completa_arcilla y demolicion_parcial_arcilla.
--    El UPDATE deja la fila existente como la porción de CONSTRUCCIÓN
--    (pega); el INSERT agrega la porción de REVESTIMIENTO (friso +
--    empate si aplica). Los INSERT están protegidos con NOT EXISTS
--    para poder correr el script más de una vez sin duplicar filas.
-- ---------------------------------------------------------------------

-- pared_completa_arcilla · Cemento gris: 0,45 → 0,14 pega + 0,31 friso
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.1400, `etapa` = 'construccion',
       `nota` = 'Mortero de pega (porción de construcción; el friso quedó en otra fila)'
 WHERE `tipo_trabajo` = 'pared_completa_arcilla' AND `material` = 'Cemento gris' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'pared_completa_arcilla', 'Cemento gris', 'saco', 0.3100,
       'Friso dos caras (porción de revestimiento; la pega quedó en otra fila)', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'pared_completa_arcilla' AND material = 'Cemento gris' AND etapa = 'revestimiento'
 );

-- pared_completa_arcilla · Arena lavada: 0,069 → 0,023 pega + 0,046 friso
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.0230, `etapa` = 'construccion',
       `nota` = 'Pega (porción de construcción; el friso quedó en otra fila)'
 WHERE `tipo_trabajo` = 'pared_completa_arcilla' AND `material` = 'Arena lavada' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'pared_completa_arcilla', 'Arena lavada', 'm3', 0.0460,
       'Friso dos caras (porción de revestimiento; la pega quedó en otra fila)', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'pared_completa_arcilla' AND material = 'Arena lavada' AND etapa = 'revestimiento'
 );

-- demoler_pared_completa_arcilla · Cemento gris: 0,45 → 0,14 + 0,31
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.1400, `etapa` = 'construccion',
       `nota` = 'Mortero de pega (porción de construcción; el friso quedó en otra fila)'
 WHERE `tipo_trabajo` = 'demoler_pared_completa_arcilla' AND `material` = 'Cemento gris' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'demoler_pared_completa_arcilla', 'Cemento gris', 'saco', 0.3100,
       'Friso dos caras (porción de revestimiento; la pega quedó en otra fila)', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'demoler_pared_completa_arcilla' AND material = 'Cemento gris' AND etapa = 'revestimiento'
 );

-- demoler_pared_completa_arcilla · Arena lavada: 0,069 → 0,023 + 0,046
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.0230, `etapa` = 'construccion',
       `nota` = 'Pega (porción de construcción; el friso quedó en otra fila)'
 WHERE `tipo_trabajo` = 'demoler_pared_completa_arcilla' AND `material` = 'Arena lavada' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'demoler_pared_completa_arcilla', 'Arena lavada', 'm3', 0.0460,
       'Friso dos caras (porción de revestimiento; la pega quedó en otra fila)', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'demoler_pared_completa_arcilla' AND material = 'Arena lavada' AND etapa = 'revestimiento'
 );

-- demolicion_parcial_arcilla · Cemento gris: 0,52 → 0,14 pega + 0,38 revestimiento (0,31 friso + 0,07 empate)
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.1400, `etapa` = 'construccion',
       `nota` = 'Mortero de pega (porción de construcción; friso y empate quedaron en otra fila)'
 WHERE `tipo_trabajo` = 'demolicion_parcial_arcilla' AND `material` = 'Cemento gris' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'demolicion_parcial_arcilla', 'Cemento gris', 'saco', 0.3800,
       'Friso dos caras (0,31) + empate del borde (0,07), porción de revestimiento', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'demolicion_parcial_arcilla' AND material = 'Cemento gris' AND etapa = 'revestimiento'
 );

-- demolicion_parcial_arcilla · Arena lavada: 0,079 → 0,023 pega + 0,056 revestimiento (0,046 friso + 0,01 empate)
UPDATE `rec_receta_trabajo`
   SET `cantidad` = 0.0230, `etapa` = 'construccion',
       `nota` = 'Pega (porción de construcción; friso y empate quedaron en otra fila)'
 WHERE `tipo_trabajo` = 'demolicion_parcial_arcilla' AND `material` = 'Arena lavada' AND `activo` = 1;

INSERT INTO `rec_receta_trabajo` (`tipo_trabajo`, `material`, `unidad`, `cantidad`, `nota`, `etapa`, `activo`)
SELECT 'demolicion_parcial_arcilla', 'Arena lavada', 'm3', 0.0560,
       'Friso dos caras (0,046) + empate del borde (0,01), porción de revestimiento', 'revestimiento', 1
 WHERE NOT EXISTS (
   SELECT 1 FROM `rec_receta_trabajo`
    WHERE tipo_trabajo = 'demolicion_parcial_arcilla' AND material = 'Arena lavada' AND etapa = 'revestimiento'
 );

-- ---------------------------------------------------------------------
-- 2) Clasificar el resto de los renglones que NO necesitan partirse
--    (todo el importe va completo a una sola etapa).
-- ---------------------------------------------------------------------

-- Bloques y saco de escombro: construcción / demolición respectivamente.
UPDATE `rec_receta_trabajo`
   SET `etapa` = 'construccion'
 WHERE `material` LIKE 'Bloque de arcilla%' AND `activo` = 1 AND `etapa` IS NULL;

UPDATE `rec_receta_trabajo`
   SET `etapa` = 'demolicion'
 WHERE `material` = 'Saco para escombros' AND `activo` = 1 AND `etapa` IS NULL
   AND `tipo_trabajo` IN ('demoler_pared_completa_arcilla', 'demolicion_parcial_arcilla',
                           'demoler_reconstruir_arcilla', 'demolicion_mamposteria');

-- Pintura y fondo: siempre 100% revestimiento (no hay componente de pega).
UPDATE `rec_receta_trabajo`
   SET `etapa` = 'revestimiento'
 WHERE `material` IN ('Pintura de caucho', 'Fondo antialcalino') AND `activo` = 1 AND `etapa` IS NULL;

-- Trabajos que son SOLO friso/pintura (no tienen componente de pega):
-- todo su Cemento gris / Arena lavada es revestimiento completo.
UPDATE `rec_receta_trabajo`
   SET `etapa` = 'revestimiento'
 WHERE `tipo_trabajo` IN ('friso_completo', 'friso_completo_dos_caras', 'friso_reparacion',
                           'friso_pintura_una_cara', 'friso_pintura_dos_caras',
                           'solo_pintura', 'pintura')
   AND `material` IN ('Cemento gris', 'Arena lavada')
   AND `activo` = 1 AND `etapa` IS NULL;

-- Trabajos que son solo mampostería/reconstrucción sin revestimiento:
-- todo su Cemento gris / Arena lavada / Bloque es construcción.
UPDATE `rec_receta_trabajo`
   SET `etapa` = 'construccion'
 WHERE `tipo_trabajo` IN ('mamposteria_bloque_arcilla', 'demoler_reconstruir_arcilla')
   AND `material` IN ('Cemento gris', 'Arena lavada')
   AND `activo` = 1 AND `etapa` IS NULL;

-- ---------------------------------------------------------------------
-- 3) Verificación: lo que quede aquí son renglones que decidí NO
--    clasificar solo (el caso de "Agua" explicado arriba, y cualquier
--    material nuevo que usted agregue después). El PDF ejecutivo los
--    va a marcar con una advertencia en vez de ignorarlos en silencio.
-- ---------------------------------------------------------------------
SELECT tipo_trabajo, material, cantidad, unidad, etapa
  FROM `rec_receta_trabajo`
 WHERE activo = 1 AND etapa IS NULL
 ORDER BY tipo_trabajo, material;
