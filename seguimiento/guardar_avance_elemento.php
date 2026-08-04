<?php
/**
 * Guarda el % de avance de un ELEMENTO DEL PISO (pasillo, escaleras,
 * fachada, ascensor...). Espejo de guardar_avance_area.php.
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

    $elementoId = (int)($b['elemento_piso_id'] ?? 0);
    $porcentaje = (int)($b['porcentaje'] ?? -1);
    if ($elementoId <= 0) jr(false, 'Elemento del piso no válido.');
    if ($porcentaje < 0 || $porcentaje > 100) jr(false, 'El porcentaje debe estar entre 0 y 100.');

    $st = db()->prepare("SELECT COUNT(*) FROM rec_elemento_piso WHERE id = :a");
    $st->execute(['a' => $elementoId]);
    if ((int)$st->fetchColumn() === 0) jr(false, 'El elemento del piso ya no existe.');

    // La foto del "durante" del elemento se guarda a nivel elemento_piso.
    // Se exige antes de registrar avance, igual que en ambientes y áreas.
    $stF = db()->prepare(
        "SELECT COUNT(*) FROM rec_foto
          WHERE nivel = 'elemento_piso' AND ref_id = :r AND parte = 'durante'"
    );
    $stF->execute(['r' => $elementoId]);
    if ((int)$stF->fetchColumn() === 0) {
        jr(false, 'Primero suba la foto del "durante" de este elemento.');
    }

    $r = recGuardarAvanceElementoPiso($elementoId, $porcentaje, trim($b['observaciones'] ?? '') ?: null);

    // Devolver el avance del edificio recalculado si lo piden.
    $edificioId = (int)($b['edificio_id'] ?? 0);
    $extra = $r;
    if ($edificioId > 0) {
        $arbol = recArbolAvance($edificioId);
        $extra['avance_edificio'] = $arbol['avance_edificio'] ?? 0;
    }

    jr(true, 'Avance guardado.', $extra);

} catch (Throwable $e) {
    jr(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar el avance.');
}
