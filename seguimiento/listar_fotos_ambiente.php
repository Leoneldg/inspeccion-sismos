<?php
/**
 * Fotos de un ambiente (antes y durante). ?ambiente=ID
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');
    $ambienteId = (int)($_GET['ambiente'] ?? 0);
    if ($ambienteId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Ambiente no válido.']);
        exit;
    }

    $fotos = [];
    foreach (recFotos('ambiente', $ambienteId) as $f) {
        $fotos[] = [
            'id'          => (int)$f['id'],
            'ruta'        => APP_URL_BASE . ltrim($f['ruta'], '/'),
            'parte'       => $f['parte'] ?: 'antes',
            'descripcion' => $f['descripcion'] ?? null,
        ];
    }

    echo json_encode(['ok' => true, 'fotos' => $fotos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al cargar.'], JSON_UNESCAPED_UNICODE);
}
