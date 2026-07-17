<?php
/** Guarda un piso (áreas comunes) y el estado de sus elementos. */
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

    $pisoId = (int)($b['piso_id'] ?? 0);
    if ($pisoId <= 0) resp(false, 'Piso no válido.');

    // Guardar áreas comunes del piso.
    recGuardarPiso($pisoId, $b);

    // Guardar cada elemento y recolectar su id (para poder adjuntarle fotos).
    $tiposValidos = array_keys(recTiposElementoPiso());
    $idsElementos = [];
    foreach (($b['elementos'] ?? []) as $el) {
        $tipo = $el['tipo'] ?? '';
        if (!in_array($tipo, $tiposValidos, true)) continue;
        $idsElementos[$tipo] = recGuardarElementoPiso($pisoId, $tipo, $el);
    }

    resp(true, 'Piso guardado.', ['elementos' => $idsElementos]);
} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
