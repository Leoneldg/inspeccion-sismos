<?php
/**
 * Devuelve la estructura completa de la ficha de seguimiento de un edificio:
 * pisos -> apartamentos (con avance y fotos antes/durante) -> ambientes.
 * El avance es por APARTAMENTO. La barra general la calcula el front
 * promediando los apartamentos.
 * ?edificio_id=N
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requierePermiso('seguimiento', 'ver');
    $edificioId = (int)($_GET['edificio_id'] ?? 0);
    if ($edificioId <= 0) { echo json_encode(['ok' => false]); exit; }

    $pisos = recPisos($edificioId);
    $out = [];

    foreach ($pisos as $piso) {
        $pisoId = (int)$piso['id'];
        $aptosOut = [];
        foreach (recApartamentos($pisoId) as $ap) {
            $aptoId = (int)$ap['id'];

            // Fotos del apartamento (antes = las del levantamiento, durante = fase obra).
            $fotosApto = recFotos('apartamento', $aptoId);
            // Ambientes con sus fotos (para detallar el lugar de cada foto).
            $ambientesOut = [];
            foreach (recAmbientes($aptoId) as $am) {
                $ambId = (int)$am['id'];
                $fa = []; $fd = [];
                foreach (recFotos('ambiente', $ambId) as $f) {
                    $item = ['ruta' => APP_URL_BASE . $f['ruta'], 'parte' => $f['parte'] ?? ''];
                    if (($f['parte'] ?? '') === 'durante') $fd[] = $item; else $fa[] = $item;
                }
                $ambientesOut[] = [
                    'id'        => $ambId,
                    'tipo'      => $am['tipo'],
                    'numero'    => (int)$am['numero'],
                    'necesita_reparacion' => (int)($am['necesita_reparacion'] ?? 0),
                    'fotos_antes'   => $fa,
                    'fotos_durante' => $fd,
                ];
            }

            // Fotos antes/durante a nivel apartamento.
            $faApto = []; $fdApto = [];
            foreach ($fotosApto as $f) {
                $item = ['ruta' => APP_URL_BASE . $f['ruta'], 'parte' => $f['parte'] ?? ''];
                if (($f['parte'] ?? '') === 'durante') $fdApto[] = $item; else $faApto[] = $item;
            }

            $aptosOut[] = [
                'id'            => $aptoId,
                'identificador' => $ap['identificador'],
                'avance'        => recAvanceApartamento($aptoId),
                'tiene_durante' => recAptoTieneFotoDurante($aptoId),
                'fotos_antes'   => $faApto,
                'fotos_durante' => $fdApto,
                'ambientes'     => $ambientesOut,
            ];
        }
        $out[] = [
            'id'      => $pisoId,
            'numero'  => (int)$piso['numero'],
            'aptos'   => $aptosOut,
        ];
    }

    echo json_encode([
        'ok' => true,
        'pisos' => $out,
        'avance_global' => recAvanceEdificio($edificioId),
        'puede_cargar' => esSistematizador(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error'], JSON_UNESCAPED_UNICODE);
}
