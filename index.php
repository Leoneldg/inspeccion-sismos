<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// El sistema está enfocado en SEGUIMIENTO Y CONTROL.
if (puede('seguimiento', 'ver')) {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/territorial.php';
    require_once __DIR__ . '/includes/seguimiento.php';

    // El responsable de parroquia entra directo a su panel.
    if (usuarioLimitadoAParroquia()) {
        header('Location: ' . APP_URL_BASE . 'seguimiento/mi_parroquia.php');
        exit;
    }

    // Reparto por rol:
    //  · Sistematizador de campo → su panel de trabajo (las tarjetas).
    //  · Admin / gobernador      → Sala de situación.
    $rolNom     = mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8');
    $esMaster   = function_exists('usuarioEsMaster') && usuarioEsMaster();
    $esAdminRol = $esMaster || str_contains($rolNom, 'administrador');
    $esSistem   = function_exists('esSistematizador') && esSistematizador();

    if ($esSistem && !$esAdminRol) {
        header('Location: ' . APP_URL_BASE . 'seguimiento/mi_trabajo.php');
    } else {
        header('Location: ' . APP_URL_BASE . 'dashboard/sala_situacion.php');
    }
    exit;
} elseif (puede('usuarios', 'ver')) {
    header('Location: ' . APP_URL_BASE . 'admin/usuarios.php');
} else {
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}
exit;
