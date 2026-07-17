<?php
/**
 * Endpoint liviano que el navegador consulta (polling) mientras save.php
 * está guardando, para mostrar el badge de progreso debajo del botón.
 *
 * A propósito NO usa session_start() de forma bloqueante: solo necesita
 * confirmar que hay una sesión de por medio (evitar exponer esto a
 * cualquiera), sin quedar esperando a que otra petición libere el lock de
 * sesión — por eso cierra la sesión enseguida con session_write_close().
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    session_write_close();
    http_response_code(401);
    echo json_encode(['error' => 'no autenticado']);
    exit;
}
session_write_close(); // liberar el lock de sesión de inmediato; ya no se necesita

$token = $_GET['token'] ?? '';
$data = progresoLeer($token);

if (!$data) {
    // Puede ser normal: el navegador empezó a preguntar una fracción de
    // segundo antes de que save.php alcanzara a crear el archivo de
    // progreso. El front-end lo trata como "todavía arrancando".
    echo json_encode(['pasos' => [], 'encontrado' => false]);
    exit;
}

echo json_encode(['pasos' => $data['pasos'], 'encontrado' => true]);
