<?php
/**
 * Búsqueda de edificaciones por texto (nombre/código) y/o parroquia.
 * Devuelve los puntos que coinciden, para mostrarlos en el mapa y en una lista.
 * ?q=texto&parroquia=Altagracia&estado=Distrito Capital
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
    $q         = trim($_GET['q'] ?? '');
    $parroquia = trim($_GET['parroquia'] ?? '');
    $estado    = trim($_GET['estado'] ?? '');
    $enteId    = trim($_GET['ente_id'] ?? '');
    $color     = trim($_GET['color'] ?? '');
    $uso       = trim($_GET['uso'] ?? '');

    if ($q === '' && $parroquia === '' && $enteId === '' && $color === '' && $uso === '') {
        echo json_encode(['ok' => true, 'puntos' => []]);
        exit;
    }

    $pdo = db();
    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');

    if ($q !== '') {
        $conds[] = '(i.nombre_edificio LIKE :q OR i.codigo LIKE :q2)';
        $params['q']  = '%' . $q . '%';
        $params['q2'] = '%' . $q . '%';
    }
    if ($parroquia !== '') {
        $conds[] = 'i.parroquia = :p';
        $params['p'] = $parroquia;
    }
    if ($estado !== '') {
        $conds[] = 'i.estado = :e';
        $params['e'] = $estado;
    }
    if ($enteId !== '') {
        $conds[] = 'so.ente_id = :ente';
        $params['ente'] = (int)$enteId;
    }
    // Filtro por uso de la edificación.
    if ($uso !== '') {
        $conds[] = 'i.uso_edificacion = :uso';
        $params['uso'] = $uso;
    }
    // Filtro por status/color de la vivienda.
    if ($color !== '') {
        $mapaColor = [
            'verde'      => 'Edificación Inspeccionada - Acceso Permitido',
            'amarillo'   => 'Acceso Restringido - Precaución al Entrar',
            'rojo'       => 'Edificación Insegura - Acceso No Permitido',
            'derrumbado' => 'Derrumbado',
        ];
        if (isset($mapaColor[$color])) {
            $conds[] = 'i.decision_final = :dec';
            $params['dec'] = $mapaColor[$color];
        }
    }
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

    $sql = "SELECT i.id AS inspeccion_id, i.codigo, i.nombre_edificio,
                   i.latitud, i.longitud, i.parroquia, i.municipio, i.estado,
                   i.uso_edificacion, i.num_pisos, i.numero_personas,
                   i.decision_final, i.fecha_inspeccion,
                   so.estado_obra, so.avance_pct, so.ente_id, e.nombre AS ente_nombre,
                   re.id AS rec_edificio_id, re.completado
              FROM inspecciones i
              LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
              LEFT JOIN entes e ON e.id = so.ente_id
              LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
             ORDER BY i.nombre_edificio
             LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $cat = catalogoDecisionFinal();
    // Sub-asignaciones (si se filtró por parroquia, se cargan de esa).
    $subasig = ($parroquia !== '' && function_exists('asigDeParroquia'))
        ? asigDeParroquia($estado ?: 'Distrito Capital', $parroquia) : [];
    $puntos = [];
    foreach ($st->fetchAll() as $ed) {
        $lat = $ed['latitud'] ?? null;
        $lng = $ed['longitud'] ?? null;
        $tieneCoord = ($lat !== null && $lng !== null
            && (float)$lat >= 0.6 && (float)$lat <= 12.2
            && (float)$lng >= -73.4 && (float)$lng <= -59.8);

        $decision = $ed['decision_final'] ?? '';
        $meta = $cat[$decision] ?? ['color' => '#767c94', 'corto' => '—'];
        $fase = function_exists('segFaseDe') ? segFaseDe($ed['estado_obra'] ?? null, $ed['avance_pct'] ?? 0) : 0;

        $puntos[] = [
            'id'            => (int)$ed['inspeccion_id'],
            'codigo'        => $ed['codigo'],
            'nombre'        => $ed['nombre_edificio'],
            'lat'           => $tieneCoord ? (float)$lat : null,
            'lng'           => $tieneCoord ? (float)$lng : null,
            'tiene_coord'   => $tieneCoord,
            'aprox'         => false,
            'color'         => $meta['color'],
            'decision'      => $meta['corto'],
            'parroquia'     => $ed['parroquia'] ?: '—',
            'municipio'     => $ed['municipio'] ?: '—',
            'estado'        => $ed['estado'] ?: '—',
            'uso'           => $ed['uso_edificacion'] ?: '—',
            'pisos'         => (int)($ed['num_pisos'] ?? 0),
            'personas'      => (int)($ed['numero_personas'] ?? 0),
            'fecha'         => $ed['fecha_inspeccion'] ?: '—',
            'ente'          => $ed['ente_nombre'] ?: null,
            'miembro'       => $subasig[(int)$ed['inspeccion_id']]['gdc'] ?? null,
            'estado_obra'   => $ed['estado_obra'] ?: null,
            'avance'        => $ed['avance_pct'] !== null ? (int)$ed['avance_pct'] : 0,
            'fase'          => $fase,
            'levantamiento_completo' => !empty($ed['rec_edificio_id']) && (int)($ed['completado'] ?? 0) === 1,
            'ficha_url'         => APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . (int)$ed['inspeccion_id'],
            'levantamiento_url' => APP_URL_BASE . 'seguimiento/levantamiento.php?inspeccion=' . (int)$ed['inspeccion_id'],
        ];
    }

    echo json_encode(['ok' => true, 'puntos' => $puntos, 'total' => count($puntos)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error en la búsqueda.'], JSON_UNESCAPED_UNICODE);
}
