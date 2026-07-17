<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

cerrarSesion();
header('Location: ' . APP_URL_BASE . 'login.php');
exit;
