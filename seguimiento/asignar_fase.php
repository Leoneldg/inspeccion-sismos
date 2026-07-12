<?php
/**
 * Asigna/avanza la fase de recuperación de una inspección.
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

/** Respuesta JSON uniforme. */
function respuesta(bool $ok, string $mensaje = '', array $extra = [], int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Solo quien puede editar el seguimiento puede mover fases.
    requierePermiso('seguimiento', 'editar');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respuesta(false, 'Método no permitido.', [], 405);
    }

    $inspeccionId = (int)($_POST['inspeccion_id'] ?? 0);
    $fase         = (int)($_POST['fase'] ?? 0);

    if ($inspeccionId <= 0) {
        respuesta(false, 'Inspección no válida.', [], 400);
    }
    if ($fase < 1 || $fase > 3) {
        respuesta(false, 'Fase no válida.', [], 400);
    }

    // La inspección debe existir y estar dentro del alcance territorial del usuario.
    $insp = segInspeccion($inspeccionId);
    if (!$insp) {
        respuesta(false, 'La inspección no existe.', [], 404);
    }
    if (!usuarioEsMaster()) {
        $estadoUsuario = estadoDelUsuario();
        if ($estadoUsuario && ($insp['estado'] ?? '') !== $estadoUsuario) {
            respuesta(false, 'No tiene permisos sobre esta edificación.', [], 403);
        }
    }

    $nuevaFase = segAsignarFase($inspeccionId, $fase);
    $catalogo  = segFasesRecuperacion();

    respuesta(true, 'Fase asignada correctamente.', [
        'fase'        => $nuevaFase,
        'fase_nombre' => $catalogo[$nuevaFase]['nombre'],
        'fase_color'  => $catalogo[$nuevaFase]['color'],
        'fase_icono'  => $catalogo[$nuevaFase]['icono'],
    ]);

} catch (Throwable $e) {
    $msg = APP_DEBUG ? $e->getMessage() : 'No se pudo asignar la fase. Intente nuevamente.';
    respuesta(false, $msg, [], 500);
}
