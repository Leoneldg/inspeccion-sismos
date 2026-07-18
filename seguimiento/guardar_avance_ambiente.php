<?php
/**
 * Guarda el % de avance de un AMBIENTE (habitación, sala, baño...).
 * Al guardarlo recalcula el % del apartamento (promedio de sus ambientes).
 * Solo el sistematizador (o master) puede registrar avance.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jr(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'ver');

    if (!esSistematizador()) {
        jr(false, 'Solo el sistematizador puede registrar el avance.');
    }

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jr(false, 'Datos inválidos.');

    $ambienteId = (int)($b['ambiente_id'] ?? 0);
    $porcentaje = (int)($b['porcentaje'] ?? -1);
    if ($ambienteId <= 0) jr(false, 'Ambiente no válido.');
    if ($porcentaje < 0 || $porcentaje > 100) jr(false, 'El porcentaje debe estar entre 0 y 100.');

    // Requisito: debe existir foto del "durante" de ese ambiente.
    $st = db()->prepare(
        "SELECT COUNT(*) FROM rec_foto WHERE nivel='ambiente' AND ref_id=:a AND parte='durante'"
    );
    $st->execute(['a' => $ambienteId]);
    if ((int)$st->fetchColumn() === 0) {
        jr(false, 'Primero suba la foto del "durante" de este ambiente.');
    }

    $r = recGuardarAvanceAmbiente($ambienteId, $porcentaje, trim($b['observaciones'] ?? '') ?: null);

    // Devolver el árbol recalculado del edificio si lo piden.
    $edificioId = (int)($b['edificio_id'] ?? 0);
    $extra = $r;
    if ($edificioId > 0) {
        $arbol = recArbolAvance($edificioId);
        $extra['avance_edificio'] = $arbol['avance_edificio'];
    }

    jr(true, 'Avance guardado.', $extra);

} catch (Throwable $e) {
    jr(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar el avance.');
}
