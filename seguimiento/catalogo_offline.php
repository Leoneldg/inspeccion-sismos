<?php
/**
 * CATÁLOGO PARA TRABAJO SIN SEÑAL.
 *
 * Entrega, en una sola respuesta, todo lo que hace falta para buscar
 * edificaciones y abrir su levantamiento sin conexión:
 *   · las edificaciones con sus datos básicos
 *   · las parroquias
 *   · los tipos de trabajo y sus recetas
 *
 * El técnico lo descarga antes de salir a campo con el botón
 * "Preparar para campo". Después puede trabajar todo el día offline.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

// Sin sesión no hay datos: el catálogo lleva información de campo.
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión expirada.']);
    exit;
}

if (!puede('seguimiento', 'ver')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'Sin permiso.']);
    exit;
}

try {
    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

    // --- Edificaciones ---
    // Solo los campos que se usan para buscar y para abrir la ficha.
    // Agregar más columnas encarece la descarga sin aportar en campo.
    $st = db()->prepare("
        SELECT i.id,
               i.codigo,
               i.nombre_edificio AS nom,
               i.parroquia       AS parr,
               i.decision_final  AS dec,
               i.uso_edificacion AS uso,
               i.num_pisos       AS pisos,
               i.familias        AS fam,
               i.latitud         AS lat,
               i.longitud        AS lng,
               TRIM(CONCAT_WS(', ', NULLIF(i.avenida_calle,''),
                                    NULLIF(i.sector,''),
                                    NULLIF(i.urbanizacion,''))) AS dir,
               re.id             AS edif_id,
               re.completado     AS cerrado
          FROM inspecciones i
          LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
          $where
         ORDER BY i.parroquia, i.nombre_edificio
    ");
    $st->execute($params);

    $cat = catalogoDecisionFinal();
    $edificios = [];
    $parroquias = [];

    foreach ($st->fetchAll() as $r) {
        $dec = $r['dec'] ?? '';
        $meta = $cat[$dec] ?? ['color' => '#767c94', 'corto' => '—'];

        $edificios[] = [
            'id'      => (int)$r['id'],
            'cod'     => $r['codigo'],
            'nom'     => $r['nom'] ?: 'Sin nombre',
            'parr'    => $r['parr'] ?: '—',
            'dir'     => $r['dir'] ?: '',
            'uso'     => $r['uso'] ?: '',
            'col'     => $meta['color'],
            'dec'     => $meta['corto'],
            'pisos'   => (int)$r['pisos'],
            'fam'     => (int)$r['fam'],
            'lat'     => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng'     => $r['lng'] !== null ? (float)$r['lng'] : null,
            'edif'    => $r['edif_id'] ? (int)$r['edif_id'] : null,
            'cerrado' => !empty($r['cerrado']),
        ];

        if (!empty($r['parr'])) $parroquias[$r['parr']] = true;
    }

    $parroquias = array_keys($parroquias);
    sort($parroquias);

    // --- Tipos de trabajo y recetas ---
    // Hacen falta para calcular materiales en el teléfono.
    $trabajos = [];
    try {
        foreach (recTiposTrabajo() as $t) {
            $trabajos[] = [
                'clave'    => $t['clave'],
                'nombre'   => $t['nombre'],
                'aplica_a' => $t['aplica_a'] ?? '',
                'unidad'   => $t['unidad'] ?? 'm2',
            ];
        }
    } catch (Throwable $e) {}

    $recetas = [];
    try {
        foreach (recRecetasTrabajo() as $clave => $ings) {
            foreach ($ings as $ing) {
                $recetas[] = [
                    'trabajo'  => $clave,
                    'material' => $ing['material'],
                    'unidad'   => $ing['unidad'],
                    'cantidad' => (float)$ing['cantidad'],
                    'etapa'    => $ing['etapa'] ?? null,
                ];
            }
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'         => true,
        'version'    => date('YmdHis'),
        'edificios'  => $edificios,
        'parroquias' => $parroquias,
        'trabajos'   => $trabajos,
        'recetas'    => $recetas,
        'total'      => count($edificios),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo preparar el catálogo.'
            . (APP_DEBUG ? ' ' . $e->getMessage() : ''),
    ], JSON_UNESCAPED_UNICODE);
}
