<?php
/**
 * Devuelve en JSON el panel de una parroquia: encargado, conteo por color,
 * edificaciones comenzadas y su avance. Lo consume el dashboard de Caracas.
 * Parámetros: ?estado=Distrito Capital&parroquia=Altagracia
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('dashboard', 'ver');
    $estado    = trim($_GET['estado'] ?? '');
    $parroquia = trim($_GET['parroquia'] ?? '');
    if ($estado === '' || $parroquia === '') {
        echo json_encode(['ok' => false, 'mensaje' => 'Falta estado o parroquia.']);
        exit;
    }

    // Alcance: un usuario estadal solo ve su estado.
    if (!usuarioEsMaster() && $estado !== estadoDelUsuario()) {
        echo json_encode(['ok' => false, 'mensaje' => 'No autorizado para ese estado.']);
        exit;
    }

    $panel = recPanelParroquia($estado, $parroquia);
    $panel['ok'] = true;
    echo json_encode($panel, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al cargar la parroquia.'], JSON_UNESCAPED_UNICODE);
}
