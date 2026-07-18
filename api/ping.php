<?php
/**
 * PING — comprueba que hay internet DE VERDAD y que la sesión sigue viva.
 * Respuesta mínima, sin tocar la base de datos, para que sea rápido
 * incluso con señal débil.
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ¿La sesión sigue activa? El cliente lo usa para avisar antes de
// intentar subir y encontrarse con la pantalla de login.
$sesionViva = !empty($_SESSION['user_id']);

echo json_encode([
    'ok'      => true,
    'sesion'  => $sesionViva,
    'hora'    => date('c'),
]);
