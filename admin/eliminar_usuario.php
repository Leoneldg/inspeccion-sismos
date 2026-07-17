<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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
    db()->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
    registrarLog($_SESSION['user_id'], 'usuario_eliminado', "ID: $id");
    flash('success', 'Usuario eliminado.');
}

header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
exit;
