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

        // Apartamento creado SIN señal: llega con un id negativo temporal.
        // Hay que resolverlo a un apartamento real (buscándolo por piso e
        // identificador, o creándolo) ANTES de guardar. Sin esto, todo lo
        // que el técnico llenó en la calle se rechazaba y se perdía.
        if ($aptoId <= 0) {
            $pisoId = (int)($b['piso_id'] ?? 0);
            $ident  = trim($b['identificador'] ?? '');
            $esLocal = !empty($b['es_local']) ? 1 : 0;

            // Un local creado offline puede no traer piso: se ubica en la
            // planta baja (piso de menor número) del edificio.
            if ($pisoId <= 0 && $esLocal && !empty($b['edificio_id'])) {
                try {
                    $stPB = db()->prepare('SELECT id FROM rec_piso
                                            WHERE edificio_id = :e
                                            ORDER BY numero_piso ASC LIMIT 1');
                    $stPB->execute(['e' => (int)$b['edificio_id']]);
                    $pisoId = (int)$stPB->fetchColumn();
                } catch (Throwable $e) {}
            }

            if ($pisoId <= 0 || $ident === '') {
                resp(false, 'Faltan datos del apartamento sin conexión (piso o identificador).',
                     ['reintentar' => false]);
            }

            // Verificar que el piso exista (por si el edificio cambió).
            $stP = db()->prepare('SELECT COUNT(*) FROM rec_piso WHERE id = :p');
            $stP->execute(['p' => $pisoId]);
            if ((int)$stP->fetchColumn() === 0) {
                resp(false, 'El piso de este apartamento ya no existe.', ['reintentar' => false]);
            }

            recAsegurarLocales();
            // ¿Ya existe (por identificador en ese piso)? Reusarlo.
            $stB = db()->prepare('SELECT id FROM rec_apartamento
                                   WHERE piso_id = :p AND identificador = :i
                                     AND COALESCE(es_local,0) = :l');
            $stB->execute(['p' => $pisoId, 'i' => $ident, 'l' => $esLocal]);
            $real = (int)$stB->fetchColumn();

            if ($real <= 0) {
                db()->prepare('INSERT INTO rec_apartamento (piso_id, identificador, es_local)
                               VALUES (:p, :i, :l)')
                    ->execute(['p' => $pisoId, 'i' => $ident, 'l' => $esLocal]);
                $real = (int)db()->lastInsertId();
            }
            $aptoId = $real;
        }

        if ($aptoId <= 0) resp(false, 'Apartamento no válido.', ['reintentar' => false]);
        recGuardarApartamento($aptoId, $b);
        // Devolver el id REAL y los ambientes (con sus ids) para que el
        // teléfono reasigne las fotos que quedaron con el id temporal.
        resp(true, 'Apartamento guardado.', [
            'apartamento_id_real' => $aptoId,
            'apartamento_id_local' => (int)($b['apartamento_id'] ?? 0),
            'ambientes' => recAmbientes($aptoId),
        ]);
    }

    // --- Datos del jefe de familia, guardado inmediato ---
    // Se guarda campo por campo mientras el técnico escribe, para que
    // una caída de señal no borre lo que ya llenó.
    if ($accion === 'jefe_familia') {
        $aptoId = (int)($b['apartamento_id'] ?? 0);
        if ($aptoId <= 0) resp(false, 'Apartamento no válido.');

        db()->prepare('UPDATE rec_apartamento
                          SET jefe_nombre = :n, jefe_cedula = :c, jefe_telefono = :t
                        WHERE id = :id')
            ->execute([
                'n'  => trim($b['jefe_nombre'] ?? '') ?: null,
                'c'  => trim($b['jefe_cedula'] ?? '') ?: null,
                't'  => trim($b['jefe_telefono'] ?? '') ?: null,
                'id' => $aptoId,
            ]);

        resp(true, 'Guardado.');
    }

    // --- Apartamento que no se pudo levantar ---
    if ($accion === 'marcar_visita') {
        $aptoId = (int)($b['apartamento_id'] ?? 0);
        if ($aptoId <= 0) resp(false, 'Apartamento no válido.');
        recMarcarVisita($aptoId, $b['estado'] ?? 'no_esta', $b['observacion'] ?? '');
        resp(true, 'Registrado.');
    }

    // --- Guardar el tipo de trabajo de un ambiente ---
    if ($accion === 'guardar_trabajo') {
        $ambId = (int)($b['ambiente_id'] ?? 0);
        if ($ambId <= 0) resp(false, 'Ambiente no válido.');
        recAsegurarTablasTrabajo();
        $tipo = trim($b['tipo_trabajo'] ?? '') ?: null;
        // Se guarda en todas las reparaciones del ambiente.
        db()->prepare('UPDATE rec_reparacion SET tipo_trabajo = :t
                        WHERE nivel = :n AND ref_id = :r')
            ->execute(['t' => $tipo, 'n' => 'ambiente', 'r' => $ambId]);
        resp(true, 'Trabajo guardado.');
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

        // El tipo de trabajo puede venir suelto o dentro de reparaciones.
        $reps = $b['reparaciones'] ?? [];
        if (!empty($b['tipo_trabajo'])) {
            $reps['tipo_trabajo'] = trim($b['tipo_trabajo']);
        }
        recGuardarReparaciones($nivel, $refId, $reps);
        resp(true, 'Reparaciones guardadas.');
    }

    resp(false, 'Acción no reconocida.');

} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
