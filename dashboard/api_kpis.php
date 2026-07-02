<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Red de seguridad: cualquier error inesperado (incluyendo fatales de PHP)
// SIEMPRE responde con JSON válido en vez de dejar el body vacío, que es lo
// que rompe fetch().json() en el navegador con "Unexpected end of JSON input".
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar el dashboard.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error'  => 'Error interno del servidor al cargar el dashboard.',
            'detail' => APP_DEBUG ? ($err['message'] . ' en ' . $err['file'] . ':' . $err['line']) : null,
        ], JSON_UNESCAPED_UNICODE);
    }
});

requireLogin();
if (!puede('dashboard', 'ver')) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    $pdo = db();
    $catalogo = catalogoDecisionFinal();

    // ---- KPIs agregados (sin filtros: vista global para presentación) ----
    $totales = $pdo->query("
        SELECT
            COUNT(*)                            AS inspecciones,
            COALESCE(SUM(familias),0)           AS familias,
            COALESCE(SUM(hombres),0)            AS hombres,
            COALESCE(SUM(mujeres),0)            AS mujeres,
            COALESCE(SUM(ninos),0)              AS ninos,
            COALESCE(SUM(movilidad_reducida),0) AS movilidad_reducida,
            COALESCE(SUM(adultos_tercera_edad),0) AS adultos_tercera_edad,
            COALESCE(SUM(gestantes),0)          AS gestantes,
            COALESCE(SUM(mascotas),0)           AS mascotas
        FROM inspecciones
    ")->fetch();

    // ---- Distribución por decisión final (semáforo) ----
    $decisionRows = $pdo->query('SELECT decision_final, COUNT(*) AS total FROM inspecciones GROUP BY decision_final')->fetchAll();
    $decision = [];
    foreach ($catalogo as $clave => $meta) {
        $decision[] = ['label' => $meta['corto'], 'total' => 0, 'color' => $meta['color']];
    }
    foreach ($decisionRows as $row) {
        foreach ($catalogo as $clave => $meta) {
            if ($clave === $row['decision_final']) {
                foreach ($decision as &$d) {
                    if ($d['label'] === $meta['corto']) { $d['total'] = (int)$row['total']; }
                }
                unset($d);
            }
        }
    }

    // ---- ¿Existe la tabla de fotos? (compatibilidad con instalaciones que
    // aún no ejecutaron database/actualizacion_v2.sql) ----
    $tieneFotos = tablaFotosExiste();

    // ---- Puntos individuales para el mapa (con conteo de fotos si aplica) ----
    $puntos = [];
    $sqlPuntos = $tieneFotos
        ? "SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  (SELECT COUNT(*) FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id) AS cantidad_fotos,
                  (SELECT ruta FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id ORDER BY f.creado_en ASC LIMIT 1) AS foto_portada
           FROM inspecciones i
           WHERE i.latitud IS NOT NULL AND i.longitud IS NOT NULL"
        : "SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  0 AS cantidad_fotos, NULL AS foto_portada
           FROM inspecciones i
           WHERE i.latitud IS NOT NULL AND i.longitud IS NOT NULL";
    $stmt = $pdo->query($sqlPuntos);
    foreach ($stmt->fetchAll() as $row) {
        $meta = $catalogo[$row['decision_final']] ?? ['color' => '#767c94', 'corto' => $row['decision_final']];
        $puntos[] = [
            'id'        => (int)$row['id'],
            'codigo'    => $row['codigo'],
            'nombre'    => $row['nombre_edificio'],
            'parroquia' => $row['parroquia'],
            'decision'  => $meta['corto'],
            'color'     => $meta['color'],
            'lat'       => (float)$row['latitud'],
            'lng'       => (float)$row['longitud'],
            'fecha'     => $row['fecha_inspeccion'],
            'fotos'     => (int)$row['cantidad_fotos'],
            'portada'   => $row['foto_portada'] ? APP_URL_BASE . $row['foto_portada'] : null,
        ];
    }

    // ---- Secciones geográficas por parroquia: centroide, total y decisión predominante ----
    $porParroquia = [];
    $stmt = $pdo->query("
        SELECT parroquia,
               COUNT(*) AS total,
               AVG(latitud) AS lat,
               AVG(longitud) AS lng
        FROM inspecciones
        WHERE latitud IS NOT NULL AND longitud IS NOT NULL
        GROUP BY parroquia
    ");
    $parroquiasGeo = $stmt->fetchAll();

    $stmtDom = $pdo->prepare("
        SELECT decision_final, COUNT(*) AS n FROM inspecciones WHERE parroquia = :p GROUP BY decision_final ORDER BY n DESC LIMIT 1
    ");
    foreach ($parroquiasGeo as $pg) {
        $stmtDom->execute(['p' => $pg['parroquia']]);
        $dom = $stmtDom->fetch();
        $metaDom = $dom ? ($catalogo[$dom['decision_final']] ?? ['color' => '#767c94']) : ['color' => '#767c94'];
        $porParroquia[] = [
            'parroquia' => $pg['parroquia'],
            'total'     => (int)$pg['total'],
            'lat'       => (float)$pg['lat'],
            'lng'       => (float)$pg['lng'],
            'color'     => $metaDom['color'],
        ];
    }

    // ---- Conteo por parroquia (para el gráfico de barras horizontal, incluye sin coordenadas) ----
    $conteoParroquia = $pdo->query('SELECT parroquia, COUNT(*) AS total FROM inspecciones GROUP BY parroquia ORDER BY total DESC')->fetchAll();

    echo json_encode([
        'totales'        => $totales,
        'decision'       => $decision,
        'puntos'         => $puntos,
        'por_parroquia'  => $conteoParroquia,
        'secciones_geo'  => $porParroquia,
        'actualizado'    => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar el dashboard.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
