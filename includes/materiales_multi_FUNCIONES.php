<?php
/**
 * MATERIALES CONSOLIDADOS DE VARIOS EDIFICIOS, DESGLOSADOS POR PISO.
 *
 * El usuario selecciona varios edificios y obtiene cuánto material se
 * necesita, juntando los pisos equivalentes de todos (el piso 1 de
 * todos sumado, el piso 2 de todos, etc.), más las áreas comunes y el
 * total general. Pensado para comprar por lote.
 *
 * Reutiliza las funciones de trabajos/materiales por piso ya existentes
 * en seguimiento.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/territorial.php';
require_once __DIR__ . '/seguimiento.php';

/**
 * Lista de edificios que el usuario puede seleccionar: los que tienen
 * levantamiento registrado, dentro de su scope. Con datos útiles para
 * elegir (parroquia, pisos, si tiene obra).
 */
function matEdificiosSeleccionables(array $filtros = []): array
{
    $cond = ['re.completado = 1'];
    $par = [];
    if (function_exists('aplicarScopeEstado'))    aplicarScopeEstado($cond, $par, 'i');
    if (function_exists('aplicarScopeParroquia')) aplicarScopeParroquia($cond, $par, 'i');
    if (!empty($filtros['parroquia'])) {
        $cond[] = 'i.parroquia = :parr';
        $par['parr'] = $filtros['parroquia'];
    }
    $where = ' WHERE ' . implode(' AND ', $cond);
    try {
        $st = db()->prepare("
            SELECT re.id AS edificio_id,
                   COALESCE(NULLIF(TRIM(i.nombre_edificio), ''), i.codigo,
                            CONCAT('Edificio #', re.id)) AS nombre,
                   COALESCE(i.parroquia, '') AS parroquia,
                   COALESCE(i.num_pisos, 0) AS num_pisos,
                   COALESCE(i.colapso_estructura, 'No') AS colapso
              FROM rec_edificio re
              JOIN inspecciones i ON i.id = re.inspeccion_id
              $where
             ORDER BY i.parroquia, nombre
        ");
        $st->execute($par);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Números de piso presentes en un conjunto de edificios.
 */
function matPisosDeEdificios(array $edificioIds): array
{
    $edificioIds = array_values(array_unique(array_map('intval', $edificioIds)));
    if (!$edificioIds) return [];
    $in = implode(',', array_fill(0, count($edificioIds), '?'));
    try {
        $st = db()->prepare("
            SELECT DISTINCT numero_piso
              FROM rec_piso
             WHERE edificio_id IN ($in)
             ORDER BY numero_piso
        ");
        $st->execute($edificioIds);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
}

/**
 * Trabajos de un número de piso, pero SOLO de los edificios indicados.
 * (Versión de recTrabajosDeEdificioPorPiso para varios edificios,
 * sumando los pisos equivalentes.)
 * Devuelve tipo_trabajo => m².
 */
function matTrabajosDePisoEnEdificios(int $numeroPiso, array $edificioIds): array
{
    $out = [];
    foreach ($edificioIds as $edId) {
        $tr = recTrabajosDeEdificioPorPiso((int)$edId, $numeroPiso);
        foreach ($tr as $clave => $m2) {
            $out[$clave] = ($out[$clave] ?? 0) + (float)$m2;
        }
    }
    return $out;
}

/**
 * Trabajos de las ÁREAS COMUNES de los edificios indicados.
 * Las áreas comunes no cuelgan de un piso: se suman aparte.
 * Devuelve tipo_trabajo => m².
 */
function matTrabajosAreasComunes(array $edificioIds): array
{
    $edificioIds = array_values(array_unique(array_map('intval', $edificioIds)));
    if (!$edificioIds) return [];
    $in = implode(',', array_fill(0, count($edificioIds), '?'));
    $out = [];
    try {
        $st = db()->prepare("
            SELECT ac.tipo_trabajo, SUM(ac.metros_cuadrados) AS m2
              FROM rec_area_comun ac
             WHERE ac.edificio_id IN ($in)
               AND ac.necesita_reparacion = 1
               AND ac.tipo_trabajo IS NOT NULL AND ac.tipo_trabajo <> ''
               AND ac.metros_cuadrados > 0
             GROUP BY ac.tipo_trabajo
        ");
        $st->execute($edificioIds);
        foreach ($st->fetchAll() as $r) {
            $out[$r['tipo_trabajo']] = (float)$r['m2'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Convierte un mapa tipo_trabajo => m² en materiales legibles
 * (nombre + unidad => cantidad) usando el cálculo estándar con holgura.
 */
function matMaterialesDeTrabajos(array $trabajos): array
{
    if (!$trabajos) return [];
    $mats = [];
    try {
        foreach (recMaterialesPorTrabajo($trabajos) as $mat => $d) {
            $mats[$mat . ' (' . $d['unidad'] . ')'] = $d['cantidad'];
        }
    } catch (Throwable $e) {}
    ksort($mats, SORT_NATURAL | SORT_FLAG_CASE);
    return $mats;
}

/**
 * Nombres legibles de los tipos de trabajo (clave => nombre).
 */
function matNombresTrabajo(): array
{
    $n = [];
    try {
        foreach (recTiposTrabajo() as $t) $n[$t['clave']] = $t['nombre'];
    } catch (Throwable $e) {}
    return $n;
}

/**
 * EL CÁLCULO PRINCIPAL.
 *
 * Dada una lista de edificios, arma:
 *  - por_piso: para cada número de piso, los materiales consolidados
 *    (sumando ese piso en todos los edificios) y los m² por trabajo.
 *  - areas_comunes: materiales y trabajos de las áreas comunes.
 *  - total: todos los materiales sumados (pisos + áreas comunes).
 *  - edificios: los que se incluyeron (nombre, parroquia).
 */
function matConsolidadoPorPiso(array $edificioIds): array
{
    $edificioIds = array_values(array_unique(array_filter(array_map('intval', $edificioIds))));
    $nombres = matNombresTrabajo();

    $out = [
        'edificios'     => [],
        'por_piso'      => [],
        'areas_comunes' => ['materiales' => [], 'por_trabajo' => []],
        'total'         => [],
        'pisos'         => [],
    ];
    if (!$edificioIds) return $out;

    // Datos de los edificios elegidos.
    $in = implode(',', array_fill(0, count($edificioIds), '?'));
    try {
        $st = db()->prepare("
            SELECT re.id AS edificio_id,
                   COALESCE(NULLIF(TRIM(i.nombre_edificio), ''), i.codigo,
                            CONCAT('Edificio #', re.id)) AS nombre,
                   COALESCE(i.parroquia, '') AS parroquia
              FROM rec_edificio re
              JOIN inspecciones i ON i.id = re.inspeccion_id
             WHERE re.id IN ($in)
             ORDER BY nombre
        ");
        $st->execute($edificioIds);
        $out['edificios'] = $st->fetchAll() ?: [];
    } catch (Throwable $e) {}

    // Acumulador del total general (materiales) y de los trabajos totales.
    $totalTrabajos = [];

    // --- Por piso ---
    $pisos = matPisosDeEdificios($edificioIds);
    $out['pisos'] = $pisos;
    foreach ($pisos as $np) {
        $trabajos = matTrabajosDePisoEnEdificios($np, $edificioIds);
        if (!$trabajos) continue;

        $porTrabajo = [];
        foreach ($trabajos as $clave => $m2) {
            $porTrabajo[$nombres[$clave] ?? $clave] = round($m2, 2);
            $totalTrabajos[$clave] = ($totalTrabajos[$clave] ?? 0) + $m2;
        }
        ksort($porTrabajo, SORT_NATURAL | SORT_FLAG_CASE);

        $out['por_piso'][] = [
            'numero_piso' => $np,
            'etiqueta'    => $np === 0 ? 'Planta baja' : ('Piso ' . $np),
            'materiales'  => matMaterialesDeTrabajos($trabajos),
            'por_trabajo' => $porTrabajo,
        ];
    }

    // --- Áreas comunes ---
    $trabajosAC = matTrabajosAreasComunes($edificioIds);
    if ($trabajosAC) {
        $porTrabajoAC = [];
        foreach ($trabajosAC as $clave => $m2) {
            $porTrabajoAC[$nombres[$clave] ?? $clave] = round($m2, 2);
            $totalTrabajos[$clave] = ($totalTrabajos[$clave] ?? 0) + $m2;
        }
        ksort($porTrabajoAC, SORT_NATURAL | SORT_FLAG_CASE);
        $out['areas_comunes'] = [
            'materiales'  => matMaterialesDeTrabajos($trabajosAC),
            'por_trabajo' => $porTrabajoAC,
        ];
    }

    // --- Total general (todos los trabajos juntos → materiales) ---
    $out['total'] = matMaterialesDeTrabajos($totalTrabajos);

    return $out;
}
