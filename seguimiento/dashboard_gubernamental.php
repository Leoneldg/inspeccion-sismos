<?php
/**
 * DASHBOARD DE CONTROL GUBERNAMENTAL — funciones de agregación.
 *
 * Reúne todos los levantamientos en cifras para la toma de decisiones:
 * habitabilidad (semáforo), personas afectadas, por parroquia, por
 * cantidad de pisos, por uso de la edificación, riesgos y materiales.
 *
 * Todas respetan el scope territorial del usuario (estado/parroquia).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/territorial.php';

/**
 * Arma el WHERE con el scope del usuario y filtros opcionales.
 * Devuelve [sqlWhere, params].
 */
function dashScopeWhere(array $filtros = [], string $alias = 'i'): array
{
    $cond = []; $par = [];
    if (function_exists('aplicarScopeEstado'))    aplicarScopeEstado($cond, $par, $alias);
    if (function_exists('aplicarScopeParroquia')) aplicarScopeParroquia($cond, $par, $alias);
    if (!empty($filtros['parroquia'])) {
        $cond[] = "$alias.parroquia = :f_parr";
        $par['f_parr'] = $filtros['parroquia'];
    }
    $where = $cond ? (' WHERE ' . implode(' AND ', $cond)) : '';
    return [$where, $par];
}

/**
 * Clasifica un valor de decision_final en verde / amarillo / rojo / otro.
 * Usa el texto real que aparece en los datos.
 */
function dashClasificarColor(?string $decision): string
{
    // strtolower basta: las palabras clave que se buscan no llevan
    // mayúsculas acentuadas. Se evita mb_strtolower para no depender de
    // la extensión mbstring en este punto.
    $d = strtolower(trim($decision ?? ''));
    if ($d === '') return 'sin';
    if (strpos($d, 'no permitido') !== false || strpos($d, 'insegura') !== false) return 'rojo';
    if (strpos($d, 'restringido') !== false || strpos($d, 'precauci') !== false) return 'amarillo';
    if (strpos($d, 'permitido') !== false || strpos($d, 'inspeccionada') !== false) return 'verde';
    return 'sin';
}

/**
 * Totales generales: cuántas edificaciones, personas, y el semáforo de
 * habitabilidad (verde/amarillo/rojo). El corazón del dashboard.
 */
function dashResumenGeneral(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $out = [
        'total_edificaciones' => 0,
        'total_personas'      => 0,
        'verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'sin' => 0,
        'personas_verde' => 0, 'personas_amarillo' => 0, 'personas_rojo' => 0, 'personas_sin' => 0,
    ];
    try {
        $st = db()->prepare("SELECT decision_final, COALESCE(numero_personas,0) AS personas
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $out['total_edificaciones']++;
            $p = (int)$r['personas'];
            $out['total_personas'] += $p;
            $c = dashClasificarColor($r['decision_final']);
            $out[$c]++;
            $out['personas_' . $c] += $p;
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Por parroquia: total de edificaciones, semáforo y personas afectadas.
 * Ordenado por cantidad de rojos (lo más urgente primero).
 */
function dashPorParroquia(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $filas = [];
    try {
        $st = db()->prepare("SELECT parroquia, decision_final, COALESCE(numero_personas,0) AS personas
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $parr = trim($r['parroquia'] ?? '') ?: 'Sin parroquia';
            if (!isset($filas[$parr])) {
                $filas[$parr] = ['parroquia' => $parr, 'total' => 0,
                                 'verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'sin' => 0,
                                 'personas' => 0];
            }
            $filas[$parr]['total']++;
            $filas[$parr][dashClasificarColor($r['decision_final'])]++;
            $filas[$parr]['personas'] += (int)$r['personas'];
        }
    } catch (Throwable $e) {}
    $filas = array_values($filas);
    // Ordenar: primero las parroquias con más rojos, luego más amarillos.
    usort($filas, function ($a, $b) {
        if ($a['rojo'] !== $b['rojo']) return $b['rojo'] <=> $a['rojo'];
        if ($a['amarillo'] !== $b['amarillo']) return $b['amarillo'] <=> $a['amarillo'];
        return $b['total'] <=> $a['total'];
    });
    return $filas;
}

/**
 * Por cantidad de pisos (agrupado en rangos útiles para logística:
 * 1, 2-4, 5-9, 10-19, 20+). Ayuda a dimensionar recursos.
 */
function dashPorCantidadPisos(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $rangos = [
        '1'      => ['etiqueta' => '1 piso',        'min' => 1,  'max' => 1,   'total' => 0, 'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0],
        '2-4'    => ['etiqueta' => '2 a 4 pisos',   'min' => 2,  'max' => 4,   'total' => 0, 'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0],
        '5-9'    => ['etiqueta' => '5 a 9 pisos',   'min' => 5,  'max' => 9,   'total' => 0, 'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0],
        '10-19'  => ['etiqueta' => '10 a 19 pisos', 'min' => 10, 'max' => 19,  'total' => 0, 'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0],
        '20+'    => ['etiqueta' => '20 o más pisos','min' => 20, 'max' => 999, 'total' => 0, 'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0],
    ];
    try {
        $st = db()->prepare("SELECT COALESCE(num_pisos,0) AS pisos, decision_final,
                                    COALESCE(numero_personas,0) AS personas
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $p = (int)$r['pisos'];
            if ($p < 1) continue;   // sin dato de pisos: no clasifica
            foreach ($rangos as $k => $rango) {
                if ($p >= $rango['min'] && $p <= $rango['max']) {
                    $rangos[$k]['total']++;
                    $rangos[$k][dashClasificarColor($r['decision_final'])]++;
                    $rangos[$k]['personas'] += (int)$r['personas'];
                    break;
                }
            }
        }
    } catch (Throwable $e) {}
    return array_values($rangos);
}

/**
 * Por uso de la edificación (vivienda, escuela, hospital, gubernamental…).
 * Crucial: una escuela o un hospital rojo es prioridad máxima.
 */
function dashPorUso(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $filas = [];
    try {
        $st = db()->prepare("SELECT COALESCE(NULLIF(TRIM(uso_edificacion),''),'Sin especificar') AS uso,
                                    decision_final, COALESCE(numero_personas,0) AS personas
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $uso = $r['uso'];
            if (!isset($filas[$uso])) {
                $filas[$uso] = ['uso' => $uso, 'total' => 0,
                                'rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sin' => 0, 'personas' => 0];
            }
            $filas[$uso]['total']++;
            $filas[$uso][dashClasificarColor($r['decision_final'])]++;
            $filas[$uso]['personas'] += (int)$r['personas'];
        }
    } catch (Throwable $e) {}
    $filas = array_values($filas);
    usort($filas, function ($a, $b) {
        if ($a['rojo'] !== $b['rojo']) return $b['rojo'] <=> $a['rojo'];
        return $b['total'] <=> $a['total'];
    });
    return $filas;
}

/**
 * Riesgos estructurales agregados: colapso, asentamiento, inclinación,
 * amenaza geológica, riesgo a edificios aledaños. Para priorizar
 * inspecciones especializadas y evacuaciones.
 */
function dashRiesgos(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $out = [
        'colapso_total'    => 0,
        'colapso_parcial'  => 0,
        'asentamiento'     => 0,
        'inclinacion'      => 0,
        'amenaza_geologica'=> 0,
        'riesgo_aledanos'  => 0,
    ];
    try {
        $st = db()->prepare("SELECT colapso_estructura, asentamiento_edificio, inclinacion_edificio,
                                    amenaza_geologica, riesgo_edificios_aledanos
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $col = strtolower(trim($r["colapso_estructura"] ?? ""));
            if ($col === 'total')   $out['colapso_total']++;
            if ($col === 'parcial') $out['colapso_parcial']++;
            // Los campos de riesgo guardan textos tipo "Alto/Medio/Sí"; se
            // cuenta cualquier valor que no sea vacío ni "no"/"ninguno".
            foreach ([
                'asentamiento'      => $r['asentamiento_edificio'] ?? '',
                'inclinacion'       => $r['inclinacion_edificio'] ?? '',
                'amenaza_geologica' => $r['amenaza_geologica'] ?? '',
                'riesgo_aledanos'   => $r['riesgo_edificios_aledanos'] ?? '',
            ] as $clave => $valor) {
                $v = strtolower(trim($valor));
                if ($v !== '' && $v !== 'no' && $v !== 'ninguno' && $v !== 'ninguna'
                    && $v !== 'n/a' && $v !== '0') {
                    $out[$clave]++;
                }
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Las edificaciones ROJAS con más personas: la lista de acción inmediata.
 * Devuelve las N con mayor población afectada.
 */
function dashRojosPrioritarios(array $filtros = [], int $limite = 15): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $filas = [];
    try {
        $st = db()->prepare("SELECT nombre_edificio, parroquia, num_pisos,
                                    COALESCE(numero_personas,0) AS personas,
                                    decision_final, uso_edificacion
                               FROM inspecciones i $where");
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            if (dashClasificarColor($r['decision_final']) === 'rojo') {
                $filas[] = $r;
            }
        }
    } catch (Throwable $e) {}
    usort($filas, fn($a, $b) => (int)$b['personas'] <=> (int)$a['personas']);
    return array_slice($filas, 0, $limite);
}

/**
 * Poblaciones vulnerables agregadas (niños, tercera edad, gestantes,
 * movilidad reducida). Para dirigir la atención social.
 */
function dashVulnerables(array $filtros = []): array
{
    [$where, $par] = dashScopeWhere($filtros);
    $out = ['familias' => 0, 'ninos' => 0, 'adultos_tercera_edad' => 0,
            'gestantes' => 0, 'movilidad_reducida' => 0, 'mascotas' => 0];
    try {
        $st = db()->prepare("SELECT
                COALESCE(SUM(familias),0) AS familias,
                COALESCE(SUM(ninos),0) AS ninos,
                COALESCE(SUM(adultos_tercera_edad),0) AS adultos_tercera_edad,
                COALESCE(SUM(gestantes),0) AS gestantes,
                COALESCE(SUM(movilidad_reducida),0) AS movilidad_reducida,
                COALESCE(SUM(mascotas),0) AS mascotas
              FROM inspecciones i $where");
        $st->execute($par);
        $r = $st->fetch();
        if ($r) foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Reúne TODO el dashboard en un solo llamado.
 */
function dashCompleto(array $filtros = []): array
{
    return [
        'general'    => dashResumenGeneral($filtros),
        'parroquia'  => dashPorParroquia($filtros),
        'pisos'      => dashPorCantidadPisos($filtros),
        'uso'        => dashPorUso($filtros),
        'riesgos'    => dashRiesgos($filtros),
        'rojos'      => dashRojosPrioritarios($filtros),
        'vulnerables'=> dashVulnerables($filtros),
        'filtros'    => $filtros,
    ];
}
