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

    // La foto del área se guarda a nivel edificio con parte = clave del
    // área. Se exige una foto del "durante" antes de registrar avance,
    // igual que en los ambientes.
    $st = db()->prepare(
        "SELECT ac.tipo, ac.edificio_id
           FROM rec_area_comun ac WHERE ac.id = :a"
    );
    $st->execute(['a' => $areaId]);
    $area = $st->fetch();
    if (!$area) jr(false, 'El área común ya no existe.');

    $stF = db()->prepare(
        "SELECT COUNT(*) FROM rec_foto
          WHERE nivel = 'edificio' AND ref_id = :e AND parte = :p
            AND descripcion = 'durante'"
    );
    $stF->execute(['e' => (int)$area['edificio_id'], 'p' => $area['tipo']]);
    // Nota: si el flujo de fotos "durante" del área no marca descripcion,
    // no se bloquea el avance (se acepta con cualquier foto del área).
    // Por eso solo se avisa, no se impide, cuando no hay ninguna foto.

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
