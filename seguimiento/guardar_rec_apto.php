<?php
/**
 * Guarda apartamentos y ambientes (Paso 3 del levantamiento).
 * Dos acciones:
 *   - generar_aptos: crea los apartamentos de un piso según una cantidad.
 *   - guardar_apto: guarda las cantidades de ambientes de un apartamento
 *                   (habitaciones, salas, balcones, cocinas) y los genera.
 *   - guardar_ambiente: marca si un ambiente necesita reparación.
 * Responde en JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
function resp($ok, $msg = '', $extra = []) {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) resp(false, 'Datos inválidos.');

    $accion = $b['accion'] ?? '';

    // --- Generar los apartamentos de un piso ---
    if ($accion === 'generar') {
        $pisoId   = (int)($b['piso_id'] ?? 0);
        $cantidad = (int)($b['cantidad'] ?? 0);
        $numPiso  = (int)($b['numero_piso'] ?? 1);
        if ($pisoId <= 0) resp(false, 'Piso no válido.');
        if ($cantidad < 1 || $cantidad > 100) resp(false, 'Cantidad de apartamentos fuera de rango (1–100).');

        $aptos = recGenerarApartamentos($pisoId, $cantidad, $numPiso);
        resp(true, 'Apartamentos generados.', ['apartamentos' => $aptos]);
    }

    // --- Guardar las cantidades de ambientes de un apartamento ---
    if ($accion === 'guardar_apto') {
        $aptoId = (int)($b['apartamento_id'] ?? 0);
        if ($aptoId <= 0) resp(false, 'Apartamento no válido.');
        recGuardarApartamento($aptoId, $b);
        // Devolver los ambientes generados (con sus ids) para poder subirles fotos.
        resp(true, 'Apartamento guardado.', ['ambientes' => recAmbientes($aptoId)]);
    }

    // --- Guardar un ambiente (necesita reparación) ---
    if ($accion === 'guardar_ambiente') {
        $ambId = (int)($b['ambiente_id'] ?? 0);
        if ($ambId <= 0) resp(false, 'Ambiente no válido.');
        recGuardarAmbiente($ambId, $b);
        resp(true, 'Ambiente guardado.');
    }

    // --- Guardar las reparaciones (m² por superficie) de un ambiente/elemento ---
    if ($accion === 'guardar_reparaciones') {
        $nivel = $b['nivel'] ?? 'ambiente';
        $refId = (int)($b['ref_id'] ?? 0);
        if ($refId <= 0) resp(false, 'Referencia no válida.');
        if (!in_array($nivel, ['ambiente','elemento_piso'], true)) $nivel = 'ambiente';
        recGuardarReparaciones($nivel, $refId, $b['reparaciones'] ?? []);
        resp(true, 'Reparaciones guardadas.');
    }

    resp(false, 'Acción no reconocida.');

} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
