<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'sesion_cerrada', 'mensaje' => 'La sesión ha sido cerrada. Vuelva a iniciar sesión.']);
    exit;
}

echo json_encode([
    'ok'    => true,
    'token' => csrfToken(),
    'user'  => $_SESSION['user_id'] ?? null,
]);
