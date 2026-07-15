<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

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

    // ---- Alcance NACIONAL ----
    // Estado seleccionado desde el frontend (vista drill-down). Un usuario
    // estadal SIEMPRE queda forzado a su estado, ignorando lo que pida el GET.
    $estadoFiltro = trim((string)($_GET['estado'] ?? ''));
    if (!usuarioEsMaster()) {
        // Un usuario estadal queda forzado a su estado. Si NO tiene estado
        // asignado (cuenta previa al alcance nacional), se comporta como
        // master a efectos de visualización: puede navegar el país.
        $suyo = estadoDelUsuario();
        if ($suyo !== null) {
            $estadoFiltro = $suyo;
        }
    }
    $tieneEstado = $estadoFiltro !== '' && $estadoFiltro !== '__NINGUNO__';

    // Municipio seleccionado (drill-down dentro de un estado). Sólo aplica si
    // hay un estado activo.
    $municipioFiltro = trim((string)($_GET['municipio'] ?? ''));
    $tieneMunicipio = $tieneEstado && $municipioFiltro !== '';

    // Unidad geográfica base del estado activo: 'parroquia' en Distrito
    // Capital, 'municipio' en el resto. Define cómo se agrupa el mapa.
    $unidadBase = $tieneEstado ? unidadBaseDelEstado($estadoFiltro) : 'estado';

    // Filtro de decisión final: llega como la etiqueta corta ("Acceso
    // Permitido", etc.) porque es lo que ya maneja el frontend; aquí se
    // traduce a la clave real de la columna decision_final.
    $decisionFiltroCorto = trim((string)($_GET['decision'] ?? ''));
    $decisionFiltroClave = null;
    foreach ($catalogo as $clave => $meta) {
        if ($meta['corto'] === $decisionFiltroCorto) { $decisionFiltroClave = $clave; break; }
    }
    $tieneDecisionFiltro = $decisionFiltroClave !== null;

    // Filtro por uso de la edificación (Vivienda, Escuela, Hospital, etc.).
    // Se trata como un filtro "de alcance" -- igual que estado/municipio,
    // afecta a TODO el dashboard (KPIs, mapa, semáforo, rankings), no solo
    // a un widget puntual como pasa con el filtro de decisión.
    $usoFiltro = trim((string)($_GET['uso'] ?? ''));
    $tieneUso = $usoFiltro !== '';
    // Opción especial "Sin uso": trae los registros con uso_edificacion en NULL
    // o vacío. Se usa un valor centinela para no chocar con ningún uso real.
    $usoEsSinValor = ($usoFiltro === '__SIN_USO__');
    $sqlUso = $usoEsSinValor
        ? '(uso_edificacion IS NULL OR TRIM(uso_edificacion) = \'\')'
        : 'uso_edificacion = :uso';

    // Filtro por presencia de fotos / archivos adjuntos. Se maneja aparte de
    // $condiciones porque algunas consultas prefijan las condiciones con "i."
    // (alias de tabla) y otras no. Por eso se preparan DOS variantes de la
    // condición: una para consultas sin alias (usa la columna id directa) y
    // otra para las que usan el alias i. Solo aplica si la tabla de fotos existe.
    $fotosFiltro = trim((string)($_GET['fotos'] ?? ''));
    $tieneFotosFiltro = ($fotosFiltro === 'con' || $fotosFiltro === 'sin') && tablaFotosExiste();
    $sqlFotosPlano = '';  // para FROM inspecciones (sin alias)
    $sqlFotosAlias = '';  // para FROM inspecciones i (con alias)
    if ($tieneFotosFiltro) {
        $op = ($fotosFiltro === 'con') ? 'EXISTS' : 'NOT EXISTS';
        $sqlFotosPlano = "$op (SELECT 1 FROM inspeccion_fotos ff WHERE ff.inspeccion_id = inspecciones.id)";
        $sqlFotosAlias = "$op (SELECT 1 FROM inspeccion_fotos ff WHERE ff.inspeccion_id = i.id)";
    }

    // Condición combinable (parroquia Y/O decisión) reutilizada en varias consultas.
    $condiciones = [];
    $paramsFiltro = [];
    if ($tieneEstado)    { $condiciones[] = 'estado = :estado'; $paramsFiltro['estado'] = $estadoFiltro; }
    if ($tieneMunicipio) { $condiciones[] = 'municipio = :municipio'; $paramsFiltro['municipio'] = $municipioFiltro; }
    if ($tieneUso)        { $condiciones[] = $sqlUso; if (!$usoEsSinValor) $paramsFiltro['uso'] = $usoFiltro; }
    if ($tieneFiltro) { $condiciones[] = 'parroquia = :p'; $paramsFiltro['p'] = $parroquiaFiltro; }
    if ($tieneDecisionFiltro) { $condiciones[] = 'decision_final = :d'; $paramsFiltro['d'] = $decisionFiltroClave; }
    if ($tieneFotosFiltro) { $condiciones[] = $sqlFotosPlano; }
    $whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    // Conjunto de condiciones "de territorio" (estado/municipio) SIN el filtro
    // de parroquia ni decisión — se usa como base en consultas que arman su
    // propio WHERE (decisión, secciones geo, rankings), para que respeten el
    // alcance nacional aunque no respeten los demás filtros. El filtro de uso
    // también entra aquí, porque igual que el territorio, debe acotar TODO el
    // dashboard, no solo un widget puntual.
    $condTerritorio = [];
    $paramsTerritorio = [];
    if ($tieneEstado)    { $condTerritorio[] = 'estado = :estado'; $paramsTerritorio['estado'] = $estadoFiltro; }
    if ($tieneMunicipio) { $condTerritorio[] = 'municipio = :municipio'; $paramsTerritorio['municipio'] = $municipioFiltro; }
    if ($tieneUso)        { $condTerritorio[] = $sqlUso; if (!$usoEsSinValor) $paramsTerritorio['uso'] = $usoFiltro; }
    if ($tieneFotosFiltro) { $condTerritorio[] = $sqlFotosPlano; }

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
    // Respeta territorio (estado/municipio) y parroquia, pero nunca la decisión.
    $condDecision = $condTerritorio;
    $paramsDecision = $paramsTerritorio;
    if ($tieneFiltro) { $condDecision[] = 'parroquia = :p'; $paramsDecision['p'] = $parroquiaFiltro; }
    $stmt = $pdo->prepare('SELECT decision_final, COUNT(*) AS total FROM inspecciones ' .
        ($condDecision ? ('WHERE ' . implode(' AND ', $condDecision) . ' ') : '') . 'GROUP BY decision_final');
    $stmt->execute($paramsDecision);
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
    // Se prefijan con "i." solo las condiciones de columna simple. La condición
    // de fotos (una subconsulta EXISTS) no debe prefijarse: se usa su variante
    // con alias ($sqlFotosAlias), que ya referencia i.id internamente.
    $condPuntosArr = [];
    foreach ($condiciones as $c) {
        if ($tieneFotosFiltro && $c === $sqlFotosPlano) {
            $condPuntosArr[] = $sqlFotosAlias;
        } else {
            $condPuntosArr[] = "i.$c";
        }
    }
    $condPuntos = $condPuntosArr ? (' AND ' . implode(' AND ', $condPuntosArr)) : '';
    // Se trae también estado y parroquia: hacen falta para poder ubicar por
    // parroquia las inspecciones que no tienen coordenadas propias.
    $sqlPuntos = $tieneFotos
        ? "SELECT i.id, i.codigo, i.nombre_edificio, i.estado, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  (SELECT COUNT(*) FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id) AS cantidad_fotos,
                  (SELECT ruta FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id ORDER BY f.creado_en ASC LIMIT 1) AS foto_portada
           FROM inspecciones i
           WHERE 1=1$condPuntos"
        : "SELECT i.id, i.codigo, i.nombre_edificio, i.estado, i.parroquia, i.decision_final, i.latitud, i.longitud, i.fecha_inspeccion,
                  0 AS cantidad_fotos, NULL AS foto_portada
           FROM inspecciones i
           WHERE 1=1$condPuntos";
    $stmt = $pdo->prepare($sqlPuntos);
    $stmt->execute($paramsFiltro);
    $filasPuntos = $stmt->fetchAll();

    // --- Detección de coordenadas "por defecto" ---
    // Si un mismo par lat/lng se repite en muchas inspecciones, no es una
    // ubicación real (viene de un valor puesto automáticamente). Esas se
    // reubican dentro de su parroquia para que no se amontonen en un punto.
    $umbralDuplicadas = 5;
    $conteoCoords = [];
    foreach ($filasPuntos as $row) {
        if ($row['latitud'] === null || $row['longitud'] === null) continue;
        $k = round((float)$row['latitud'], 5) . ',' . round((float)$row['longitud'], 5);
        $conteoCoords[$k] = ($conteoCoords[$k] ?? 0) + 1;
    }
    $coordsPorDefecto = [];
    foreach ($conteoCoords as $k => $n) {
        if ($n >= $umbralDuplicadas) $coordsPorDefecto[$k] = true;
    }

    // --- Anclas por parroquia ---
    // Se recogen las inspecciones que SÍ tienen coordenada propia y fiable.
    // Sirven de referencia de dónde hay edificaciones reales dentro de cada
    // parroquia, para no colocar los puntos aproximados en zonas deshabitadas
    // (montañas, embalses…), que es lo que pasa si se reparten por todo el
    // polígono: parroquias como El Junquito o Macarao son casi todo monte.
    $anclas = [];
    foreach ($filasPuntos as $row) {
        if ($row['latitud'] === null || $row['longitud'] === null) continue;
        if ((float)$row['latitud'] == 0.0 && (float)$row['longitud'] == 0.0) continue;
        $k = round((float)$row['latitud'], 5) . ',' . round((float)$row['longitud'], 5);
        if (isset($coordsPorDefecto[$k])) continue;   // coordenada falsa: no sirve de ancla
        $pq = segNorm((string)($row['estado'] ?? '')) . '|' . segNorm((string)($row['parroquia'] ?? ''));
        $anclas[$pq][] = [(float)$row['latitud'], (float)$row['longitud']];
    }

    foreach ($filasPuntos as $row) {
        $meta = $catalogo[$row['decision_final']] ?? ['color' => '#767c94', 'corto' => $row['decision_final']];

        $lat = $row['latitud'];
        $lng = $row['longitud'];

        // ¿Tiene coordenadas propias utilizables?
        $tieneGps = ($lat !== null && $lng !== null
                     && !((float)$lat == 0.0 && (float)$lng == 0.0));
        if ($tieneGps) {
            $k = round((float)$lat, 5) . ',' . round((float)$lng, 5);
            if (isset($coordsPorDefecto[$k])) $tieneGps = false;   // coordenada falsa
        }

        $aprox = false;
        if (!$tieneGps) {
            // Se ubica dentro de su parroquia, apoyándose en los edificios
            // reales de esa misma parroquia (si los hay) para caer en zona urbana.
            $pqKey = segNorm((string)($row['estado'] ?? '')) . '|' . segNorm((string)($row['parroquia'] ?? ''));
            $pt = segUbicarEnParroquia(
                (string)($row['estado'] ?? ''),
                (string)($row['parroquia'] ?? ''),
                (int)$row['id'],                    // semilla: siempre el mismo punto
                $anclas[$pqKey] ?? []
            );
            if ($pt === null) continue;             // sin parroquia -> no se muestra
            [$lat, $lng] = $pt;
            $aprox = true;
        }

        $puntos[] = [
            'id'        => (int)$row['id'],
            'codigo'    => $row['codigo'],
            'nombre'    => $row['nombre_edificio'],
            'parroquia' => $row['parroquia'],
            'decision'  => $meta['corto'],
            'decision_color' => $meta['color'],
            'lat'       => (float)$lat,
            'lng'       => (float)$lng,
            'aprox'     => $aprox,              // true = ubicación aproximada por parroquia
            'fecha'     => $row['fecha_inspeccion'],
            'fotos'     => (int)$row['cantidad_fotos'],
            'portada'   => $row['foto_portada'] ? APP_URL_BASE . $row['foto_portada'] : null,
        ];
    }

    $inspecciones = [];
    if ($tieneFiltro || $tieneUso || $tieneDecisionFiltro) {
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
    // La columna por la que se agrupan las "secciones" del mapa depende del
    // nivel de navegación:
    //   - Sin estado (vista NACIONAL)      -> agrupa por 'estado'
    //   - Estado = Distrito Capital        -> agrupa por 'parroquia' (histórico)
    //   - Estado sin municipio             -> agrupa por 'municipio'
    //   - Estado + municipio               -> agrupa por 'parroquia'
    if (!$tieneEstado) {
        $colGeo = 'estado';
    } elseif ($tieneMunicipio || $unidadBase === 'parroquia') {
        $colGeo = 'parroquia';
    } else {
        $colGeo = 'municipio';
    }

    $porParroquia = [];
    $condGeoArr = $condTerritorio;
    $paramsGeo  = $paramsTerritorio;
    if ($tieneDecisionFiltro) { $condGeoArr[] = 'decision_final = :d'; $paramsGeo['d'] = $decisionFiltroClave; }
    $condGeoArr[] = 'latitud IS NOT NULL AND longitud IS NOT NULL';
    $whereGeo = 'WHERE ' . implode(' AND ', $condGeoArr);

    $stmt = $pdo->prepare("
        SELECT `$colGeo` AS unidad,
               COUNT(*) AS total,
               AVG(latitud) AS lat,
               AVG(longitud) AS lng
        FROM inspecciones
        $whereGeo
        GROUP BY `$colGeo`
    ");
    $stmt->execute($paramsGeo);
    $parroquiasGeo = $stmt->fetchAll();

    // Decisión dominante por unidad (respetando el territorio activo).
    $domTerr = $condTerritorio;
    $domParams0 = $paramsTerritorio;
    $domWhere = ($domTerr ? implode(' AND ', $domTerr) . ' AND ' : '') . "`$colGeo` = :u";
    $stmtDom = $pdo->prepare("
        SELECT decision_final, COUNT(*) AS n FROM inspecciones WHERE $domWhere GROUP BY decision_final ORDER BY n DESC LIMIT 1
    ");
    foreach ($parroquiasGeo as $pg) {
        if ($tieneDecisionFiltro) {
            $metaDom = $catalogo[$decisionFiltroClave] ?? ['color' => '#767c94'];
        } else {
            $stmtDom->execute(array_merge($domParams0, ['u' => $pg['unidad']]));
            $dom = $stmtDom->fetch();
            $metaDom = $dom ? ($catalogo[$dom['decision_final']] ?? ['color' => '#767c94']) : ['color' => '#767c94'];
        }
        $porParroquia[] = [
            // Se conserva la clave 'parroquia' por compatibilidad con el
            // frontend existente; 'unidad' es el nombre real (estado/municipio/parroquia).
            'parroquia' => $pg['unidad'],
            'unidad'    => $pg['unidad'],
            'total'     => (int)$pg['total'],
            'lat'       => (float)$pg['lat'],
            'lng'       => (float)$pg['lng'],
            'color'     => $metaDom['color'],
        ];
    }

    // ---- Agregación NACIONAL por estado (para la vista de todo el país y
    // el filtro de estado del usuario master). No respeta el filtro de estado
    // (siempre lista todos), pero sí el de decisión y el de uso si están activos. ----
    $porEstado = [];
    $condEstadoNal = [];
    $paramsEstadoNal = [];
    if ($tieneDecisionFiltro) { $condEstadoNal[] = 'decision_final = :d'; $paramsEstadoNal['d'] = $decisionFiltroClave; }
    if ($tieneUso) { $condEstadoNal[] = $sqlUso; if (!$usoEsSinValor) $paramsEstadoNal['uso'] = $usoFiltro; }
    if ($tieneFotosFiltro) { $condEstadoNal[] = $sqlFotosPlano; }
    $sqlEstado = 'SELECT estado, COUNT(*) AS total FROM inspecciones' .
        ($condEstadoNal ? (' WHERE ' . implode(' AND ', $condEstadoNal)) : '') .
        " GROUP BY estado ORDER BY total DESC";
    $stmtE = $pdo->prepare($sqlEstado);
    $stmtE->execute($paramsEstadoNal);
    foreach ($stmtE->fetchAll() as $row) {
        if ($row['estado'] === null || $row['estado'] === '') continue;
        $porEstado[] = ['estado' => $row['estado'], 'total' => (int)$row['total']];
    }

    // ---- Conteo por parroquia (para el gráfico de barras horizontal, incluye sin coordenadas).
    // Respeta el filtro de decisión, para que el ranking normal también se pueda ver
    // "filtrado" cuando se elige una decisión desde la gráfica de semáforo. ----
    // El gráfico de barras usa la misma unidad base que el mapa: estado en la
    // vista nacional, municipio dentro de un estado, parroquia en DC o al
    // entrar a un municipio.
    $condConteo = $condTerritorio;
    $paramsConteo = $paramsTerritorio;
    if ($tieneDecisionFiltro) { $condConteo[] = 'decision_final = :d'; $paramsConteo['d'] = $decisionFiltroClave; }
    $whereConteo = $condConteo ? ('WHERE ' . implode(' AND ', $condConteo)) : '';
    $sqlConteoParroquia = "SELECT `$colGeo` AS parroquia, COUNT(*) AS total FROM inspecciones $whereConteo GROUP BY `$colGeo` ORDER BY total DESC";
    $stmt = $pdo->prepare($sqlConteoParroquia);
    $stmt->execute($paramsConteo);
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
        'por_estado'      => $porEstado,
        'kpis_custom'     => $kpisCustom,
        'parroquia_filtro'=> $tieneFiltro ? $parroquiaFiltro : null,
        'decision_filtro' => $tieneDecisionFiltro ? $decisionFiltroCorto : null,
        'uso_filtro'      => $tieneUso ? $usoFiltro : null,
        // Contexto de navegación nacional para el frontend
        'nivel'           => (!$tieneEstado ? 'nacional' : ($tieneMunicipio || $unidadBase === 'parroquia' ? 'parroquia' : 'municipio')),
        'estado_filtro'   => $tieneEstado ? $estadoFiltro : null,
        'municipio_filtro'=> $tieneMunicipio ? $municipioFiltro : null,
        'unidad_base'     => $colGeo,
        'es_master'       => usuarioEsMaster() || estadoDelUsuario() === null,
        'actualizado'     => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar el dashboard.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
