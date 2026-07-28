<?php
/**
 * PANEL DIRECTIVO · datos agregados.
 *
 * Reúne, en UNA sola llamada, lo que un directivo necesita ver de la
 * obra sin entrar a ninguna ficha, ordenado por lo más importante:
 *   1) Avance general + edificaciones que más faltan.
 *   2) Materiales y metros totales de la obra.
 *   3) Etapas (sin empezar / en trabajo / terminadas).
 *
 * Reutiliza funciones existentes:
 *   - segConsolidadoMateriales(): materiales y metros de toda la obra.
 *   - recArbolAvance(): avance real por edificio (incluye áreas comunes).
 *   - los scopes territoriales del usuario (aplicarScope*).
 *
 * El avance por edificio se toma del árbol (mismo número que ve el
 * supervisor en la ficha), no de un promedio distinto, para que las
 * cifras cuadren en todo el sistema.
 */

/**
 * Devuelve el panel completo:
 * [
 *   'general'    => ['avance'=>int, 'total'=>int, 'con_obra'=>int],
 *   'etapas'     => ['sin_empezar'=>int, 'en_trabajo'=>int, 'terminadas'=>int],
 *   'obra'       => ['m2'=>float, 'materiales'=>[...], 'apartamentos'=>int],
 *   'edificios'  => [ ['nombre','parroquia','avance','m2','estado'], ... ],
 * ]
 */
function panelDirectivoDatos(): array
{
    $out = [
        'general'   => ['avance' => 0, 'total' => 0, 'con_obra' => 0],
        'etapas'    => ['sin_empezar' => 0, 'en_trabajo' => 0, 'terminadas' => 0],
        'obra'      => ['m2' => 0.0, 'materiales' => [], 'apartamentos' => 0],
        'edificios' => [],
    ];

    // --- Edificaciones con levantamiento cerrado, dentro del scope ---
    $conds = ['re.completado = 1'];
    $params = [];
    if (function_exists('aplicarScopeEstado'))    aplicarScopeEstado($conds, $params, 'i');
    if (function_exists('aplicarScopeParroquia')) aplicarScopeParroquia($conds, $params, 'i');
    $where = 'WHERE ' . implode(' AND ', $conds);

    try {
        $st = db()->prepare("
            SELECT i.id, i.nombre_edificio, i.parroquia, re.id AS edificio_id
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
              ORDER BY i.nombre_edificio
        ");
        $st->execute($params);
        $filas = $st->fetchAll();
    } catch (Throwable $e) {
        $filas = [];
    }

    $sumaAvance = 0;
    $nConAvance = 0;

    foreach ($filas as $f) {
        $edId = (int)$f['edificio_id'];
        $avance = 0;
        try {
            $arbol = recArbolAvance($edId);
            $avance = (int)($arbol['avance_edificio'] ?? 0);
        } catch (Throwable $e) {
            $avance = 0;
        }

        // Etapa según el avance.
        if ($avance >= 100)      { $out['etapas']['terminadas']++;   $estado = 'terminada'; }
        elseif ($avance > 0)     { $out['etapas']['en_trabajo']++;   $estado = 'en_trabajo'; }
        else                     { $out['etapas']['sin_empezar']++;  $estado = 'sin_empezar'; }

        $sumaAvance += $avance;
        $nConAvance++;

        $out['edificios'][] = [
            'nombre'    => $f['nombre_edificio'] ?: 'Sin nombre',
            'parroquia' => $f['parroquia'] ?: 'Sin parroquia',
            'avance'    => $avance,
            'estado'    => $estado,
        ];
    }

    // Avance general = promedio de las edificaciones cerradas.
    $out['general']['total']    = count($filas);
    $out['general']['con_obra'] = $nConAvance;
    $out['general']['avance']   = $nConAvance > 0 ? (int)round($sumaAvance / $nConAvance) : 0;

    // "Las que más faltan" = menor avance primero (y a igualdad, por
    // nombre para que el orden sea estable).
    usort($out['edificios'], function ($a, $b) {
        return [$a['avance'], $a['nombre']] <=> [$b['avance'], $b['nombre']];
    });

    // --- Materiales y metros totales (reusa el consolidado existente) ---
    try {
        $cons = segConsolidadoMateriales();
        $out['obra']['m2']           = (float)($cons['m2_total'] ?? 0);
        $out['obra']['apartamentos'] = (int)($cons['apartamentos'] ?? 0);
        // materiales viene como lista de ['material','cantidad','unidad'].
        $mats = [];
        foreach (($cons['materiales'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $mats[] = [
                'material' => $m['material'] ?? '',
                'cantidad' => (float)($m['cantidad'] ?? 0),
                'unidad'   => $m['unidad'] ?? '',
            ];
        }
        usort($mats, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);
        $out['obra']['materiales'] = $mats;
    } catch (Throwable $e) {
        // Si falla, el panel igual muestra avance y etapas.
    }

    return $out;
}
