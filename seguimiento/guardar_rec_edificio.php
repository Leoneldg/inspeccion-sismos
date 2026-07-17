<?php
/** Guarda los datos generales del edificio (Paso 1) y genera sus pisos. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
function resp($ok,$msg='',$extra=[]){ echo json_encode(array_merge(['ok'=>$ok,'mensaje'=>$msg],$extra),JSON_UNESCAPED_UNICODE); exit; }

try {
    requierePermiso('seguimiento', 'editar');
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) resp(false, 'Datos inválidos.');

    $inspeccionId = (int)($b['inspeccion_id'] ?? 0);
    if ($inspeccionId <= 0) resp(false, 'Edificio no válido.');
    if (!segInspeccion($inspeccionId)) resp(false, 'El edificio no existe.');

    $edificioId = recGuardarEdificio($inspeccionId, $b);

    // Generar los pisos automáticamente según num_pisos.
    $numPisos = (int)($b['num_pisos'] ?? 0);
    if ($numPisos > 0 && $numPisos <= 200) {
        recGenerarPisos($edificioId, $numPisos);
    }

    resp(true, 'Datos generales guardados.', ['edificio_id' => $edificioId]);
} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
