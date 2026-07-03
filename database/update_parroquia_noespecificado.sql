-- Script: update_parroquia_noespecificado.sql
-- Propósito: reemplazar el valor de `parroquia` que contiene "No especificado"
-- con el valor de `municipio` cuando corresponda.
-- RECOMENDACIONES ANTES DE EJECUTAR:
--  1) Hacer respaldo completo de la base de datos (dump).
--  2) Probar en un entorno staging antes de producción.
--  3) Revisar los resultados de las consultas de verificación incluidas.

USE inspecciones_sismos;

START TRANSACTION;

-- A) Conteo de filas que cumplen la condición (normaliza mayúsculas/espacios)
SELECT
    COUNT(*) AS filas_para_corregir
FROM inspecciones
WHERE LOWER(TRIM(COALESCE(parroquia, ''))) = 'no especificado'
  AND TRIM(COALESCE(municipio, '')) <> '';

-- B) Crear (si no existe) una tabla de respaldo y volcar las filas a modificar
CREATE TABLE IF NOT EXISTS inspecciones_backup_parroquia_noespecificado (
    LIKE inspecciones
);

INSERT INTO inspecciones_backup_parroquia_noespecificado
SELECT * FROM inspecciones
WHERE LOWER(TRIM(COALESCE(parroquia, ''))) = 'no especificado'
  AND TRIM(COALESCE(municipio, '')) <> '';

-- C) Mostrar ejemplos (hasta 100) para inspección manual
SELECT id, codigo, municipio, parroquia
FROM inspecciones
WHERE LOWER(TRIM(COALESCE(parroquia, ''))) = 'no especificado'
  AND TRIM(COALESCE(municipio, '')) <> ''
ORDER BY id
LIMIT 100;

-- D) Aplicar la corrección
UPDATE inspecciones
SET parroquia = municipio
WHERE LOWER(TRIM(COALESCE(parroquia, ''))) = 'no especificado'
  AND TRIM(COALESCE(municipio, '')) <> '';

-- E) Resumen de la operación
SELECT ROW_COUNT() AS filas_actualizadas;

-- F) Verificar que no queden registros pendientes con "no especificado"
SELECT
    COUNT(*) AS filas_restantes_no_especificado
FROM inspecciones
WHERE LOWER(TRIM(COALESCE(parroquia, ''))) = 'no especificado';

COMMIT;

-- NOTA: Si prefieres, puedes ejecutar en modo de sólo lectura las secciones A-C primero,
-- verificar resultados, y luego ejecutar la sección D-E-F para aplicar los cambios.
