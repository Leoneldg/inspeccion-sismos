<?php
/**
 * Asigna un ente a una edificación para su fase de recuperación.
 * Lo invoca por AJAX el mapa del módulo de Seguimiento y Control.
 * Responde siempre en JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function respuesta(bool $ok, string $mensaje = '', array $extra = [], int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respuesta(false, 'Método no permitido.', [], 405);
    }

    $inspeccionId = (int)($_POST['inspeccion_id'] ?? 0);
    $enteId       = (int)($_POST['ente_id'] ?? 0);

    if ($inspeccionId <= 0) respuesta(false, 'Inspección no válida.', [], 400);
    if ($enteId <= 0)       respuesta(false, 'Debe seleccionar un ente.', [], 400);

    // La inspección debe existir y estar dentro del alcance del usuario.
    $insp = segInspeccion($inspeccionId);
    if (!$insp) respuesta(false, 'La inspección no existe.', [], 404);

    if (!usuarioEsMaster()) {
        $estadoUsuario = estadoDelUsuario();
        if ($estadoUsuario && ($insp['estado'] ?? '') !== $estadoUsuario) {
            respuesta(false, 'No tiene permisos sobre esta edificación.', [], 403);
        }
        // El ente debe estar dentro de los que el usuario puede asignar.
        $permitidos = array_map('intval', array_column(segEntes($estadoUsuario), 'id'));
        if (!in_array($enteId, $permitidos, true)) {
            respuesta(false, 'No puede asignar ese ente.', [], 403);
        }
    }

    $ente = segAsignarEnte($inspeccionId, $enteId);
    recAuditar('ente_asignado', $inspeccionId, null, 'Ente: ' . ($ente['nombre'] ?? ('#' . $enteId)));

    // Mensaje de confirmación solicitado.
    $texto = 'Se asignó al ente ' . $ente['nombre']
           . ' la recuperación del "' . $insp['nombre_edificio'] . '" para fase de recuperación.';

    respuesta(true, $texto, [
        'ente_id'     => $ente['id'],
        'ente_nombre' => $ente['nombre'],
        'edificio'    => $insp['nombre_edificio'],
    ]);

} catch (Throwable $e) {
    $msg = APP_DEBUG ? $e->getMessage() : 'No se pudo asignar el ente. Intente nuevamente.';
    respuesta(false, $msg, [], 500);
}
