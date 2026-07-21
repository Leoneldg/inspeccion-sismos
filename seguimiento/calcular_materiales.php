<?php
/**
 * Calcula los materiales necesarios a partir de los m² por superficie.
 * Recibe { m2: { pared: 40, techo: 15, ... } } y devuelve los materiales.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');

    // Modo simple (GET): ?tipo=friso_completo&m2=12
    //
    // El parámetro es un TIPO DE TRABAJO del catálogo, no una superficie.
    // Antes se validaba contra las superficies (pared, techo, piso) y por
    // eso las áreas comunes nunca mostraban su material.
    if (isset($_GET['tipo'], $_GET['m2'])) {
        $tipo = trim($_GET['tipo']);
        $m2v  = (float)$_GET['m2'];

        if ($tipo === '' || $m2v <= 0) {
            echo json_encode(['ok' => true, 'materiales' => []]);
            exit;
        }

        // ¿Es un trabajo del catálogo? Es el caso normal.
        $claves = [];
        foreach (recTiposTrabajo() as $tt) $claves[] = $tt['clave'];

        if (in_array($tipo, $claves, true)) {
            $out = [];
            foreach (recMaterialesPorTrabajo([$tipo => $m2v]) as $mat => $d) {
                $out[] = [
                    'material' => $mat,
                    'cantidad' => $d['cantidad'],
                    'unidad'   => $d['unidad'],
                ];
            }
            echo json_encode(['ok' => true, 'materiales' => $out], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Si no, se intenta como superficie (compatibilidad con lo viejo).
        $sups = array_keys(recTiposSuperficie());
        if (in_array($tipo, $sups, true)) {
            $materiales = recCalcularMateriales([$tipo => $m2v]);
            echo json_encode(['ok' => true, 'materiales' => $materiales], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => true, 'materiales' => []]);
        exit;
    }

    $b = json_decode(file_get_contents('php://input'), true);
    $m2 = (is_array($b) && isset($b['m2']) && is_array($b['m2'])) ? $b['m2'] : [];

    $tipos = array_keys(recTiposSuperficie());
    $limpio = [];
    foreach ($m2 as $tipo => $v) {
        if (in_array($tipo, $tipos, true) && (float)$v > 0) $limpio[$tipo] = (float)$v;
    }

    $materiales = recCalcularMateriales($limpio);
    echo json_encode(['ok' => true, 'materiales' => $materiales], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al calcular.'], JSON_UNESCAPED_UNICODE);
}
