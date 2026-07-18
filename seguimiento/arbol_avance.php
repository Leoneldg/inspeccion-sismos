<?php
/**
 * Devuelve el árbol completo de avance de un edificio:
 * pisos (con su %) → apartamentos (con su %), en una sola llamada.
 * ?inspeccion=ID
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
    $inspeccionId = (int)($_GET['inspeccion'] ?? 0);
    if ($inspeccionId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Inspección no válida.']);
        exit;
    }

    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Este edificio aún no tiene levantamiento.']);
        exit;
    }

    $arbol = recArbolAvance($edificioId);
    $arbol['ok'] = true;
    $arbol['edificio_id'] = $edificioId;
    $arbol['puede_editar'] = puede('seguimiento', 'editar');
    $arbol['es_sistematizador'] = function_exists('esSistematizador') ? esSistematizador() : true;

    echo json_encode($arbol, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al cargar.'], JSON_UNESCAPED_UNICODE);
}
