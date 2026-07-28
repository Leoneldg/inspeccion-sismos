<?php
/**
 * Guarda el % de avance de un ÁREA COMÚN (pasillo, escalera, fachada...).
 * Espejo de guardar_avance_ambiente.php: funciona igual que el de un
 * apartamento. Solo el sistematizador (o master) puede registrar avance.
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

    $areaId     = (int)($b['area_comun_id'] ?? 0);
    $porcentaje = (int)($b['porcentaje'] ?? -1);
    if ($areaId <= 0) jr(false, 'Área común no válida.');
    if ($porcentaje < 0 || $porcentaje > 100) jr(false, 'El porcentaje debe estar entre 0 y 100.');

    // La foto del "durante" del área se guarda a nivel area_comun.
    // Se exige antes de registrar avance, igual que en los ambientes.
    $st = db()->prepare("SELECT COUNT(*) FROM rec_area_comun WHERE id = :a");
    $st->execute(['a' => $areaId]);
    if ((int)$st->fetchColumn() === 0) jr(false, 'El área común ya no existe.');

    $stF = db()->prepare(
        "SELECT COUNT(*) FROM rec_foto
          WHERE nivel = 'area_comun' AND ref_id = :r AND parte = 'durante'"
    );
    $stF->execute(['r' => $areaId]);
    if ((int)$stF->fetchColumn() === 0) {
        jr(false, 'Primero suba la foto del "durante" de esta área.');
    }

    $r = recGuardarAvanceAreaComun($areaId, $porcentaje, trim($b['observaciones'] ?? '') ?: null);

    // Devolver el avance del edificio recalculado si lo piden.
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
