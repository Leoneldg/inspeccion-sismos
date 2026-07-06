-- =====================================================================
-- Actualización v4
-- Alinea el instrumento con la planilla física "Evaluación Rápida de
-- Daños en Edificaciones" (Fundación Venezolana de Investigaciones
-- Sismológicas / Min. P.P. Relaciones Interiores, Justicia y Paz).
-- Solo AGREGA columnas que no existían; no modifica ni elimina nada
-- de lo ya guardado.
-- =====================================================================

USE inspecciones_sismos;

-- ---------------------------------------------------------------------
-- 1. INFORMACIÓN GENERAL (encabezado de la planilla)
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN planilla_numero      VARCHAR(30)       DEFAULT NULL AFTER codigo,
    ADD COLUMN tipo_evento          VARCHAR(150)      DEFAULT NULL AFTER planilla_numero,
    ADD COLUMN fecha_evento         DATE              DEFAULT NULL AFTER tipo_evento,
    ADD COLUMN anio_construccion    SMALLINT UNSIGNED DEFAULT NULL AFTER num_sotanos,
    ADD COLUMN numero_personas      INT UNSIGNED      DEFAULT NULL AFTER anio_construccion;

-- Material predominante de la estructura: la planilla distingue Concreto,
-- Acero, Mampostería FORMAL y Mampostería INFORMAL. El sistema ya tenía
-- "material_mamposteria" genérico; se agregan los que faltaban.
ALTER TABLE inspecciones
    ADD COLUMN material_concreto       TINYINT(1) NOT NULL DEFAULT 0 AFTER material_acero,
    ADD COLUMN mamposteria_formal      TINYINT(1) NOT NULL DEFAULT 0 AFTER material_mamposteria,
    ADD COLUMN mamposteria_informal    TINYINT(1) NOT NULL DEFAULT 0 AFTER mamposteria_formal;

-- ---------------------------------------------------------------------
-- 2. INSPECCIÓN EXTERNA — riesgo global calculado (A. Bajo / B. Medio / C. Alto)
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN riesgo_externo VARCHAR(20) DEFAULT NULL AFTER requiere_inspeccion_interna;

-- ---------------------------------------------------------------------
-- 3. IDENTIFICACIÓN DEL PISO CRÍTICO Y ELEMENTOS CON DAÑO SEVERO/COMPLETO
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN pisos_inspeccionados          VARCHAR(255) DEFAULT NULL AFTER riesgo_externo,
    ADD COLUMN acceso_miembros_estructurales VARCHAR(20)  DEFAULT NULL AFTER pisos_inspeccionados,
    ADD COLUMN piso_critico                  VARCHAR(100) DEFAULT NULL AFTER acceso_miembros_estructurales,
    ADD COLUMN riesgo_estructural_severo     VARCHAR(20)  DEFAULT NULL AFTER piso_critico;

-- ---------------------------------------------------------------------
-- 4. ELEMENTOS CON DAÑO MODERADO EN EL PISO CRÍTICO
--    (conteos por tipo de elemento: sin daño/menor, moderado, examinados, %)
--    Se guarda como JSON para no explotar el número de columnas físicas,
--    siguiendo el mismo patrón que "danos_estructurales" y "datos_adicionales".
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN elementos_piso_critico   JSON        DEFAULT NULL AFTER riesgo_estructural_severo,
    ADD COLUMN riesgo_estructural_moderado VARCHAR(20) DEFAULT NULL AFTER elementos_piso_critico;

-- ---------------------------------------------------------------------
-- 5. RIESGO DE COMPONENTES NO ESTRUCTURALES (calculado A/B/C)
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN riesgo_componentes VARCHAR(20) DEFAULT NULL AFTER danos_no_estructurales;

-- ---------------------------------------------------------------------
-- 7. ACCIONES RECOMENDADAS (Inspección Detallada + Medidas de Prevención)
-- ---------------------------------------------------------------------
ALTER TABLE inspecciones
    ADD COLUMN acciones_recomendadas JSON DEFAULT NULL AFTER recomendaciones;
