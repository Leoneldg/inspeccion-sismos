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

    $parroquiaFiltro = trim((string)($_GET['parroquia'] ?? ''));
    $tieneFiltro = $parroquiaFiltro !== '';

    // Filtro de decisión final: llega como la etiqueta corta ("Acceso
    // Permitido", etc.) porque es lo que ya maneja el frontend; aquí se
    // traduce a la clave real de la columna decision_final.
    $decisionFiltroCorto = trim((string)($_GET['decision'] ?? ''));
    $decisionFiltroClave = null;
    foreach ($catalogo as $clave => $meta) {
        if ($meta['corto'] === $decisionFiltroCorto) { $decisionFiltroClave = $clave; break; }
    }
    $tieneDecisionFiltro = $decisionFiltroClave !== null;

    // Condición combinable (parroquia Y/O decisión) reutilizada en varias consultas.
    $condiciones = [];
    $paramsFiltro = [];
    if ($tieneFiltro) { $condiciones[] = 'parroquia = :p'; $paramsFiltro['p'] = $parroquiaFiltro; }
    if ($tieneDecisionFiltro) { $condiciones[] = 'decision_final = :d'; $paramsFiltro['d'] = $decisionFiltroClave; }
    $whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    // ---- KPIs agregados (respeta ambos filtros) ----
    $stmt = $pdo->prepare("
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
        $whereSql
    ");
    $stmt->execute($paramsFiltro);
    $totales = $stmt->fetch();

    // ---- Distribución por decisión final (semáforo). Respeta el filtro de
    // parroquia, pero NUNCA el de decisión (es la fuente del propio filtro:
    // siempre deben verse las 3 barras para poder elegir/comparar). ----
    $stmt = $pdo->prepare('SELECT decision_final, COUNT(*) AS total FROM inspecciones ' .
        ($tieneFiltro ? 'WHERE parroquia = :p ' : '') . 'GROUP BY decision_final');
    $stmt->execute($tieneFiltro ? ['p' => $parroquiaFiltro] : []);
    $decisionRows = $stmt->fetchAll();
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

    // ---- Puntos individuales para el mapa (con conteo de fotos si aplica; respeta ambos filtros) ----
    $puntos = [];
    $condPuntos = $condiciones ? (' AND ' . implode(' AND ', array_map(fn($c) => "i.$c", $condiciones))) : '';
    $sqlPuntos = $tieneFotos
        ? "SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  (SELECT COUNT(*) FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id) AS cantidad_fotos,
                  (SELECT ruta FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id ORDER BY f.creado_en ASC LIMIT 1) AS foto_portada
           FROM inspecciones i
           WHERE i.latitud IS NOT NULL AND i.longitud IS NOT NULL$condPuntos"
        : "SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  0 AS cantidad_fotos, NULL AS foto_portada
           FROM inspecciones i
           WHERE i.latitud IS NOT NULL AND i.longitud IS NOT NULL$condPuntos";
    $stmt = $pdo->prepare($sqlPuntos);
    $stmt->execute($paramsFiltro);
    foreach ($stmt->fetchAll() as $row) {
        $meta = $catalogo[$row['decision_final']] ?? ['color' => '#767c94', 'corto' => $row['decision_final']];
        $puntos[] = [
            'id'        => (int)$row['id'],
            'codigo'    => $row['codigo'],
            'nombre'    => $row['nombre_edificio'],
            'parroquia' => $row['parroquia'],
            'decision'  => $meta['corto'],
            'decision_color' => $meta['color'],
            'lat'       => (float)$row['latitud'],
            'lng'       => (float)$row['longitud'],
            'fecha'     => $row['fecha_inspeccion'],
            'fotos'     => (int)$row['cantidad_fotos'],
            'portada'   => $row['foto_portada'] ? APP_URL_BASE . $row['foto_portada'] : null,
        ];
    }

    $inspecciones = [];
    if ($tieneFiltro || $tieneDecisionFiltro) {
        $stmt = $pdo->prepare("SELECT id, nombre_edificio, decision_final FROM inspecciones $whereSql ORDER BY nombre_edificio");
        $stmt->execute($paramsFiltro);
        foreach ($stmt->fetchAll() as $row) {
            $meta = $catalogo[$row['decision_final']] ?? ['color' => '#767c94', 'corto' => $row['decision_final']];
            $inspecciones[] = [
                'id' => (int)$row['id'],
                'nombre' => $row['nombre_edificio'],
                'decision' => $meta['corto'],
                'decision_color' => $meta['color'],
            ];
        }
    }

    // ---- Secciones geográficas por parroquia: centroide, total y decisión
    // predominante. Respeta el filtro de decisión (si hay uno activo, el
    // total y el color mostrado son solo de esa decisión). ----
    $porParroquia = [];
    $condGeo = $tieneDecisionFiltro ? 'AND decision_final = :d' : '';
    $stmt = $pdo->prepare("
        SELECT parroquia,
               COUNT(*) AS total,
               AVG(latitud) AS lat,
               AVG(longitud) AS lng
        FROM inspecciones
        WHERE latitud IS NOT NULL AND longitud IS NOT NULL $condGeo
        GROUP BY parroquia
    ");
    $stmt->execute($tieneDecisionFiltro ? ['d' => $decisionFiltroClave] : []);
    $parroquiasGeo = $stmt->fetchAll();

    $stmtDom = $pdo->prepare("
        SELECT decision_final, COUNT(*) AS n FROM inspecciones WHERE parroquia = :p GROUP BY decision_final ORDER BY n DESC LIMIT 1
    ");
    foreach ($parroquiasGeo as $pg) {
        if ($tieneDecisionFiltro) {
            // Con filtro de decisión activo, el color de la sección es
            // directamente el de esa decisión (no hace falta calcular la dominante).
            $metaDom = $catalogo[$decisionFiltroClave] ?? ['color' => '#767c94'];
        } else {
            $stmtDom->execute(['p' => $pg['parroquia']]);
            $dom = $stmtDom->fetch();
            $metaDom = $dom ? ($catalogo[$dom['decision_final']] ?? ['color' => '#767c94']) : ['color' => '#767c94'];
        }
        $porParroquia[] = [
            'parroquia' => $pg['parroquia'],
            'total'     => (int)$pg['total'],
            'lat'       => (float)$pg['lat'],
            'lng'       => (float)$pg['lng'],
            'color'     => $metaDom['color'],
        ];
    }

    // ---- Conteo por parroquia (para el gráfico de barras horizontal, incluye sin coordenadas).
    // Respeta el filtro de decisión, para que el ranking normal también se pueda ver
    // "filtrado" cuando se elige una decisión desde la gráfica de semáforo. ----
    $sqlConteoParroquia = 'SELECT parroquia, COUNT(*) AS total FROM inspecciones' .
        ($tieneDecisionFiltro ? ' WHERE decision_final = :d' : '') . ' GROUP BY parroquia ORDER BY total DESC';
    $stmt = $pdo->prepare($sqlConteoParroquia);
    $stmt->execute($tieneDecisionFiltro ? ['d' => $decisionFiltroClave] : []);
    $conteoParroquia = $stmt->fetchAll();

    // ---- Conteo por parroquia + decisión final (para el ranking filtrable
    // "parroquias con más casos en rojo/amarillo/verde"). No respeta ningún
    // filtro: siempre es la foto completa, para poder rankear todas las decisiones. ----
    $conteoParroquiaDecision = [];
    $stmt = $pdo->query('SELECT parroquia, decision_final, COUNT(*) AS total FROM inspecciones GROUP BY parroquia, decision_final');
    foreach ($stmt->fetchAll() as $row) {
        $meta = $catalogo[$row['decision_final']] ?? ['corto' => $row['decision_final'], 'color' => '#767c94'];
        $conteoParroquiaDecision[] = [
            'parroquia' => $row['parroquia'],
            'decision'  => $meta['corto'],
            'color'     => $meta['color'],
            'total'     => (int)$row['total'],
        ];
    }

    // ---- KPIs personalizados (definidos en Configuración del Sistema).
    // El nombre de columna SIEMPRE se valida contra catalogoCamposKpi()
    // (lista blanca) antes de interpolarlo en SQL, porque los nombres de
    // columna no se pueden pasar como parámetro con PDO. ----
    $kpisCustom = [];
    $camposKpiValidos = catalogoCamposKpi();
    foreach (obtenerConfigKpisCustom() as $def) {
        $campo = $def['campo'] ?? '';
        if (!isset($camposKpiValidos[$campo])) {
            continue; // campo desconocido/no vigente: se ignora en vez de romper el dashboard
        }
        $meta = $camposKpiValidos[$campo];

        if (($def['tipo'] ?? '') === 'conteo') {
            $condsKpi = $condiciones;
            $paramsKpi = $paramsFiltro;
            $condsKpi[] = "$campo = :kpi_valor";
            $paramsKpi['kpi_valor'] = (string)($def['valor'] ?? '');
            $sql = 'SELECT COUNT(*) AS n FROM inspecciones' . ($condsKpi ? ' WHERE ' . implode(' AND ', $condsKpi) : '');
            $stmtKpi = $pdo->prepare($sql);
            $stmtKpi->execute($paramsKpi);
            $kpisCustom[$def['id']] = (int)$stmtKpi->fetch()['n'];
        } elseif ($meta['tipo'] === 'numero' && in_array($def['tipo'] ?? '', ['suma', 'promedio'], true)) {
            $fn = $def['tipo'] === 'promedio' ? 'AVG' : 'SUM';
            $sql = "SELECT COALESCE($fn($campo),0) AS n FROM inspecciones $whereSql";
            $stmtKpi = $pdo->prepare($sql);
            $stmtKpi->execute($paramsFiltro);
            $n = (float)$stmtKpi->fetch()['n'];
            $kpisCustom[$def['id']] = $def['tipo'] === 'promedio' ? round($n, 1) : $n;
        }
    }

    echo json_encode([
        'totales'         => $totales,
        'decision'        => $decision,
        'puntos'          => $puntos,
        'inspecciones'    => $inspecciones,
        'por_parroquia'   => $conteoParroquia,
        'por_parroquia_decision' => $conteoParroquiaDecision,
        'secciones_geo'   => $porParroquia,
        'kpis_custom'     => $kpisCustom,
        'parroquia_filtro'=> $tieneFiltro ? $parroquiaFiltro : null,
        'decision_filtro' => $tieneDecisionFiltro ? $decisionFiltroCorto : null,
        'actualizado'     => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar el dashboard.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
