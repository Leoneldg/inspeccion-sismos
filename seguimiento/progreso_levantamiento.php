<?php
/**
 * Devuelve el progreso del levantamiento de una edificación (JSON).
 *
 * Uso: progreso_levantamiento.php?inspeccion=4449
 *   o  progreso_levantamiento.php?edificio=123
 *
 * Lo consume la barra de avance del formulario para mostrar el porcentaje
 * y, al cerrar, saber qué falta. Solo lee: no modifica nada.
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

    $edificioId = (int)($_GET['edificio'] ?? 0);
    if ($edificioId <= 0) {
        $inspeccionId = (int)($_GET['inspeccion'] ?? 0);
        if ($inspeccionId > 0) {
            $ed = recEdificio($inspeccionId);
            $edificioId = (int)($ed['id'] ?? 0);
        }
    }
    if ($edificioId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Edificación no válida.'],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prog = recProgresoLevantamiento($edificioId);
    echo json_encode(array_merge(['ok' => true], $prog), JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('progreso_levantamiento: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo calcular el progreso.'],
                     JSON_UNESCAPED_UNICODE);
}
