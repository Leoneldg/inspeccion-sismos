<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . APP_URL_BASE . 'admin/roles.php');
    exit;
}

$accion = $_POST['accion'] ?? '';
$pdo = db();

try {
    if ($accion === 'crear_rol') {
        requierePermiso('usuarios', 'crear');
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        if ($nombre === '') {
            flash('error', 'El nombre del rol es obligatorio.');
        } else {
            $pdo->prepare('INSERT INTO roles (nombre, descripcion, es_sistema) VALUES (:n, :d, 0)')
                ->execute(['n' => $nombre, 'd' => $descripcion ?: null]);
            $rolId = $pdo->lastInsertId();

            // Crea permisos en cero para todos los módulos existentes
            $modulos = $pdo->query('SELECT id FROM modulos')->fetchAll();
            $stmt = $pdo->prepare('INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (:r, :m, 0,0,0,0)');
            foreach ($modulos as $m) {
                $stmt->execute(['r' => $rolId, 'm' => $m['id']]);
            }
            registrarLog($_SESSION['user_id'], 'rol_creado', "Rol: $nombre");
            flash('success', 'Rol creado correctamente. Configure sus permisos por módulo.');
        }

    } elseif ($accion === 'eliminar_rol') {
        requierePermiso('usuarios', 'eliminar');
        $rolId = (int)($_POST['rol_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT es_sistema, nombre FROM roles WHERE id = :id');
        $stmt->execute(['id' => $rolId]);
        $rol = $stmt->fetch();
        if (!$rol) {
            flash('error', 'El rol no existe.');
        } elseif ($rol['es_sistema']) {
            flash('error', 'No se puede eliminar un rol base del sistema.');
        } else {
            // Verifica que no haya usuarios activos con ese rol
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM usuarios WHERE rol_id = :id');
            $stmt->execute(['id' => $rolId]);
            if ((int)$stmt->fetch()['c'] > 0) {
                flash('error', 'No se puede eliminar el rol porque tiene usuarios asignados. Reasígnelos primero.');
            } else {
                $pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $rolId]);
                registrarLog($_SESSION['user_id'], 'rol_eliminado', "Rol: {$rol['nombre']}");
                flash('success', 'Rol eliminado correctamente.');
            }
        }

    } elseif ($accion === 'guardar_permisos') {
        requierePermiso('usuarios', 'editar');
        $rolId = (int)($_POST['rol_id'] ?? 0);
        $permisos = $_POST['permisos'] ?? [];
        $modulos = $pdo->query('SELECT id, clave FROM modulos')->fetchAll();

        // PROTECCION: no permitir que el usuario se quite a si mismo el acceso
        // a Usuarios; de lo contrario quedaria bloqueado fuera del sistema.
        $miRol = (int)($_SESSION['rol_id'] ?? 0);
        if ($rolId === $miRol && !usuarioEsMaster()) {
            $idUsuarios = null;
            foreach ($modulos as $m) {
                if (($m['clave'] ?? '') === 'usuarios') { $idUsuarios = (int)$m['id']; break; }
            }
            if ($idUsuarios !== null) {
                $conservaVer    = !empty($permisos[$idUsuarios]['ver']);
                $conservaEditar = !empty($permisos[$idUsuarios]['editar']);
                if (!$conservaVer || !$conservaEditar) {
                    flash('error', 'No puede quitarle a su propio rol el acceso a Usuarios: quedaria sin poder administrar el sistema.');
                    header('Location: ' . APP_URL_BASE . 'admin/roles.php');
                    exit;
                }
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO rol_modulo_permisos (rol_id, modulo_id, ver, crear, editar, eliminar)
             VALUES (:r, :m, :v, :c, :e, :el)
             ON DUPLICATE KEY UPDATE ver = :v2, crear = :c2, editar = :e2, eliminar = :el2'
        );
        foreach ($modulos as $m) {
            $mid = $m['id'];
            $v  = !empty($permisos[$mid]['ver']) ? 1 : 0;
            $c  = !empty($permisos[$mid]['crear']) ? 1 : 0;
            $ed = !empty($permisos[$mid]['editar']) ? 1 : 0;
            $el = !empty($permisos[$mid]['eliminar']) ? 1 : 0;
            $stmt->execute(['r' => $rolId, 'm' => $mid, 'v' => $v, 'c' => $c, 'e' => $ed, 'el' => $el, 'v2' => $v, 'c2' => $c, 'e2' => $ed, 'el2' => $el]);
        }
        registrarLog($_SESSION['user_id'], 'permisos_actualizados', "Rol ID: $rolId");
        flash('success', 'Permisos actualizados correctamente.');
    }
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud.');
}

header('Location: ' . APP_URL_BASE . 'admin/roles.php');
exit;
