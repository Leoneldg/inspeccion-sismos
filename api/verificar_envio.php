<?php
/**
 * api/verificar_envio.php
 * Verifica si un client_submission_id ya fue procesado por el servidor.
 * El JS lo consulta en el catch del fetch para saber si el servidor
 * guardó la inspección a pesar de que la conexión se cortó antes
 * de recibir la respuesta — en ese caso NO guardar offline.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'procesado' => false, 'error' => 'sesion_cerrada']);
    exit;
}

$csid = trim($_GET['csid'] ?? '');
if (!$csid) {
    echo json_encode(['ok' => false, 'procesado' => false, 'error' => 'csid_requerido']);
    exit;
}

try {
    $id = envioYaProcesado($csid);
    if ($id) {
        echo json_encode([
            'ok'        => true,
            'procesado' => true,
            'id'        => $id,
            'url'       => APP_URL_BASE . 'formulario/view.php?id=' . $id,
        ]);
    } else {
        echo json_encode(['ok' => true, 'procesado' => false]);
    }
} catch (Throwable $e) {
    // Si la tabla no existe aún (antes de correr actualizacion.sql), asumir no procesado
    echo json_encode(['ok' => true, 'procesado' => false]);
}
