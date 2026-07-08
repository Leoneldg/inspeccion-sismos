<?php
/**
 * api/csrf_token.php
 * Devuelve un CSRF token fresco para uso en el reenvío offline.
 * El inspector puede tener un token viejo guardado en IndexedDB de hace horas;
 * este endpoint da uno nuevo antes de cada reenvío automático.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!estaLogueado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'sesion_cerrada', 'mensaje' => 'La sesión ha sido cerrada. Vuelva a iniciar sesión.']);
    exit;
}

echo json_encode([
    'ok'    => true,
    'token' => csrfToken(),
    'user'  => $_SESSION['user_id'] ?? null,
]);
