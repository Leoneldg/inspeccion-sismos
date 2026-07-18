<?php
/**
 * Fotos de un ambiente (antes y durante). ?ambiente=ID
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');
    $ambienteId = (int)($_GET['ambiente'] ?? 0);
    if ($ambienteId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Ambiente no válido.']);
        exit;
    }

    $fotos = [];
    foreach (recFotos('ambiente', $ambienteId) as $f) {
        // "parte" distingue antes/durante. Si trae otra cosa (Pared,
        // Techo, Piso…) es la parte física que se fotografió.
        $p = $f['parte'] ?: 'antes';
        $esFase = in_array(mb_strtolower($p), ['antes', 'durante', 'despues', 'etiqueta'], true);
        $fotos[] = [
            'id'            => (int)$f['id'],
            'ruta'          => APP_URL_BASE . ltrim($f['ruta'], '/'),
            'parte'         => $esFase ? $p : 'antes',
            'parte_detalle' => $esFase ? ($f['descripcion'] ?? '') : $p,
            'descripcion'   => $f['descripcion'] ?? null,
            'fecha'         => !empty($f['creado_en'])
                ? date('d/m/Y H:i', strtotime($f['creado_en'])) : '',
        ];
    }

    echo json_encode(['ok' => true, 'fotos' => $fotos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al cargar.'], JSON_UNESCAPED_UNICODE);
}
