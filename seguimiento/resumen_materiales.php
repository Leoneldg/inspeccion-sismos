<?php
/**
 * Devuelve el resumen de materiales de todo el edificio (para el cierre).
 * ?edificio_id=N
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
try {
    requierePermiso('seguimiento', 'ver');
    $edificioId = (int)($_GET['edificio_id'] ?? 0);
    if ($edificioId <= 0) { echo json_encode(['ok'=>false]); exit; }

    $resumen = recResumenMaterialesEdificio($edificioId);
    echo json_encode([
        'ok' => true,
        'materiales' => $resumen['materiales'],
        'm2_por_superficie' => $resumen['m2_por_superficie'],
        'por_trabajo' => $resumen['por_trabajo'] ?? [],
        'total_m2' => $resumen['total_m2'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'mensaje'=>APP_DEBUG?$e->getMessage():'Error'], JSON_UNESCAPED_UNICODE);
}
