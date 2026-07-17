<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
    exit;
}

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
requierePermiso('usuarios', $id ? 'editar' : 'crear');

$nombre  = trim($_POST['nombre_completo'] ?? '');
$usuario = trim($_POST['usuario'] ?? '');
$email   = trim($_POST['email'] ?? '');
$rolId   = (int)($_POST['rol_id'] ?? 0);
$activo  = !empty($_POST['activo']) ? 1 : 0;
$password = (string)($_POST['password'] ?? '');
// Alcance territorial nacional
$esMaster = !empty($_POST['es_master']) ? 1 : 0;
$estadoAsignado = trim($_POST['estado_asignado'] ?? '');
// Ente al que pertenece el usuario (aislamiento de datos por ente).
$enteId = isset($_POST['ente_id']) && $_POST['ente_id'] !== '' ? (int)$_POST['ente_id'] : null;
// ¿Existe la columna ente_id? (para instalaciones sin la migración)
$tieneColEnte = false;
try { db()->query('SELECT ente_id FROM usuarios LIMIT 1'); $tieneColEnte = true; } catch (Throwable $e) { $tieneColEnte = false; }
// Un master no se limita a un estado; un no-master debe tener estado
if ($esMaster) {
    $estadoAsignado = null;
} elseif ($estadoAsignado === '') {
    $estadoAsignado = null;
}

if ($nombre === '' || $usuario === '' || $email === '' || !$rolId || (!$id && $password === '')) {
    flash('error', 'Complete todos los campos obligatorios.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php' . ($id ? "?id=$id" : ''));
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'El correo electrónico no es válido.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php' . ($id ? "?id=$id" : ''));
    exit;
}
if ($password !== '' && strlen($password) < 8) {
    flash('error', 'La contraseña debe tener al menos 8 caracteres.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php' . ($id ? "?id=$id" : ''));
    exit;
}

$pdo = db();

try {
    // Unicidad de usuario/correo
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE (usuario = :u OR email = :e) AND id != :id');
    $stmt->execute(['u' => $usuario, 'e' => $email, 'id' => $id ?? 0]);
    if ($stmt->fetch()) {
        flash('error', 'Ya existe un usuario con ese nombre de usuario o correo.');
        header('Location: ' . APP_URL_BASE . 'admin/usuarios.php' . ($id ? "?id=$id" : ''));
        exit;
    }

    if ($id) {
        $sql = 'UPDATE usuarios SET nombre_completo=:n, usuario=:u, email=:e, rol_id=:r, activo=:a, es_master=:m, estado_asignado=:est';
        $params = ['n' => $nombre, 'u' => $usuario, 'e' => $email, 'r' => $rolId, 'a' => $activo, 'm' => $esMaster, 'est' => $estadoAsignado, 'id' => $id];
        if ($tieneColEnte) {
            $sql .= ', ente_id=:ente';
            $params['ente'] = $enteId;
        }
        if ($password !== '') {
            $sql .= ', password_hash=:p';
            $params['p'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        registrarLog($_SESSION['user_id'], 'usuario_actualizado', "ID: $id");
        flash('success', 'Usuario actualizado correctamente.');
    } else {
        if ($tieneColEnte) {
            $pdo->prepare(
                'INSERT INTO usuarios (nombre_completo, usuario, email, password_hash, rol_id, activo, es_master, estado_asignado, ente_id)
                 VALUES (:n, :u, :e, :p, :r, :a, :m, :est, :ente)'
            )->execute([
                'n' => $nombre, 'u' => $usuario, 'e' => $email,
                'p' => password_hash($password, PASSWORD_BCRYPT),
                'r' => $rolId, 'a' => $activo,
                'm' => $esMaster, 'est' => $estadoAsignado, 'ente' => $enteId,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO usuarios (nombre_completo, usuario, email, password_hash, rol_id, activo, es_master, estado_asignado)
                 VALUES (:n, :u, :e, :p, :r, :a, :m, :est)'
            )->execute([
                'n' => $nombre, 'u' => $usuario, 'e' => $email,
                'p' => password_hash($password, PASSWORD_BCRYPT),
                'r' => $rolId, 'a' => $activo,
                'm' => $esMaster, 'est' => $estadoAsignado,
            ]);
        }
        registrarLog($_SESSION['user_id'], 'usuario_creado', "Usuario: $usuario");
        flash('success', 'Usuario creado correctamente.');
    }
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'Ocurrió un error al guardar el usuario.');
}

header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
exit;
