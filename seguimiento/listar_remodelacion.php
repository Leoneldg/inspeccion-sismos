<?php
/**
 * Lista los PISOS de un edificio con sus apartamentos, para la ficha de
 * seguimiento/remodelación. Por cada apartamento devuelve:
 *  - identificador y % de avance (nivel apartamento)
 *  - si ya tiene foto del "durante" (para habilitar la barra)
 *  - fotos del "antes" (levantamiento) y del "durante", a nivel apartamento
 *  - sus ambientes, cada uno con sus fotos antes/durante y el lugar (tipo+número)
 * ?edificio_id=N
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

/** Separa las fotos de un nivel/ref en antes (levantamiento) y durante. */
function fotosAntesDurante(string $nivel, int $refId): array
{
    $antes = []; $durante = [];
    foreach (recFotos($nivel, $refId) as $f) {
        $item = ['ruta' => APP_URL_BASE . $f['ruta'], 'parte' => $f['parte'] ?? ''];
        if (($f['parte'] ?? '') === 'durante' || ($f['parte'] ?? '') === 'despues') {
            $durante[] = $item;
        } else {
            $antes[] = $item;
        }
    }
    return [$antes, $durante];
}

try {
    requierePermiso('seguimiento', 'ver');
    $edificioId = (int)($_GET['edificio_id'] ?? 0);
    if ($edificioId <= 0) { echo json_encode(['ok' => false]); exit; }

    $pdo = db();

    // Avance global del edificio (promedio de apartamentos).
    $avanceGlobal = recAvanceEdificio($edificioId);

    // Pisos del edificio.
    $sp = $pdo->prepare("SELECT id, numero FROM rec_piso WHERE edificio_id = :e ORDER BY numero");
    $sp->execute(['e' => $edificioId]);
    $pisos = [];

    foreach ($sp->fetchAll() as $piso) {
        $pisoId = (int)$piso['id'];
        // Apartamentos del piso.
        $sap = $pdo->prepare("SELECT id, identificador FROM rec_apartamento WHERE piso_id = :p ORDER BY identificador");
        $sap->execute(['p' => $pisoId]);
        $aptos = [];

        foreach ($sap->fetchAll() as $ap) {
            $aptoId = (int)$ap['id'];
            [$aptoAntes, $aptoDurante] = fotosAntesDurante('apartamento', $aptoId);

            // Ambientes del apartamento con sus fotos (detallando el lugar).
            $sam = $pdo->prepare("SELECT id, tipo, numero FROM rec_ambiente WHERE apartamento_id = :a ORDER BY tipo, numero");
            $sam->execute(['a' => $aptoId]);
            $ambientes = [];
            foreach ($sam->fetchAll() as $am) {
                $ambId = (int)$am['id'];
                [$amAntes, $amDurante] = fotosAntesDurante('ambiente', $ambId);
                $ambientes[] = [
                    'id'            => $ambId,
                    'tipo'          => $am['tipo'],
                    'numero'        => (int)$am['numero'],
                    'fotos_antes'   => $amAntes,
                    'fotos_durante' => $amDurante,
                ];
            }

            $aptos[] = [
                'id'            => $aptoId,
                'identificador' => $ap['identificador'],
                'avance'        => recAvanceApartamento($aptoId),
                'tiene_durante' => recAptoTieneFotoDurante($aptoId),
                'fotos_antes'   => $aptoAntes,
                'fotos_durante' => $aptoDurante,
                'ambientes'     => $ambientes,
            ];
        }

        $pisos[] = [
            'id'     => $pisoId,
            'numero' => (int)$piso['numero'],
            'aptos'  => $aptos,
        ];
    }

    echo json_encode([
        'ok' => true,
        'avance_global' => $avanceGlobal,
        'pisos' => $pisos,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error'], JSON_UNESCAPED_UNICODE);
}
