<?php
/** Devuelve los datos básicos del usuario logueado para el caché offline. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireLogin();
echo json_encode([
    'ok' => true,
    'usuario' => [
        'id'         => $_SESSION['user_id']  ?? null,
        'nombre'     => $_SESSION['nombre']   ?? '',
        'usuario'    => $_SESSION['usuario']  ?? '',
        'rol'        => $_SESSION['rol_nombre'] ?? '',
        'es_master'  => (bool)($_SESSION['es_master'] ?? false),
        'ente_nombre'=> $_SESSION['ente_nombre'] ?? '',
    ]
], JSON_UNESCAPED_UNICODE);
