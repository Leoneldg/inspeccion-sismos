<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if (puede('dashboard', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'dashboard/index.php');
} elseif (puede('formulario', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
} elseif (puede('usuarios', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
} else {
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}
exit;
