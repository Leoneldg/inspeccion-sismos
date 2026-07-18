<?php
/**
 * Devuelve en JSON los puntos (edificaciones) de UNA parroquia.
 * Se llama cuando el usuario selecciona la parroquia en el mapa,
 * para no dibujar miles de puntos de golpe (rendimiento).
 * ?estado=Distrito Capital&parroquia=Altagracia
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
    $estado    = trim($_GET['estado'] ?? '');
    $parroquia = trim($_GET['parroquia'] ?? '');
    if ($estado === '' || $parroquia === '') {
        echo json_encode(['ok' => false, 'mensaje' => 'Falta estado o parroquia.']);
        exit;
    }

    $cat = catalogoDecisionFinal();
    // Sub-asignaciones de la parroquia (una consulta para todos los puntos).
    $subasig = function_exists('asigDeParroquia') ? asigDeParroquia($estado, $parroquia) : [];
    // Por defecto solo las que están en reconstrucción (levantamiento
    // cerrado). Con ?fase=todas se muestran todas las inspecciones.
    $fase = ($_GET['fase'] ?? 'reconstruccion') === 'todas' ? 'todas' : 'reconstruccion';
    $filas = segPuntosDeParroquia($estado, $parroquia, $fase);
    $puntos = [];

    foreach ($filas as $ed) {
        $lat = $ed['latitud'] ?? null;
        $lng = $ed['longitud'] ?? null;
        // Solo puntos con coordenadas válidas dentro de Venezuela.
        if ($lat === null || $lng === null) continue;
        $lat = (float)$lat; $lng = (float)$lng;
        if ($lat < 0.6 || $lat > 12.2 || $lng < -73.4 || $lng > -59.8) continue;

        $decision = $ed['decision_final'] ?? '';
        $meta = $cat[$decision] ?? ['color' => '#767c94', 'corto' => '—'];
        $fase = function_exists('segFaseDe')
            ? segFaseDe($ed['estado_obra'] ?? null, $ed['avance_pct'] ?? 0)
            : 0;

        $puntos[] = [
            'id'            => (int)$ed['inspeccion_id'],
            'codigo'        => $ed['codigo'],
            'nombre'        => $ed['nombre_edificio'],
            'lat'           => $lat,
            'lng'           => $lng,
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

    echo json_encode(['ok' => true, 'puntos' => $puntos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => APP_DEBUG ? $e->getMessage() : 'Error al cargar puntos.'], JSON_UNESCAPED_UNICODE);
}
