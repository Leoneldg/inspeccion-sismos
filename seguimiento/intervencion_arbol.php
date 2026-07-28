<?php
/**
 * Árbol de la fase de intervención de un edificio.
 * ?inspeccion=ID
 *
 * Lo consumen las dos vistas del Modo campo: Resultados (solo lectura) y
 * Reportar (captura). Devuelve el plan del levantamiento con el estado de
 * ejecución de cada partida y el avance ya calculado.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/intervencion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');

    $inspeccionId = (int)($_GET['inspeccion'] ?? 0);
    if ($inspeccionId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Edificio no indicado.']);
        exit;
    }

    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) {
        echo json_encode(['ok' => false,
            'mensaje' => 'Este edificio todavía no tiene levantamiento técnico.']);
        exit;
    }

    $arbol = intvArbol($edificioId);
    $arbol['ok'] = true;
    $arbol['edificio_id'] = $edificioId;
    $arbol['inspeccion_id'] = $inspeccionId;
    $arbol['puede_reportar'] = esSistematizador();
    $arbol['hoy'] = date('Y-m-d');

    echo json_encode($arbol, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'mensaje' => APP_DEBUG ? $e->getMessage() : 'No se pudo cargar la intervención.',
    ], JSON_UNESCAPED_UNICODE);
}
