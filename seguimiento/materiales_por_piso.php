<?php
/**
 * MATERIALES POR PISO (global) — endpoint JSON.
 *
 * Devuelve el total de materiales necesarios para un número de piso
 * concreto, sumando TODOS los edificios que el usuario puede ver
 * (respetando su scope territorial y, si se indica, una parroquia).
 *
 * Parámetros GET:
 *   piso       (int)     número de piso a filtrar. 0 = planta baja.
 *   parroquia  (string)  opcional, limita a una parroquia.
 *
 * Uso: seguimiento/materiales_por_piso.php?piso=3&parroquia=Catedral
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jr($ok, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'ver');

    // El piso puede ser 0 (planta baja), así que se distingue "no enviado".
    if (!isset($_GET['piso']) || $_GET['piso'] === '') {
        jr(false, ['mensaje' => 'Indique el piso.']);
    }
    $numeroPiso = (int)$_GET['piso'];

    $parroquia = trim($_GET['parroquia'] ?? '');
    // Si el usuario no puede ver esa parroquia, se ignora el filtro.
    if ($parroquia !== '' && !puedeAccederParroquia($parroquia)) {
        $parroquia = '';
    }
    $filtros = $parroquia !== '' ? ['parroquia' => $parroquia] : [];

    $res = recMaterialesPorPisoConDesglose($numeroPiso, $filtros);

    // Ordenar el total por nombre para una lectura estable.
    $total = $res['total'] ?? [];
    ksort($total, SORT_NATURAL | SORT_FLAG_CASE);

    jr(true, [
        'numero_piso'  => $numeroPiso,
        'etiqueta'     => $numeroPiso === 0 ? 'Planta baja' : ('Piso ' . $numeroPiso),
        'parroquia'    => $parroquia,
        'total'        => $total,
        'por_edificio' => $res['por_edificio'] ?? [],
    ]);
} catch (Throwable $e) {
    jr(false, ['mensaje' => APP_DEBUG ? $e->getMessage() : 'No se pudo calcular.']);
}
