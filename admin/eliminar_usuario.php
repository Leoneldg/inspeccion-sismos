<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('usuarios', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if ($id === (int)$_SESSION['user_id']) {
    flash('error', 'No puede eliminar su propio usuario mientras tiene la sesión activa.');
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
    exit;
}

if ($id) {
    // Alcance nacional: un administrador estadal no puede eliminar usuarios
    // de otro estado.
    if (!usuarioEsMaster()) {
        $chk = db()->prepare('SELECT estado_asignado FROM usuarios WHERE id = :id');
        $chk->execute(['id' => $id]);
        $obj = $chk->fetch();
        if (!$obj || ($obj['estado_asignado'] ?? null) !== estadoDelUsuario()) {
            flash('error', 'No puede eliminar usuarios de otro estado.');
            header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
            exit;
        }
    }
    db()->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
    registrarLog($_SESSION['user_id'], 'usuario_eliminado', "ID: $id");
    flash('success', 'Usuario eliminado.');
}

header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
exit;
