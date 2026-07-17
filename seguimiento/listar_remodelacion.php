<?php
/**
 * Lista los apartamentos y sus ambientes con reparación de un edificio,
 * con la foto "antes" (levantamiento), la foto "durante" y el % de avance.
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

    $pdo = db();
    // Apartamentos del edificio con al menos un ambiente que necesita reparación.
    $st = $pdo->prepare(
        "SELECT DISTINCT ap.id, ap.identificador
           FROM rec_apartamento ap
           JOIN rec_piso pi ON pi.id = ap.piso_id
           JOIN rec_ambiente am ON am.apartamento_id = ap.id
          WHERE pi.edificio_id = :e AND am.necesita_reparacion = 1
          ORDER BY ap.identificador"
    );
    $st->execute(['e' => $edificioId]);
    $apartamentos = [];

    foreach ($st->fetchAll() as $ap) {
        $aptoId = (int)$ap['id'];
        // Ambientes con reparación de este apartamento.
        $sa = $pdo->prepare(
            "SELECT id, tipo, numero FROM rec_ambiente
              WHERE apartamento_id = :a AND necesita_reparacion = 1
              ORDER BY tipo, numero"
        );
        $sa->execute(['a' => $aptoId]);
        $ambientes = [];
        foreach ($sa->fetchAll() as $am) {
            $ambId = (int)$am['id'];
            $fotos = recFotos('ambiente', $ambId);
            $antes = null; $durante = null;
            foreach ($fotos as $f) {
                $ruta = APP_URL_BASE . $f['ruta'];
                // "durante" = parte durante; el resto se considera del "antes" (levantamiento).
                if (($f['parte'] ?? '') === 'durante') { $durante = $ruta; }
                elseif ($antes === null) { $antes = $ruta; }
            }
            $ambientes[] = [
                'id'           => $ambId,
                'tipo'         => $am['tipo'],
                'numero'       => (int)$am['numero'],
                'foto_antes'   => $antes,
                'foto_durante' => $durante,
                'avance'       => recAvanceAmbiente($ambId),
            ];
        }
        $apartamentos[] = [
            'id'           => $aptoId,
            'identificador'=> $ap['identificador'],
            'avance'       => recAvanceApartamento($aptoId),
            'ambientes'    => $ambientes,
        ];
    }

    echo json_encode(['ok' => true, 'apartamentos' => $apartamentos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error'], JSON_UNESCAPED_UNICODE);
}
