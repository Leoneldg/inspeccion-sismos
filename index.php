<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// El sistema está enfocado en SEGUIMIENTO Y CONTROL: esa es la página principal.
if (puede('seguimiento', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
} elseif (puede('usuarios', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
} else {
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}
exit;
