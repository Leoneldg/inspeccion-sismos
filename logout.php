<?php
/**
 * Cierre de sesión.
 *
 * Limpia la sesión (y su cookie) y devuelve al login.
 * Este archivo es el destino del botón "Cerrar sesión" del sidebar
 * (includes/header.php) y está excluido del cache del service worker
 * para que nunca se sirva una copia guardada.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

cerrarSesion();

header('Location: ' . APP_URL_BASE . 'login.php');
exit;
