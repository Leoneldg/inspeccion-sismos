-- =====================================================================
-- Actualización — Módulo "Correcciones del Sistema"
-- Agrega el módulo y sus permisos por rol. Idempotente (usa INSERT
-- IGNORE / comprobaciones), segura de correr más de una vez.
-- =====================================================================

USE inspecciones_sismos;

INSERT IGNORE INTO modulos (clave, nombre, icono, orden) VALUES
('correcciones', 'Correcciones del Sistema', 'bi-clipboard2-pulse-fill', 9);

-- Administrador y Superadministrador: control total
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, m.id, 1, 0, 1, 0
FROM roles r JOIN modulos m ON m.clave = 'correcciones'
WHERE r.nombre IN ('Administrador', 'Superadministrador')
  AND NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp WHERE rmp.rol_id = r.id AND rmp.modulo_id = m.id
  );

-- Supervisor y Estadal: solo ver (para reportar, no corregir directamente)
INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
SELECT r.id, m.id, 1, 0, 0, 0
FROM roles r JOIN modulos m ON m.clave = 'correcciones'
WHERE r.nombre IN ('Supervisor', 'Estadal')
  AND NOT EXISTS (
    SELECT 1 FROM rol_modulo_permisos rmp WHERE rmp.rol_id = r.id AND rmp.modulo_id = m.id
  );
