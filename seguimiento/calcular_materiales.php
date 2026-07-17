<?php
/**
 * Calcula los materiales necesarios a partir de los m² por superficie.
 * Recibe { m2: { pared: 40, techo: 15, ... } } y devuelve los materiales.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');
    $b = json_decode(file_get_contents('php://input'), true);
    $m2 = (is_array($b) && isset($b['m2']) && is_array($b['m2'])) ? $b['m2'] : [];

    $tipos = array_keys(recTiposSuperficie());
    $limpio = [];
    foreach ($m2 as $tipo => $v) {
        if (in_array($tipo, $tipos, true) && (float)$v > 0) $limpio[$tipo] = (float)$v;
    }

    $materiales = recCalcularMateriales($limpio);
    echo json_encode(['ok' => true, 'materiales' => $materiales], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al calcular.'], JSON_UNESCAPED_UNICODE);
}
