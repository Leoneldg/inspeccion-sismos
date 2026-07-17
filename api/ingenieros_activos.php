<?php
/** Devuelve la lista de ingenieros activos para el caché offline. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireLogin();
try {
    $rows = db()->query(
        'SELECT id, nombre_completo, cedula, profesion, colegio_inscripcion
         FROM ingenieros WHERE activo = 1 ORDER BY nombre_completo'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'ingenieros' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al obtener ingenieros.']);
}
