<?php
/**
 * DASHBOARD TÉCNICO / CONSTRUCCIÓN — funciones de agregación.
 *
 * Enfoque 100% constructivo: materiales a comprar, metros² de trabajo,
 * daño estructural. Desglosado por parroquia, por cantidad de pisos y
 * por edificio. Sin datos sociales.
 *
 * Reutiliza segConsolidadoMateriales() (ya calcula materiales, trabajos
 * y m² del scope) y añade los cortes por pisos y por edificio.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/territorial.php';
require_once __DIR__ . '/seguimiento.php';

/**
 * WHERE con el scope del usuario + filtro de parroquia opcional.
 * Sólo edificaciones con levantamiento cerrado y completo, igual que
 * segConsolidadoMateriales, para que las cifras cuadren.
 */
function techScopeWhere(array $filtros = []): array
{
    $conds = ['re.completado = 1'];
    if (function_exists('recSqlEdificioCompleto')) {
        $conds[] = recSqlEdificioCompleto('re');
    }
    $par = [];
    if (function_exists('aplicarScopeEstado'))    aplicarScopeEstado($conds, $par, 'i');
    if (function_exists('aplicarScopeParroquia')) aplicarScopeParroquia($conds, $par, 'i');
    if (!empty($filtros['parroquia'])) {
        $conds[] = 'i.parroquia = :f_parr';
        $par['f_parr'] = $filtros['parroquia'];
    }
    return ['WHERE ' . implode(' AND ', $conds), $par];
}

/**
 * Metros² de trabajo agrupados por CANTIDAD DE PISOS (rangos).
 * Suma los metros de todas las reparaciones (ambiente + elemento_piso)
 * de los edificios de cada rango de altura.
 */
function techPorCantidadPisos(array $filtros = []): array
{
    [$where, $par] = techScopeWhere($filtros);
    $rangos = [
        '1'     => ['etiqueta' => '1 piso',        'min' => 1,  'max' => 1,   'edificios' => 0, 'm2' => 0.0],
        '2-4'   => ['etiqueta' => '2 a 4 pisos',   'min' => 2,  'max' => 4,   'edificios' => 0, 'm2' => 0.0],
        '5-9'   => ['etiqueta' => '5 a 9 pisos',   'min' => 5,  'max' => 9,   'edificios' => 0, 'm2' => 0.0],
        '10-19' => ['etiqueta' => '10 a 19 pisos', 'min' => 10, 'max' => 19,  'edificios' => 0, 'm2' => 0.0],
        '20+'   => ['etiqueta' => '20 o más pisos','min' => 20, 'max' => 999, 'edificios' => 0, 'm2' => 0.0],
    ];
    // Edificios por rango.
    try {
        $st = db()->prepare("
            SELECT COALESCE(i.num_pisos,0) AS pisos, COUNT(DISTINCT i.id) AS edificios
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
             GROUP BY i.num_pisos
        ");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $p = (int)$r['pisos'];
            if ($p < 1) continue;
            foreach ($rangos as $k => $rg) {
                if ($p >= $rg['min'] && $p <= $rg['max']) {
                    $rangos[$k]['edificios'] += (int)$r['edificios'];
                    break;
                }
            }
        }
    } catch (Throwable $e) {}

    // Metros² por rango (reparaciones de ambientes de cada edificio).
    try {
        $st = db()->prepare("
            SELECT COALESCE(i.num_pisos,0) AS pisos, SUM(rr.metros_cuadrados) AS m2
              FROM rec_reparacion rr
              JOIN rec_ambiente am ON am.id = rr.ref_id AND rr.nivel = 'ambiente'
              JOIN rec_apartamento ap ON ap.id = am.apartamento_id
              JOIN rec_piso pi ON pi.id = ap.piso_id
              JOIN rec_edificio re ON re.id = pi.edificio_id
              JOIN inspecciones i ON i.id = re.inspeccion_id
              $where
               AND rr.metros_cuadrados > 0
             GROUP BY i.num_pisos
        ");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $p = (int)$r['pisos'];
            if ($p < 1) continue;
            foreach ($rangos as $k => $rg) {
                if ($p >= $rg['min'] && $p <= $rg['max']) {
                    $rangos[$k]['m2'] += (float)$r['m2'];
                    break;
                }
            }
        }
    } catch (Throwable $e) {}

    foreach ($rangos as $k => $rg) $rangos[$k]['m2'] = round($rg['m2'], 2);
    return array_values($rangos);
}

/**
 * Por edificio: metros² de trabajo, daño estructural (colapso) y pisos.
 * Ordenado por más metros de trabajo (los que más obra requieren).
 */
function techPorEdificio(array $filtros = [], int $limite = 40): array
{
    [$where, $par] = techScopeWhere($filtros);
    $filas = [];
    try {
        $st = db()->prepare("
            SELECT i.id, i.nombre_edificio, i.parroquia,
                   COALESCE(i.num_pisos,0) AS num_pisos,
                   COALESCE(i.colapso_estructura,'No') AS colapso,
                   re.id AS edificio_id
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
        ");
        $st->execute($par);
        $edificios = $st->fetchAll();
    } catch (Throwable $e) { $edificios = []; }

    foreach ($edificios as $e) {
        $edId = (int)$e['edificio_id'];
        $m2 = 0.0;
        try {
            // Reusar la función existente que suma trabajos del edificio.
            $trabajos = recTrabajosDeEdificio($edId);
            foreach ($trabajos as $cant) $m2 += (float)$cant;
        } catch (Throwable $ex) {}
        if ($m2 <= 0) continue;   // sólo los que tienen obra registrada
        $filas[] = [
            'nombre'    => $e['nombre_edificio'] ?: 'Sin nombre',
            'parroquia' => $e['parroquia'] ?: 'Sin parroquia',
            'num_pisos' => (int)$e['num_pisos'],
            'colapso'   => $e['colapso'],
            'm2'        => round($m2, 2),
        ];
    }
    usort($filas, fn($a, $b) => $b['m2'] <=> $a['m2']);
    return array_slice($filas, 0, $limite);
}

/**
 * Conteo de daño estructural (colapso) del scope, para el resumen.
 */
function techDanoEstructural(array $filtros = []): array
{
    [$where, $par] = techScopeWhere($filtros);
    $out = ['total' => 0, 'colapso_total' => 0, 'colapso_parcial' => 0, 'sin_colapso' => 0];
    try {
        $st = db()->prepare("
            SELECT COALESCE(i.colapso_estructura,'No') AS colapso, COUNT(DISTINCT i.id) AS n
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
             GROUP BY i.colapso_estructura
        ");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $c = strtolower(trim($r['colapso'] ?? ''));
            $n = (int)$r['n'];
            $out['total'] += $n;
            if ($c === 'total')        $out['colapso_total']  += $n;
            elseif ($c === 'parcial')  $out['colapso_parcial']+= $n;
            else                       $out['sin_colapso']    += $n;
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Reúne todo el dashboard técnico en un llamado.
 * Reutiliza segConsolidadoMateriales para materiales/trabajos/parroquias.
 */
function techDashboard(array $filtros = []): array
{
    // segConsolidadoMateriales respeta el scope del usuario pero NO el
    // filtro de parroquia por argumento; si se filtró por parroquia,
    // el corte por parroquia igual queda disponible en su salida y aquí
    // sólo mostramos la elegida en las tarjetas.
    $cons = [];
    try { $cons = segConsolidadoMateriales(); } catch (Throwable $e) { $cons = []; }

    return [
        'consolidado' => $cons,                        // materiales, trabajos, m2, parroquias
        'pisos'       => techPorCantidadPisos($filtros),
        'edificios'   => techPorEdificio($filtros),
        'dano'        => techDanoEstructural($filtros),
        'filtros'     => $filtros,
    ];
}
