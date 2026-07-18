<?php
/**
 * PAQUETE OFFLINE de una edificación.
 * Devuelve TODO lo necesario para trabajar sin señal: datos de la
 * inspección, árbol de pisos/apartamentos/ambientes y las fotos del
 * "antes" (como rutas, para que el navegador las cachee).
 *
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

    $insp = segInspeccion($inspeccionId);
    if (!$insp) {
        echo json_encode(['ok' => false, 'mensaje' => 'La edificación no existe.']);
        exit;
    }
    if (!puedeAccederParroquia($insp['parroquia'] ?? null)) {
        echo json_encode(['ok' => false, 'mensaje' => 'No tiene asignada esta parroquia.']);
        exit;
    }

    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Esta edificación aún no tiene levantamiento.']);
        exit;
    }

    // Árbol completo con porcentajes.
    $arbol = recArbolAvance($edificioId);

    // Fotos de cada ambiente (antes y durante) para poder verlas sin señal.
    $rutasFotos = [];
    foreach ($arbol['pisos'] as &$piso) {
        foreach ($piso['apartamentos'] as &$apto) {
            foreach ($apto['ambientes'] as &$amb) {
                $fotos = [];
                foreach (recFotos('ambiente', (int)$amb['id']) as $f) {
                    $ruta = APP_URL_BASE . ltrim($f['ruta'], '/');
                    $fotos[] = [
                        'ruta'  => $ruta,
                        'parte' => $f['parte'] ?: 'antes',
                    ];
                    $rutasFotos[] = $ruta;
                }
                $amb['fotos'] = $fotos;
            }
            unset($amb);
            // Fotos a nivel del apartamento.
            $fa = [];
            foreach (recFotos('apartamento', (int)$apto['id']) as $f) {
                $ruta = APP_URL_BASE . ltrim($f['ruta'], '/');
                $fa[] = ['ruta' => $ruta, 'parte' => $f['parte'] ?: 'antes'];
                $rutasFotos[] = $ruta;
            }
            $apto['fotos'] = $fa;
        }
        unset($apto);
    }
    unset($piso);

    $paquete = [
        'ok'            => true,
        'inspeccion_id' => $inspeccionId,
        'edificio_id'   => $edificioId,
        'descargado_en' => date('c'),
        'inspeccion'    => [
            'codigo'         => $insp['codigo'] ?? '',
            'nombre'         => $insp['nombre_edificio'] ?? '',
            'parroquia'      => $insp['parroquia'] ?? '',
            'municipio'      => $insp['municipio'] ?? '',
            'direccion'      => trim(implode(', ', array_filter([$insp['avenida_calle'] ?? '', $insp['sector'] ?? '', $insp['urbanizacion'] ?? '']))),
            'decision_final' => $insp['decision_final'] ?? '',
        ],
        'arbol'  => $arbol,
        'fotos'  => array_values(array_unique($rutasFotos)),
    ];

    echo json_encode($paquete, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al preparar la descarga.',
    ], JSON_UNESCAPED_UNICODE);
}
