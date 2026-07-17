<?php
/**
 * Guarda el % de avance de un ambiente (solo rol sistematizador).
 * Devuelve el avance global recalculado del edificio.
 * Body JSON: { ambiente_id, porcentaje, edificio_id }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');
    if (!esSistematizador()) {
        echo json_encode(['ok' => false, 'mensaje' => 'Solo el sistematizador puede registrar avance.']);
        exit;
    }
    $b = json_decode(file_get_contents('php://input'), true);
    $aptoId = (int)($b['apartamento_id'] ?? 0);
    $pct   = (int)($b['porcentaje'] ?? 0);
    $edificioId = (int)($b['edificio_id'] ?? 0);
    if ($aptoId <= 0) { echo json_encode(['ok' => false, 'mensaje' => 'Apartamento no válido.']); exit; }

    // Requisito: debe existir al menos una foto del "durante" del apartamento.
    if (!recAptoTieneFotoDurante($aptoId)) {
        echo json_encode(['ok' => false, 'mensaje' => 'Primero suba una foto del "durante".']);
        exit;
    }

    recGuardarAvanceApto($aptoId, $pct, trim($b['observaciones'] ?? '') ?: null);

    $avanceGlobal = $edificioId ? recAvanceEdificio($edificioId) : null;
    echo json_encode(['ok' => true, 'avance_global' => $avanceGlobal], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error'], JSON_UNESCAPED_UNICODE);
}
