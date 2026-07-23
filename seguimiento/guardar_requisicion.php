<?php
/**
 * Acciones de una requisición (AJAX, JSON).
 *
 * Acciones:
 *   crear         nueva requisición en borrador
 *   cabecera      actualiza título y observaciones
 *   guardar       agrega o modifica un renglón
 *   borrar        quita un renglón
 *   emitir        cierra la requisición como solicitud formal
 *   reabrir       vuelve a borrador (queda registrado)
 *   eliminar      borra la requisición completa (solo borrador)
 *   copiar        copia los renglones de otra requisición
 *   nuevo_item    agrega un artículo al catálogo
 *   nuevo_rubro   agrega un rubro al catálogo
 *
 * Solo escribe en las tablas req_*. No toca nada del resto del sistema.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/requisiciones.php';

header('Content-Type: application/json; charset=utf-8');

function respR(bool $ok, string $msg = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra),
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');
    reqAsegurarTablas();

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) respR(false, 'Datos inválidos.');

    $accion = (string)($b['accion'] ?? '');

    // -----------------------------------------------------------------
    // Catálogo (no dependen de una requisición)
    // -----------------------------------------------------------------
    if ($accion === 'nuevo_rubro') {
        $nombre = trim((string)($b['nombre'] ?? ''));
        if ($nombre === '')           respR(false, 'Escriba el nombre del rubro.');
        if (mb_strlen($nombre) > 120) respR(false, 'El nombre es demasiado largo.');

        $id = reqAgregarRubro($nombre);
        if (!$id) respR(false, 'No se pudo agregar el rubro.');
        respR(true, 'Rubro agregado.', ['rubro_id' => $id, 'nombre' => $nombre]);
    }

    if ($accion === 'nuevo_item') {
        $rubroId = (int)($b['rubro_id'] ?? 0);
        $nombre  = trim((string)($b['nombre'] ?? ''));
        $unidad  = trim((string)($b['unidad'] ?? 'unidad')) ?: 'unidad';

        if ($rubroId <= 0)            respR(false, 'Elija el rubro.');
        if ($nombre === '')           respR(false, 'Escriba el nombre del material.');
        if (mb_strlen($nombre) > 160) respR(false, 'El nombre es demasiado largo.');
        if (!in_array($unidad, reqUnidades(), true)) $unidad = 'unidad';

        $id = reqAgregarItem($rubroId, $nombre, $unidad);
        if (!$id) respR(false, 'No se pudo agregar el material al catálogo.');
        respR(true, 'Material agregado al catálogo.', [
            'item_id' => $id, 'nombre' => $nombre, 'unidad' => $unidad,
        ]);
    }

    // -----------------------------------------------------------------
    // Crear una requisición nueva
    // -----------------------------------------------------------------
    if ($accion === 'crear') {
        $edificioId   = (int)($b['edificio_id'] ?? 0);
        $inspeccionId = (int)($b['inspeccion_id'] ?? 0);

        if ($edificioId <= 0 && $inspeccionId > 0) {
            $ed = recEdificio($inspeccionId);
            $edificioId = (int)($ed['id'] ?? 0);
        }
        if ($edificioId <= 0) respR(false, 'No se pudo ubicar la edificación.');

        $stChk = db()->prepare('SELECT COUNT(*) FROM rec_edificio WHERE id = :e');
        $stChk->execute(['e' => $edificioId]);
        if (!(int)$stChk->fetchColumn()) respR(false, 'La edificación no existe.');

        $r = reqCrear($edificioId,
                      (string)($b['titulo'] ?? ''),
                      (string)($b['observaciones'] ?? ''));
        if (empty($r['ok'])) respR(false, $r['mensaje'] ?? 'No se pudo crear.');
        respR(true, $r['mensaje'], [
            'requisicion_id' => $r['id'], 'numero' => $r['numero'],
        ]);
    }

    // -----------------------------------------------------------------
    // El resto necesita la requisición
    // -----------------------------------------------------------------
    $reqId = (int)($b['requisicion_id'] ?? 0);
    if ($reqId <= 0) respR(false, 'No se pudo ubicar la requisición.');

    $req = reqObtener($reqId);
    if (!$req) respR(false, 'La requisición no existe.');

    if ($accion === 'cabecera') {
        $r = reqActualizarCabecera($reqId,
                                   (string)($b['titulo'] ?? ''),
                                   (string)($b['observaciones'] ?? ''));
        respR(!empty($r['ok']), $r['mensaje'] ?? '');
    }

    if ($accion === 'guardar') {
        $unidad = trim((string)($b['unidad'] ?? 'unidad'));
        if (!in_array($unidad, reqUnidades(), true)) $unidad = 'unidad';

        $r = reqGuardarRenglon($reqId, [
            'rubro_id'     => (int)($b['rubro_id'] ?? 0),
            'item_id'      => (int)($b['item_id'] ?? 0),
            'nombre_libre' => (string)($b['nombre_libre'] ?? ''),
            'unidad'       => $unidad,
            'cantidad'     => (string)($b['cantidad'] ?? ''),
            'nota'         => (string)($b['nota'] ?? ''),
            'renglon_id'   => (int)($b['renglon_id'] ?? 0),
        ]);
        if (empty($r['ok'])) respR(false, $r['mensaje'] ?? 'No se pudo guardar.');
        respR(true, $r['mensaje'], [
            'renglon_id' => $r['id'] ?? 0,
            'total'      => reqTotalRenglones($reqId),
        ]);
    }

    if ($accion === 'borrar') {
        $r = reqBorrarRenglon($reqId, (int)($b['renglon_id'] ?? 0));
        if (empty($r['ok'])) respR(false, $r['mensaje'] ?? 'No se pudo eliminar.');
        respR(true, $r['mensaje'], ['total' => reqTotalRenglones($reqId)]);
    }

    if ($accion === 'emitir') {
        $r = reqEmitir($reqId);
        respR(!empty($r['ok']), $r['mensaje'] ?? '');
    }

    if ($accion === 'reabrir') {
        $r = reqReabrir($reqId);
        respR(!empty($r['ok']), $r['mensaje'] ?? '');
    }

    if ($accion === 'eliminar') {
        $r = reqBorrar($reqId);
        respR(!empty($r['ok']), $r['mensaje'] ?? '');
    }

    if ($accion === 'copiar') {
        $r = reqCopiarRenglones($reqId, (int)($b['origen_id'] ?? 0));
        if (empty($r['ok'])) respR(false, $r['mensaje'] ?? 'No se pudo copiar.');
        respR(true, $r['mensaje'], ['total' => reqTotalRenglones($reqId)]);
    }

    respR(false, 'Acción no reconocida.');

} catch (Throwable $e) {
    error_log('guardar_requisicion: ' . $e->getMessage());
    respR(false, APP_DEBUG ? $e->getMessage() : 'No se pudo completar la operación.');
}
