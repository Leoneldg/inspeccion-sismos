<?php
/**
 * FASE 2 · INTERVENCIÓN.
 *
 * El levantamiento técnico (fase 1) deja el PLAN de trabajo: qué ambientes
 * hay que reparar y, dentro de cada uno, qué partidas (friso de pared,
 * pintura de techo…) con sus metros. Este archivo es la fase siguiente:
 * registrar la EJECUCIÓN de ese plan.
 *
 * Reglas del módulo:
 *
 *   · La unidad de reporte es la PARTIDA, no el ambiente. Un ambiente con
 *     dos partidas puede tener una terminada y otra sin empezar.
 *
 *   · El porcentaje NO se escribe a mano en ningún lado. Sale del estado
 *     de cada partida, ponderado por sus metros cuadrados:
 *         sin reporte      →   0
 *         reporte "durante"→  50
 *         reporte "después"→ 100
 *
 *   · Cada partida lleva BITÁCORA: varias visitas fechadas por fase, cada
 *     una con sus fotos y su observación. El día es la unidad de la
 *     bitácora, así el técnico solo toma fotos y el sistema las agrupa.
 *
 * No toca `rec_avance_ambiente`, `rec_avance_apto` ni `rec_avance_area_comun`:
 * esas son del módulo de remodelación, que trabaja con porcentaje manual.
 * Los dos módulos conviven sin pisarse el dato.
 */

require_once __DIR__ . '/seguimiento.php';

/** Fases que admite un reporte de intervención. */
function intvFases(): array
{
    return ['durante' => 'Durante', 'despues' => 'Después'];
}

/** Estado de una partida → porcentaje. Única fuente del cálculo. */
function intvPctDeEstado(string $estado): int
{
    switch ($estado) {
        case 'terminada':  return 100;
        case 'en_proceso': return 50;
        default:           return 0;
    }
}

/** Niveles del levantamiento que pueden tener partidas de trabajo. */
function intvNiveles(): array
{
    return ['ambiente', 'area_comun', 'elemento_piso'];
}

/**
 * Tabla de la bitácora.
 *
 * Importante: NO se guarda `rec_reparacion.id`. Esa tabla se borra entera
 * y se reinserta cada vez que alguien guarda el levantamiento
 * (ver recGuardarReparaciones), así que sus ids cambian y el historial
 * quedaría huérfano. Se usa la CLAVE NATURAL de la partida:
 * nivel + ref_id + superficie + trabajo, que sí sobrevive a la reescritura.
 */
function intvAsegurarTablas(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rec_interv_reporte (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            edificio_id INT UNSIGNED NOT NULL,
            nivel ENUM('ambiente','area_comun','elemento_piso') NOT NULL DEFAULT 'ambiente',
            ref_id INT UNSIGNED NOT NULL,
            tipo_superficie VARCHAR(20) NOT NULL DEFAULT '',
            tipo_trabajo VARCHAR(60) NOT NULL DEFAULT '',
            fase ENUM('durante','despues') NOT NULL,
            fecha DATE NOT NULL,
            observaciones VARCHAR(400) DEFAULT NULL,
            reportado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_interv_dia (nivel, ref_id, tipo_superficie, tipo_trabajo, fase, fecha),
            KEY idx_interv_ed (edificio_id),
            KEY idx_interv_partida (nivel, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir: la UI avisa si no se puede guardar */ }

    // rec_foto.nivel tiene que admitir 'reporte_intervencion'.
    //
    // En varias instalaciones esta columna nació como ENUM. Cuando eso
    // pasa, MySQL rechaza el valor nuevo (o lo guarda como '' en modo no
    // estricto) y la foto se sube al disco pero nunca aparece en la
    // ficha: el mismo problema que ya tuvo rec_reparacion.nivel con las
    // áreas comunes. Se convierte a VARCHAR, que admite cualquier nivel
    // futuro sin volver a tocar el esquema.
    try {
        $col = db()->query("SHOW COLUMNS FROM rec_foto LIKE 'nivel'")->fetch(PDO::FETCH_ASSOC);
        $tipo = strtolower($col['Type'] ?? '');
        if (str_starts_with($tipo, 'enum')) {
            db()->exec("ALTER TABLE rec_foto MODIFY nivel VARCHAR(40) NOT NULL DEFAULT 'ambiente'");
        }
    } catch (Throwable $e) { /* si no se puede alterar, se reporta al subir */ }
}

/**
 * Clave natural de una partida. Se usa como identificador en el JSON y
 * como índice en memoria. Se normaliza para que "Pared" y "pared" sean
 * la misma partida.
 */
function intvClave(string $nivel, int $refId, ?string $superficie, ?string $trabajo): string
{
    return $nivel . '|' . $refId . '|'
         . mb_strtolower(trim((string)$superficie), 'UTF-8') . '|'
         . mb_strtolower(trim((string)$trabajo), 'UTF-8');
}

/** Quita acentos y baja a minúsculas, para comparar textos escritos a mano. */
function intvNormalizar(?string $txt): string
{
    $t = mb_strtolower(trim((string)$txt), 'UTF-8');
    return strtr($t, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
}

/**
 * Fotos del "antes" que dejó el levantamiento, agrupadas por partida.
 *
 * El levantamiento guarda la foto con `parte` = la superficie física que
 * se fotografió ("Pared", "Techo", "Piso", "Grieta"…). Aquí se reparten:
 * las que coinciden con una superficie van a su partida; el resto queda
 * como fotos generales del ambiente.
 *
 * Devuelve [ 'por_superficie' => [nivel|ref|superficie => [fotos]],
 *            'generales'      => [nivel|ref => [fotos]] ]
 */
function intvFotosAntes(int $edificioId): array
{
    $porSup = [];
    $generales = [];
    $superficies = array_keys(recTiposSuperficie());   // pared, techo, piso

    $añadir = function (string $nivel, int $refId, array $f) use (&$porSup, &$generales, $superficies) {
        $parteNorm = intvNormalizar($f['parte'] ?? '');
        $item = [
            'id'    => (int)$f['id'],
            'ruta'  => APP_URL_BASE . ltrim($f['ruta'], '/'),
            'parte' => $f['parte'] ?: '',
            'fecha' => !empty($f['creado_en']) ? date('d/m/Y', strtotime($f['creado_en'])) : '',
        ];
        if (in_array($parteNorm, $superficies, true)) {
            $porSup[$nivel . '|' . $refId . '|' . $parteNorm][] = $item;
        } else {
            $generales[$nivel . '|' . $refId][] = $item;
        }
    };

    // --- Ambientes ---
    try {
        $st = db()->prepare("
            SELECT f.id, f.ruta, f.parte, f.creado_en, f.ref_id
              FROM rec_foto f
              JOIN rec_ambiente am ON am.id = f.ref_id
              JOIN rec_apartamento ap ON ap.id = am.apartamento_id
              JOIN rec_piso pi ON pi.id = ap.piso_id
             WHERE pi.edificio_id = :e
               AND f.nivel = 'ambiente'
               AND (f.parte IS NULL OR f.parte NOT IN ('durante','despues'))
             ORDER BY f.id");
        $st->execute(['e' => $edificioId]);
        foreach ($st->fetchAll() as $f) $añadir('ambiente', (int)$f['ref_id'], $f);
    } catch (Throwable $e) { /* sin fotos de ambientes */ }

    // --- Elementos de piso (escaleras, pasillos, ascensor…) ---
    try {
        $st = db()->prepare("
            SELECT f.id, f.ruta, f.parte, f.creado_en, f.ref_id
              FROM rec_foto f
              JOIN rec_elemento_piso ep ON ep.id = f.ref_id
              JOIN rec_piso pi ON pi.id = ep.piso_id
             WHERE pi.edificio_id = :e
               AND f.nivel = 'elemento_piso'
               AND (f.parte IS NULL OR f.parte NOT IN ('durante','despues'))
             ORDER BY f.id");
        $st->execute(['e' => $edificioId]);
        foreach ($st->fetchAll() as $f) $añadir('elemento_piso', (int)$f['ref_id'], $f);
    } catch (Throwable $e) { /* sin fotos de elementos */ }

    // --- Áreas comunes ---
    // El levantamiento las guarda a nivel 'edificio', usando `parte` para
    // decir de qué área es: 'lobby', o 'lobby_reparacion' si requiere
    // trabajo. Hay que traducir esa clave al id del área.
    try {
        $areas = [];
        foreach (recAreasComunesConNombre($edificioId) as $a) {
            $areas[intvNormalizar($a['tipo'])] = (int)$a['id'];
        }
        if ($areas) {
            $st = db()->prepare("SELECT id, ruta, parte, creado_en
                                   FROM rec_foto
                                  WHERE nivel = 'edificio' AND ref_id = :e
                                  ORDER BY id");
            $st->execute(['e' => $edificioId]);
            foreach ($st->fetchAll() as $f) {
                $p = intvNormalizar($f['parte'] ?? '');
                if ($p === '') continue;
                $base = preg_replace('/_reparacion$/', '', $p);
                if (!isset($areas[$base])) continue;
                $generales['area_comun|' . $areas[$base]][] = [
                    'id'    => (int)$f['id'],
                    'ruta'  => APP_URL_BASE . ltrim($f['ruta'], '/'),
                    'parte' => $f['parte'] ?: '',
                    'fecha' => !empty($f['creado_en']) ? date('d/m/Y', strtotime($f['creado_en'])) : '',
                ];
            }
        }
    } catch (Throwable $e) { /* sin fotos de áreas comunes */ }

    return ['por_superficie' => $porSup, 'generales' => $generales];
}

/**
 * Bitácora completa del edificio, indexada por clave de partida.
 * Cada entrada trae sus fotos (que cuelgan del id del reporte).
 */
function intvBitacora(int $edificioId): array
{
    intvAsegurarTablas();
    $out = [];
    try {
        $st = db()->prepare("
            SELECT r.*, u.nombre_completo AS autor
              FROM rec_interv_reporte r
              LEFT JOIN usuarios u ON u.id = r.reportado_por
             WHERE r.edificio_id = :e
             ORDER BY r.fecha DESC, r.id DESC");
        $st->execute(['e' => $edificioId]);
        $reportes = $st->fetchAll();
        if (!$reportes) return $out;

        // Fotos de todos los reportes de una sola vez.
        $ids = array_map(fn($r) => (int)$r['id'], $reportes);
        $fotos = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stF = db()->prepare("SELECT id, ref_id, ruta, parte, creado_en
                                FROM rec_foto
                               WHERE nivel = 'reporte_intervencion'
                                 AND ref_id IN ($in)
                               ORDER BY id");
        $stF->execute($ids);
        foreach ($stF->fetchAll() as $f) {
            $fotos[(int)$f['ref_id']][] = [
                'id'   => (int)$f['id'],
                'ruta' => APP_URL_BASE . ltrim($f['ruta'], '/'),
                'hora' => !empty($f['creado_en']) ? date('H:i', strtotime($f['creado_en'])) : '',
            ];
        }

        foreach ($reportes as $r) {
            $clave = intvClave($r['nivel'], (int)$r['ref_id'], $r['tipo_superficie'], $r['tipo_trabajo']);
            $out[$clave][] = [
                'id'      => (int)$r['id'],
                'fase'    => $r['fase'],
                'fecha'   => $r['fecha'],
                'fecha_txt' => date('d/m/Y', strtotime($r['fecha'])),
                'obs'     => $r['observaciones'] ?? '',
                'autor'   => $r['autor'] ?? '',
                'fotos'   => $fotos[(int)$r['id']] ?? [],
            ];
        }
    } catch (Throwable $e) { /* bitácora vacía */ }
    return $out;
}

/**
 * Todas las partidas del edificio que dejó el levantamiento.
 * Devuelve filas planas con su contexto, listas para agrupar.
 */
function intvPartidas(int $edificioId): array
{
    recAsegurarTablasTrabajo();
    recAsegurarAreasPartidas();

    $nombresTrabajo = [];
    foreach (recTiposTrabajo() as $t) $nombresTrabajo[$t['clave']] = $t['nombre'];
    $superficies = recTiposSuperficie();
    $areasTipicas = recAreasComunesTipicas();

    $filas = [];

    // --- Partidas de ambientes ---
    try {
        $st = db()->prepare("
            SELECT rr.nivel, rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo,
                   SUM(rr.metros_cuadrados) AS m2,
                   MIN(rr.partida) AS orden,
                   pi.id AS piso_id, pi.numero_piso,
                   ap.id AS apto_id, ap.identificador, COALESCE(ap.es_local,0) AS es_local,
                   am.tipo AS amb_tipo, am.numero AS amb_numero
              FROM rec_reparacion rr
              JOIN rec_ambiente am ON am.id = rr.ref_id
              JOIN rec_apartamento ap ON ap.id = am.apartamento_id
              JOIN rec_piso pi ON pi.id = ap.piso_id
             WHERE pi.edificio_id = :e
               AND rr.nivel = 'ambiente'
               AND am.necesita_reparacion = 1
             GROUP BY rr.nivel, rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo,
                      pi.id, pi.numero_piso, ap.id, ap.identificador, ap.es_local,
                      am.tipo, am.numero
             -- El orden tiene que ser estable: recGuardarReparaciones borra
             -- y reinserta las partidas, así que sin ORDER BY explícito la
             -- lista se le reordena al técnico entre una visita y otra.
             ORDER BY pi.numero_piso, ap.id, am.tipo, am.numero,
                      orden, rr.tipo_superficie, rr.tipo_trabajo");
        $st->execute(['e' => $edificioId]);
        foreach ($st->fetchAll() as $r) {
            $filas[] = [
                'nivel'        => 'ambiente',
                'ref_id'       => (int)$r['ref_id'],
                'superficie'   => $r['tipo_superficie'],
                'superficie_txt' => $superficies[$r['tipo_superficie']] ?? $r['tipo_superficie'],
                'trabajo'      => $r['tipo_trabajo'] ?? '',
                'trabajo_txt'  => $nombresTrabajo[$r['tipo_trabajo']] ?? ($r['tipo_trabajo'] ?: 'Trabajo sin indicar'),
                'm2'           => round((float)$r['m2'], 2),
                'piso_id'      => (int)$r['piso_id'],
                'numero_piso'  => (int)$r['numero_piso'],
                'contenedor_id'   => 'ap' . (int)$r['apto_id'],
                'contenedor_txt'  => ((int)$r['es_local'] === 1 ? 'Local ' : '') . ($r['identificador'] ?: 'Sin identificar'),
                'espacio_id'   => 'am' . (int)$r['ref_id'],
                'espacio_txt'  => trim($r['amb_tipo'] . ' ' . (int)$r['amb_numero']),
            ];
        }
    } catch (Throwable $e) { /* sin partidas de ambientes */ }

    // --- Partidas de elementos de piso ---
    try {
        $st = db()->prepare("
            SELECT rr.nivel, rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo,
                   SUM(rr.metros_cuadrados) AS m2, MIN(rr.partida) AS orden,
                   pi.id AS piso_id, pi.numero_piso, ep.tipo AS elem_tipo
              FROM rec_reparacion rr
              JOIN rec_elemento_piso ep ON ep.id = rr.ref_id
              JOIN rec_piso pi ON pi.id = ep.piso_id
             WHERE pi.edificio_id = :e AND rr.nivel = 'elemento_piso'
             GROUP BY rr.nivel, rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo,
                      pi.id, pi.numero_piso, ep.tipo
             ORDER BY pi.numero_piso, ep.tipo, orden, rr.tipo_superficie, rr.tipo_trabajo");
        $st->execute(['e' => $edificioId]);
        foreach ($st->fetchAll() as $r) {
            $filas[] = [
                'nivel'        => 'elemento_piso',
                'ref_id'       => (int)$r['ref_id'],
                'superficie'   => $r['tipo_superficie'],
                'superficie_txt' => $superficies[$r['tipo_superficie']] ?? $r['tipo_superficie'],
                'trabajo'      => $r['tipo_trabajo'] ?? '',
                'trabajo_txt'  => $nombresTrabajo[$r['tipo_trabajo']] ?? ($r['tipo_trabajo'] ?: 'Trabajo sin indicar'),
                'm2'           => round((float)$r['m2'], 2),
                'piso_id'      => (int)$r['piso_id'],
                'numero_piso'  => (int)$r['numero_piso'],
                'contenedor_id'   => 'elem',
                'contenedor_txt'  => 'Elementos del piso',
                'espacio_id'   => 'ep' . (int)$r['ref_id'],
                'espacio_txt'  => ucfirst(str_replace('_', ' ', $r['elem_tipo'] ?? 'Elemento')),
            ];
        }
    } catch (Throwable $e) { /* sin partidas de elementos */ }

    // --- Partidas de áreas comunes ---
    // Algunas áreas guardan su trabajo en rec_reparacion; otras, más
    // antiguas, solo tienen tipo_trabajo y metros en la propia tabla del
    // área. Se contemplan las dos, sin duplicar.
    try {
        $conPartida = [];
        $st = db()->prepare("
            SELECT rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo,
                   SUM(rr.metros_cuadrados) AS m2, MIN(rr.partida) AS orden
              FROM rec_reparacion rr
              JOIN rec_area_comun ac ON ac.id = rr.ref_id
             WHERE ac.edificio_id = :e AND rr.nivel = 'area_comun'
             GROUP BY rr.ref_id, rr.tipo_superficie, rr.tipo_trabajo
             ORDER BY rr.ref_id, orden, rr.tipo_superficie, rr.tipo_trabajo");
        $st->execute(['e' => $edificioId]);
        $rowsAC = $st->fetchAll();
        foreach ($rowsAC as $r) $conPartida[(int)$r['ref_id']] = true;

        $nombresArea = [];
        foreach (recAreasComunesConNombre($edificioId) as $a) {
            $nombresArea[(int)$a['id']] = $a;
        }

        foreach ($rowsAC as $r) {
            $a = $nombresArea[(int)$r['ref_id']] ?? null;
            if (!$a || empty($a['necesita_reparacion'])) continue;
            $filas[] = [
                'nivel'        => 'area_comun',
                'ref_id'       => (int)$r['ref_id'],
                'superficie'   => $r['tipo_superficie'],
                'superficie_txt' => $superficies[$r['tipo_superficie']] ?? $r['tipo_superficie'],
                'trabajo'      => $r['tipo_trabajo'] ?? '',
                'trabajo_txt'  => $nombresTrabajo[$r['tipo_trabajo']] ?? ($r['tipo_trabajo'] ?: 'Trabajo sin indicar'),
                'm2'           => round((float)$r['m2'], 2),
                'piso_id'      => 0,
                'numero_piso'  => -1,
                'contenedor_id'   => 'comunes',
                'contenedor_txt'  => 'Áreas comunes',
                'espacio_id'   => 'ac' . (int)$r['ref_id'],
                'espacio_txt'  => $a['etiqueta'] ?? ($areasTipicas[$a['tipo']] ?? 'Área común'),
            ];
        }

        // Áreas que necesitan reparación pero no dejaron fila en
        // rec_reparacion: se arma una partida única con lo que hay.
        foreach ($nombresArea as $id => $a) {
            if (empty($a['necesita_reparacion']) || isset($conPartida[$id])) continue;
            $filas[] = [
                'nivel'        => 'area_comun',
                'ref_id'       => $id,
                'superficie'   => '',
                'superficie_txt' => 'General',
                'trabajo'      => $a['tipo_trabajo'] ?? '',
                'trabajo_txt'  => $nombresTrabajo[$a['tipo_trabajo']] ?? ($a['tipo_trabajo'] ?: 'Trabajo sin indicar'),
                'm2'           => round((float)($a['metros_cuadrados'] ?? 0), 2),
                'piso_id'      => 0,
                'numero_piso'  => -1,
                'contenedor_id'   => 'comunes',
                'contenedor_txt'  => 'Áreas comunes',
                'espacio_id'   => 'ac' . $id,
                'espacio_txt'  => $a['etiqueta'] ?? 'Área común',
            ];
        }
    } catch (Throwable $e) { /* sin partidas de áreas comunes */ }

    return $filas;
}

/**
 * ÁRBOL COMPLETO DE LA INTERVENCIÓN.
 *
 * Arma piso → contenedor (apartamento / local / grupo) → espacio
 * (ambiente / área / elemento) → partidas, y calcula el avance de cada
 * nivel ponderando por metros cuadrados.
 *
 * El peso de una partida son sus m². Si el levantamiento no los registró
 * se usa 1, para que la partida siga contando en lugar de desaparecer.
 */
function intvArbol(int $edificioId): array
{
    $partidas  = intvPartidas($edificioId);
    $bitacora  = intvBitacora($edificioId);
    $antes     = intvFotosAntes($edificioId);

    $pisos = [];
    $totAmbientes = ['sin_iniciar' => 0, 'en_proceso' => 0, 'terminada' => 0];
    $m2Total = 0.0; $m2Hecho = 0.0;

    foreach ($partidas as $p) {
        $clave = intvClave($p['nivel'], $p['ref_id'], $p['superficie'], $p['trabajo']);
        $hist  = $bitacora[$clave] ?? [];

        $tieneDespues = false; $tieneDurante = false;
        foreach ($hist as $h) {
            if ($h['fase'] === 'despues') $tieneDespues = true;
            if ($h['fase'] === 'durante') $tieneDurante = true;
        }
        $estado = $tieneDespues ? 'terminada' : ($tieneDurante ? 'en_proceso' : 'sin_iniciar');
        $pct    = intvPctDeEstado($estado);
        $peso   = $p['m2'] > 0 ? $p['m2'] : 1.0;

        $pid = $p['piso_id'];
        if (!isset($pisos[$pid])) {
            $pisos[$pid] = [
                'piso_id'     => $pid,
                'numero_piso' => $p['numero_piso'],
                'etiqueta'    => $p['numero_piso'] < 0 ? 'Áreas comunes'
                                 : ($p['numero_piso'] === 0 ? 'Planta baja' : 'Piso ' . $p['numero_piso']),
                'contenedores' => [],
                '_peso' => 0.0, '_acum' => 0.0,
            ];
        }
        $cid = $p['contenedor_id'];
        if (!isset($pisos[$pid]['contenedores'][$cid])) {
            $pisos[$pid]['contenedores'][$cid] = [
                'id' => $cid, 'titulo' => $p['contenedor_txt'],
                'espacios' => [], '_peso' => 0.0, '_acum' => 0.0,
            ];
        }
        $eid = $p['espacio_id'];
        if (!isset($pisos[$pid]['contenedores'][$cid]['espacios'][$eid])) {
            $pisos[$pid]['contenedores'][$cid]['espacios'][$eid] = [
                'id'     => $eid,
                'titulo' => $p['espacio_txt'],
                'nivel'  => $p['nivel'],
                'ref_id' => $p['ref_id'],
                'partidas' => [],
                'fotos_generales' => $antes['generales'][$p['nivel'] . '|' . $p['ref_id']] ?? [],
                '_peso' => 0.0, '_acum' => 0.0,
            ];
        }

        $keyAntes = $p['nivel'] . '|' . $p['ref_id'] . '|' . intvNormalizar($p['superficie']);
        $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['partidas'][] = [
            'clave'      => $clave,
            'nivel'      => $p['nivel'],
            'ref_id'     => $p['ref_id'],
            'superficie' => $p['superficie'],
            'superficie_txt' => $p['superficie_txt'],
            'trabajo'    => $p['trabajo'],
            'trabajo_txt' => $p['trabajo_txt'],
            'm2'         => $p['m2'],
            'peso'       => round($peso, 2),
            'estado'     => $estado,
            'pct'        => $pct,
            'fotos_antes' => $antes['por_superficie'][$keyAntes] ?? [],
            'bitacora'   => $hist,
        ];

        // Acumular el ponderado en los tres niveles.
        $aporte = $peso * $pct;
        $pisos[$pid]['_peso'] += $peso;
        $pisos[$pid]['_acum'] += $aporte;
        $pisos[$pid]['contenedores'][$cid]['_peso'] += $peso;
        $pisos[$pid]['contenedores'][$cid]['_acum'] += $aporte;
        $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['_peso'] += $peso;
        $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['_acum'] += $aporte;

        $m2Total += $p['m2'];
        $m2Hecho += $p['m2'] * $pct / 100;
    }

    // Cerrar los promedios ponderados y contar espacios por estado.
    $pesoEd = 0.0; $acumEd = 0.0;
    foreach ($pisos as $pid => $pi) {
        foreach ($pi['contenedores'] as $cid => $co) {
            foreach ($co['espacios'] as $eid => $es) {
                $pct = $es['_peso'] > 0 ? (int)round($es['_acum'] / $es['_peso']) : 0;
                $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['avance'] = $pct;
                $estado = $pct >= 100 ? 'terminada' : ($pct > 0 ? 'en_proceso' : 'sin_iniciar');
                $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['estado'] = $estado;
                $totAmbientes[$estado]++;
                unset($pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['_peso'],
                      $pisos[$pid]['contenedores'][$cid]['espacios'][$eid]['_acum']);
            }
            $pisos[$pid]['contenedores'][$cid]['avance'] =
                $co['_peso'] > 0 ? (int)round($co['_acum'] / $co['_peso']) : 0;
            $pisos[$pid]['contenedores'][$cid]['espacios'] =
                array_values($pisos[$pid]['contenedores'][$cid]['espacios']);
            unset($pisos[$pid]['contenedores'][$cid]['_peso'],
                  $pisos[$pid]['contenedores'][$cid]['_acum']);
        }
        $pisos[$pid]['avance'] = $pi['_peso'] > 0 ? (int)round($pi['_acum'] / $pi['_peso']) : 0;
        $pisos[$pid]['contenedores'] = array_values($pisos[$pid]['contenedores']);
        $pesoEd += $pi['_peso'];
        $acumEd += $pi['_acum'];
        unset($pisos[$pid]['_peso'], $pisos[$pid]['_acum']);
    }

    // Orden: planta baja y pisos primero, áreas comunes al final.
    uasort($pisos, function ($a, $b) {
        $na = $a['numero_piso'] < 0 ? PHP_INT_MAX : $a['numero_piso'];
        $nb = $b['numero_piso'] < 0 ? PHP_INT_MAX : $b['numero_piso'];
        return $na <=> $nb;
    });

    $totalEspacios = array_sum($totAmbientes);

    return [
        'pisos'   => array_values($pisos),
        'avance'  => $pesoEd > 0 ? (int)round($acumEd / $pesoEd) : 0,
        'espacios' => [
            'total'       => $totalEspacios,
            'sin_iniciar' => $totAmbientes['sin_iniciar'],
            'en_proceso'  => $totAmbientes['en_proceso'],
            'terminados'  => $totAmbientes['terminada'],
        ],
        'm2' => [
            'total'      => round($m2Total, 2),
            'intervenido'=> round($m2Hecho, 2),
        ],
        'sin_plan' => $totalEspacios === 0,
    ];
}

/**
 * Registra (o reutiliza) el reporte del día para una partida y fase.
 *
 * La bitácora se agrupa por día: si el técnico sube tres fotos del
 * "durante" el mismo día, entran todas al mismo asiento. Devuelve el id
 * del reporte, que es a donde se cuelgan las fotos.
 */
function intvRegistrar(int $edificioId, string $nivel, int $refId,
                       ?string $superficie, ?string $trabajo,
                       string $fase, ?string $obs = null, ?string $fecha = null): array
{
    intvAsegurarTablas();

    if (!in_array($nivel, intvNiveles(), true)) {
        throw new InvalidArgumentException('Nivel de partida no válido.');
    }
    if (!array_key_exists($fase, intvFases())) {
        throw new InvalidArgumentException('Fase no válida.');
    }
    if ($refId <= 0) throw new InvalidArgumentException('Partida no válida.');

    $sup = mb_strtolower(trim((string)$superficie), 'UTF-8');
    $tra = mb_strtolower(trim((string)$trabajo), 'UTF-8');
    $dia = $fecha ?: date('Y-m-d');

    // Un asiento por partida + fase + día.
    $st = db()->prepare(
        "SELECT id FROM rec_interv_reporte
          WHERE nivel = :n AND ref_id = :r AND tipo_superficie = :s
            AND tipo_trabajo = :t AND fase = :f AND fecha = :d
          LIMIT 1");
    $st->execute(['n' => $nivel, 'r' => $refId, 's' => $sup, 't' => $tra, 'f' => $fase, 'd' => $dia]);
    $id = (int)$st->fetchColumn();

    if ($id > 0) {
        if ($obs !== null && trim($obs) !== '') {
            db()->prepare('UPDATE rec_interv_reporte SET observaciones = :o WHERE id = :id')
                ->execute(['o' => mb_substr(trim($obs), 0, 400), 'id' => $id]);
        }
    } else {
        db()->prepare(
            "INSERT INTO rec_interv_reporte
                (edificio_id, nivel, ref_id, tipo_superficie, tipo_trabajo,
                 fase, fecha, observaciones, reportado_por)
             VALUES (:e, :n, :r, :s, :t, :f, :d, :o, :u)"
        )->execute([
            'e' => $edificioId, 'n' => $nivel, 'r' => $refId, 's' => $sup, 't' => $tra,
            'f' => $fase, 'd' => $dia,
            'o' => $obs !== null && trim($obs) !== '' ? mb_substr(trim($obs), 0, 400) : null,
            'u' => $_SESSION['user_id'] ?? null,
        ]);
        $id = (int)db()->lastInsertId();

        recAuditar('intervencion_reporte', null, $edificioId,
            ucfirst($fase) . ' · ' . $nivel . ' #' . $refId
            . ($sup ? ' · ' . $sup : '') . ($tra ? ' · ' . $tra : ''));
    }

    return ['reporte_id' => $id, 'fecha' => $dia, 'fase' => $fase];
}

/**
 * Deshace el reporte de una fase para una partida: borra los asientos de
 * esa fase y sus fotos. Sirve para corregir un "después" cargado por
 * error, que de otro modo dejaría la partida en 100% para siempre.
 */
function intvDeshacer(string $nivel, int $refId, ?string $superficie,
                      ?string $trabajo, string $fase): int
{
    intvAsegurarTablas();
    $sup = mb_strtolower(trim((string)$superficie), 'UTF-8');
    $tra = mb_strtolower(trim((string)$trabajo), 'UTF-8');

    $st = db()->prepare(
        "SELECT id FROM rec_interv_reporte
          WHERE nivel = :n AND ref_id = :r AND tipo_superficie = :s
            AND tipo_trabajo = :t AND fase = :f");
    $st->execute(['n' => $nivel, 'r' => $refId, 's' => $sup, 't' => $tra, 'f' => $fase]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$ids) return 0;

    foreach ($ids as $rid) {
        $stF = db()->prepare("SELECT id, ruta FROM rec_foto
                               WHERE nivel = 'reporte_intervencion' AND ref_id = :r");
        $stF->execute(['r' => $rid]);
        foreach ($stF->fetchAll() as $f) {
            $abs = dirname(__DIR__) . '/' . $f['ruta'];
            if (is_file($abs)) @unlink($abs);
        }
        db()->prepare("DELETE FROM rec_foto WHERE nivel = 'reporte_intervencion' AND ref_id = :r")
            ->execute(['r' => $rid]);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("DELETE FROM rec_interv_reporte WHERE id IN ($in)")->execute($ids);

    recAuditar('intervencion_deshacer', null, null,
        'Se deshizo el ' . $fase . ' de ' . $nivel . ' #' . $refId);

    return count($ids);
}

/** Avance de intervención de un edificio, para listados y tableros. */
function intvAvanceEdificio(int $edificioId): int
{
    try { return intvArbol($edificioId)['avance']; }
    catch (Throwable $e) { return 0; }
}
