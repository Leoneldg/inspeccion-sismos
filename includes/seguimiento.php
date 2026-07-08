<?php
/**
 * =====================================================================
 * Módulo de Seguimiento y Control — capa de datos y utilidades.
 * =====================================================================
 * Encapsula todo el acceso a las tablas del módulo (seguimiento_obras,
 * seguimiento_recursos, seguimiento_fotos, seguimiento_bitacora, entes) para
 * que las páginas queden delgadas. Respeta el alcance nacional (un usuario
 * estadal sólo ve/gestiona obras de su estado).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/territorial.php';

// Carpeta de registro fotográfico de obras (paralela a uploads/inspecciones).
if (!defined('SEG_UPLOAD_DIR')) {
    define('SEG_UPLOAD_DIR', dirname(__DIR__) . '/uploads/seguimiento/');
    define('SEG_UPLOAD_URL', APP_URL_BASE . 'uploads/seguimiento/');
}

/** Fases del registro fotográfico de una obra. */
function segFasesFoto(): array
{
    return [
        'Inicio'    => ['icono' => 'bi-flag-fill',       'color' => '#2d4488'],
        'Avance'    => ['icono' => 'bi-hourglass-split', 'color' => '#C9A227'],
        'Culminada' => ['icono' => 'bi-check-circle-fill','color' => '#2E7D32'],
    ];
}

/** Estados posibles de una obra + color de badge. */
function segEstadosObra(): array
{
    return [
        'Sin iniciar'  => '#767c94',
        'En ejecución' => '#2d4488',
        'Suspendida'   => '#A61C1C',
        'Culminada'    => '#2E7D32',
    ];
}

/** Lista de entes activos (opcionalmente filtrada por estado). */
function segEntes(?string $estado = null): array
{
    $pdo = db();
    $sql = 'SELECT * FROM entes WHERE activo = 1';
    $params = [];
    if ($estado !== null && $estado !== '') {
        // Entes del estado + entes nacionales (estado NULL)
        $sql .= ' AND (estado = :e OR estado IS NULL)';
        $params['e'] = $estado;
    }
    $sql .= ' ORDER BY nombre';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Devuelve la obra (ficha de seguimiento) de una inspección, creándola
 * vacía si aún no existe. Devuelve el registro completo.
 */
function segObtenerOCrearObra(int $inspeccionId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM seguimiento_obras WHERE inspeccion_id = :i');
    $stmt->execute(['i' => $inspeccionId]);
    $obra = $stmt->fetch();
    if ($obra) return $obra;

    $pdo->prepare('INSERT INTO seguimiento_obras (inspeccion_id, creado_por) VALUES (:i, :u)')
        ->execute(['i' => $inspeccionId, 'u' => $_SESSION['user_id'] ?? null]);
    $id = (int)$pdo->lastInsertId();

    // Pre-cargar recursos a partir de los datos de la inspección.
    segPrecargarRecursos($id, $inspeccionId);

    segBitacora($id, 'Ficha creada', 'Se inició el seguimiento de esta edificación.');

    $stmt->execute(['i' => $inspeccionId]);
    return $stmt->fetch();
}

/** Obra por su propio id (o null). */
function segObraPorId(int $obraId): ?array
{
    $stmt = db()->prepare('SELECT * FROM seguimiento_obras WHERE id = :id');
    $stmt->execute(['id' => $obraId]);
    $o = $stmt->fetch();
    return $o ?: null;
}

/**
 * Pre-carga recursos estimados leyendo la inspección: m² de losas a
 * reponer, muros a reconstruir, etc. Sólo agrega los que tengan valor y que
 * no existan ya (para no duplicar al re-crear).
 */
function segPrecargarRecursos(int $obraId, int $inspeccionId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT m2_losas, muros_reconstruir, pct_dano_iii, pct_dano_iv, pct_dano_v FROM inspecciones WHERE id = :i');
    $stmt->execute(['i' => $inspeccionId]);
    $insp = $stmt->fetch();
    if (!$insp) return;

    $recursos = [];
    if (!empty($insp['m2_losas']) && (float)$insp['m2_losas'] > 0) {
        $recursos[] = ['Reposición de losas', 'm²', (float)$insp['m2_losas']];
    }
    if (!empty($insp['muros_reconstruir']) && (int)$insp['muros_reconstruir'] > 0) {
        $recursos[] = ['Reconstrucción de muros', 'unidad', (float)$insp['muros_reconstruir']];
    }

    $ins = $pdo->prepare(
        'INSERT INTO seguimiento_recursos (obra_id, recurso, unidad, cantidad_estimada, origen)
         SELECT :o, :r, :u, :c, "Inspección" FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM seguimiento_recursos WHERE obra_id = :o2 AND recurso = :r2 AND origen = "Inspección")'
    );
    foreach ($recursos as $r) {
        $ins->execute(['o' => $obraId, 'r' => $r[0], 'u' => $r[1], 'c' => $r[2], 'o2' => $obraId, 'r2' => $r[0]]);
    }
}

/** Recursos de una obra. */
function segRecursos(int $obraId): array
{
    $stmt = db()->prepare('SELECT * FROM seguimiento_recursos WHERE obra_id = :o ORDER BY origen DESC, id');
    $stmt->execute(['o' => $obraId]);
    return $stmt->fetchAll();
}

/** Fotos de una obra, agrupadas por fase. */
function segFotos(int $obraId): array
{
    $stmt = db()->prepare('SELECT * FROM seguimiento_fotos WHERE obra_id = :o ORDER BY fecha_registro, id');
    $stmt->execute(['o' => $obraId]);
    $rows = $stmt->fetchAll();
    $porFase = ['Inicio' => [], 'Avance' => [], 'Culminada' => []];
    foreach ($rows as $r) {
        $porFase[$r['fase']][] = $r;
    }
    return $porFase;
}

/** Bitácora de una obra (más reciente primero). */
function segBitacoraLista(int $obraId): array
{
    $stmt = db()->prepare(
        'SELECT b.*, u.nombre_completo AS usuario_nombre
         FROM seguimiento_bitacora b
         LEFT JOIN usuarios u ON u.id = b.usuario_id
         WHERE b.obra_id = :o ORDER BY b.creado_en DESC'
    );
    $stmt->execute(['o' => $obraId]);
    return $stmt->fetchAll();
}

/** Registra un evento en la bitácora de la obra. */
function segBitacora(int $obraId, string $evento, ?string $detalle = null): void
{
    try {
        db()->prepare('INSERT INTO seguimiento_bitacora (obra_id, usuario_id, evento, detalle) VALUES (:o, :u, :e, :d)')
            ->execute(['o' => $obraId, 'u' => $_SESSION['user_id'] ?? null, 'e' => $evento, 'd' => $detalle]);
    } catch (Throwable $e) { /* no interrumpir */ }
}

/**
 * Guarda una foto de avance de obra en uploads/seguimiento/<obra_id>/.
 * Reutiliza las mismas validaciones que las fotos de inspección.
 */
function segGuardarFoto(int $obraId, string $fase, string $fecha, array $archivo, ?string $descripcion, ?float $avancePct): ?int
{
    if ($archivo['error'] !== UPLOAD_ERR_OK) return null;
    if (defined('FOTO_MAX_BYTES') && $archivo['size'] > FOTO_MAX_BYTES) return null;
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $permitidas = defined('FOTO_EXT_PERMITIDAS') ? FOTO_EXT_PERMITIDAS : ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $permitidas, true)) return null;
    if (@getimagesize($archivo['tmp_name']) === false) return null;

    $dir = rtrim(SEG_UPLOAD_DIR, '/') . '/' . $obraId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    if (!is_writable($dir)) return null;

    $nombre = $fase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . $nombre;
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) return null;

    // Comprimir si la función existe (best-effort).
    if (function_exists('comprimirImagenEnDisco')) {
        try { comprimirImagenEnDisco($destino, $ext); } catch (Throwable $e) {}
    }

    $rutaRel = 'uploads/seguimiento/' . $obraId . '/' . $nombre;
    $stmt = db()->prepare(
        'INSERT INTO seguimiento_fotos (obra_id, fase, fecha_registro, ruta, descripcion, avance_pct, subido_por)
         VALUES (:o, :f, :fecha, :ruta, :desc, :pct, :u)'
    );
    $stmt->execute([
        'o' => $obraId, 'f' => $fase, 'fecha' => $fecha, 'ruta' => $rutaRel,
        'desc' => $descripcion ?: null, 'pct' => $avancePct, 'u' => $_SESSION['user_id'] ?? null,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Calcula los días restantes / vencidos hasta la fecha fin estimada.
 * Devuelve ['dias'=>int, 'estado'=>'a_tiempo'|'proximo'|'vencido'|'sin_fecha'].
 */
function segTiempoRestante(?string $fechaFinEstimada, string $estadoObra): array
{
    if ($estadoObra === 'Culminada') return ['dias' => 0, 'estado' => 'culminada'];
    if (empty($fechaFinEstimada)) return ['dias' => 0, 'estado' => 'sin_fecha'];
    $hoy = new DateTime('today');
    $fin = DateTime::createFromFormat('Y-m-d', $fechaFinEstimada);
    if (!$fin) return ['dias' => 0, 'estado' => 'sin_fecha'];
    $dias = (int)$hoy->diff($fin)->format('%r%a');
    if ($dias < 0)      return ['dias' => $dias, 'estado' => 'vencido'];
    if ($dias <= 7)     return ['dias' => $dias, 'estado' => 'proximo'];
    return ['dias' => $dias, 'estado' => 'a_tiempo'];
}

/**
 * Lista de edificios inspeccionados con su estado de seguimiento (LEFT JOIN),
 * respetando el alcance por estado y filtros opcionales.
 *
 * @param array $filtros ['q'=>, 'estado'=>, 'estado_obra'=>, 'ente_id'=>, 'solo_mias'=>bool]
 */
function segListaEdificios(array $filtros = []): array
{
    $pdo = db();
    $conds = [];
    $params = [];

    // Scope territorial (estadal ve solo su estado).
    aplicarScopeEstado($conds, $params, 'i');

    // Filtro de estado explícito (solo master).
    if (usuarioEsMaster() && !empty($filtros['estado'])) {
        $conds[] = 'i.estado = :fe';
        $params['fe'] = $filtros['estado'];
    }
    if (!empty($filtros['q'])) {
        $conds[] = '(i.nombre_edificio LIKE :q OR i.codigo LIKE :q2)';
        $params['q'] = '%' . $filtros['q'] . '%';
        $params['q2'] = '%' . $filtros['q'] . '%';
    }
    if (!empty($filtros['estado_obra'])) {
        if ($filtros['estado_obra'] === '__sin__') {
            $conds[] = 'so.id IS NULL';
        } else {
            $conds[] = 'so.estado_obra = :eo';
            $params['eo'] = $filtros['estado_obra'];
        }
    }
    if (!empty($filtros['ente_id'])) {
        $conds[] = 'so.ente_id = :ente';
        $params['ente'] = (int)$filtros['ente_id'];
    }
    if (!empty($filtros['solo_mias'])) {
        $conds[] = 'so.responsable_id = :resp';
        $params['resp'] = $_SESSION['user_id'] ?? 0;
    }

    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $sql = "
        SELECT i.id AS inspeccion_id, i.codigo, i.nombre_edificio, i.estado, i.municipio, i.parroquia,
               i.decision_final, i.fecha_inspeccion,
               so.id AS obra_id, so.estado_obra, so.avance_pct, so.fecha_fin_estimada,
               so.ente_id, e.nombre AS ente_nombre,
               so.responsable_id, r.nombre_completo AS responsable_nombre,
               (SELECT COUNT(*) FROM seguimiento_fotos sf WHERE sf.obra_id = so.id) AS fotos
        FROM inspecciones i
        LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
        LEFT JOIN entes e ON e.id = so.ente_id
        LEFT JOIN usuarios r ON r.id = so.responsable_id
        $where
        ORDER BY (so.id IS NULL) DESC, i.creado_en DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Datos de la inspección para la cabecera de la ficha. */
function segInspeccion(int $inspeccionId): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, e.nombre AS ente_nombre
         FROM inspecciones i
         LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
         LEFT JOIN entes e ON e.id = so.ente_id
         WHERE i.id = :i'
    );
    $stmt->execute(['i' => $inspeccionId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** KPIs del módulo (respetando scope). */
function segKpis(): array
{
    $pdo = db();
    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_edificios,
            SUM(CASE WHEN so.id IS NULL THEN 1 ELSE 0 END) AS sin_seguimiento,
            SUM(CASE WHEN so.estado_obra = 'En ejecución' THEN 1 ELSE 0 END) AS en_ejecucion,
            SUM(CASE WHEN so.estado_obra = 'Culminada' THEN 1 ELSE 0 END) AS culminadas,
            COALESCE(AVG(so.avance_pct),0) AS avance_promedio
        FROM inspecciones i
        LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
        $where
    ");
    $stmt->execute($params);
    return $stmt->fetch();
}

// =====================================================================
// PLAN DE ACCIÓN — tipos de construcción, materiales, inventario, avance
// =====================================================================

/** Catálogo de tipos de construcción para el plan de acción. */
function segTiposConstruccion(): array
{
    return [
        'Pared'             => 'Pared',
        'Piso'              => 'Piso',
        'Techo'             => 'Techo',
        'Viga'              => 'Viga',
        'Columna'           => 'Columna',
        'Fundación'         => 'Fundación',
        'Escalera'          => 'Escalera',
        'Fachada'           => 'Fachada',
        'Instalación eléctrica'   => 'Instalación eléctrica',
        'Instalación sanitaria'   => 'Instalación sanitaria',
        'Cielo raso'        => 'Cielo raso',
        'Estructura general' => 'Estructura general',
        'Otro'              => 'Otro',
    ];
}

/** Catálogo de categorías de materiales con sus subtipos. */
function segCatalogoMateriales(): array
{
    return [
        'Bloques' => [
            'Bloque hueco 10x20x40 cm',
            'Bloque hueco 15x20x40 cm',
            'Bloque hueco 20x20x40 cm',
            'Bloque macizo 10x20x40 cm',
            'Bloque de arcilla (ladrillo)',
            'Bloque de concreto celular',
            'Bloque ornamental',
        ],
        'Cemento' => [
            'Cemento Portland tipo I (saco 42.5 kg)',
            'Cemento Portland tipo II',
            'Cemento blanco',
            'Mortero premezclado',
        ],
        'Arena y Agregados' => [
            'Arena lavada (m³)',
            'Arena gruesa (m³)',
            'Gravilla / piedra picada (m³)',
            'Tosca / relleno compactado (m³)',
        ],
        'Acero / Cabillas' => [
            'Cabilla corrugada 1/4" (6 mm)',
            'Cabilla corrugada 3/8" (10 mm)',
            'Cabilla corrugada 1/2" (12 mm)',
            'Cabilla corrugada 5/8" (16 mm)',
            'Cabilla corrugada 3/4" (19 mm)',
            'Malla electrosoldada 15x15 cm',
            'Malla electrosoldada 10x10 cm',
            'Perfil metálico (ml)',
        ],
        'Cables eléctricos' => [
            'Cable THHN #12 AWG',
            'Cable THHN #10 AWG',
            'Cable THHN #8 AWG',
            'Cable THHN #6 AWG',
            'Cable vulcanizado 2x12',
            'Cable vulcanizado 3x12',
            'Tubería conduit PVC 1/2"',
            'Tubería conduit PVC 3/4"',
        ],
        'Tuberías y plomería' => [
            'Tubería PVC presión 1/2"',
            'Tubería PVC presión 3/4"',
            'Tubería PVC sanitaria 4"',
            'Tubería PVC sanitaria 6"',
            'Tubería CPVC 1/2"',
            'Codo PVC 90°',
        ],
        'Madera y encofrado' => [
            'Tabla de madera 1"x10"',
            'Machimbre (m²)',
            'Bastidor de madera 2"x4"',
            'Lámina de fórmica',
            'Madera rolliza (ml)',
        ],
        'Impermeabilizantes' => [
            'Impermeabilizante líquido (lt)',
            'Membrana asfáltica (m²)',
            'Sika cemento (kg)',
        ],
        'Pintura y acabados' => [
            'Pintura de caucho (galón)',
            'Pintura anticorrosiva (galón)',
            'Estuco (saco)',
            'Cerámica / porcelanato (m²)',
        ],
        'Otros materiales' => [
            'Otro — especificar',
        ],
    ];
}

/** Unidades de medida comunes por categoría. */
function segUnidadesMateriales(): array
{
    return ['und' => 'unidades', 'saco' => 'sacos', 'm²' => 'm²', 'm³' => 'm³',
            'ml' => 'metros lineales', 'kg' => 'kilogramos', 'lt' => 'litros',
            'galón' => 'galones', 'rollo' => 'rollos'];
}

/** Devuelve todos los materiales del plan de una obra. */
function segMateriales(int $obraId): array
{
    try {
        return db()->prepare('SELECT * FROM seguimiento_materiales WHERE obra_id = :o ORDER BY categoria, subtipo')
                   ->execute(['o' => $obraId]) ? db()->prepare('SELECT * FROM seguimiento_materiales WHERE obra_id = :o ORDER BY categoria, subtipo')
                   ->execute(['o' => $obraId]) && ($r = db()->prepare('SELECT * FROM seguimiento_materiales WHERE obra_id = :o ORDER BY categoria, subtipo')) && $r->execute(['o' => $obraId]) ? $r->fetchAll() : [] : [];
    } catch (Throwable $e) { return []; }
}

/** Devuelve todos los materiales de una obra (versión correcta). */
function segMaterialesDe(int $obraId): array
{
    try {
        $st = db()->prepare('SELECT * FROM seguimiento_materiales WHERE obra_id = :o ORDER BY categoria, subtipo, id');
        $st->execute(['o' => $obraId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** Reportes de inventario de un material (del más reciente al más antiguo). */
function segReportesMaterial(int $materialId): array
{
    try {
        $st = db()->prepare(
            'SELECT r.*, u.nombre_completo AS reportado_nombre
             FROM seguimiento_inventario_reportes r
             LEFT JOIN usuarios u ON u.id = r.reportado_por
             WHERE r.material_id = :m
             ORDER BY r.reportado_en DESC'
        );
        $st->execute(['m' => $materialId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** Todos los reportes de inventario de una obra, ordenados del más reciente. */
function segReportesObra(int $obraId): array
{
    try {
        $st = db()->prepare(
            'SELECT r.*, m.categoria, m.subtipo, m.unidad, m.cantidad_asignada,
                    u.nombre_completo AS reportado_nombre
             FROM seguimiento_inventario_reportes r
             JOIN seguimiento_materiales m ON m.id = r.material_id
             LEFT JOIN usuarios u ON u.id = r.reportado_por
             WHERE r.obra_id = :o
             ORDER BY r.reportado_en DESC'
        );
        $st->execute(['o' => $obraId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Recalcula y guarda el avance de la obra a partir de los materiales
 * (avance_material_pct) y el metraje reportado (avance_metraje_pct).
 * El avance_pct final es el MENOR de los dos (el cuello de botella).
 */
function segRecalcularAvance(int $obraId): void
{
    try {
        $pdo = db();
        // Avance por materiales: promedio de (asignada - actual) / asignada
        // por cada material donde cantidad_asignada > 0.
        $st = $pdo->prepare(
            'SELECT COALESCE(
               AVG(GREATEST(0, LEAST(100,
                 ((cantidad_asignada - cantidad_actual) / cantidad_asignada) * 100
               ))), 0
             ) AS pct
             FROM seguimiento_materiales
             WHERE obra_id = :o AND cantidad_asignada > 0'
        );
        $st->execute(['o' => $obraId]);
        $pctMat = (float)($st->fetchColumn() ?: 0);

        // Avance por metraje: viene del último reporte que tenga metraje_avance.
        $st2 = $pdo->prepare(
            'SELECT r.metraje_avance, o.metraje_total
             FROM seguimiento_inventario_reportes r
             JOIN seguimiento_obras o ON o.id = r.obra_id
             WHERE r.obra_id = :o AND r.metraje_avance IS NOT NULL
             ORDER BY r.reportado_en DESC LIMIT 1'
        );
        $st2->execute(['o' => $obraId]);
        $rowM = $st2->fetch();
        $pctMet = 0;
        if ($rowM && $rowM['metraje_total'] > 0) {
            $pctMet = min(100, ($rowM['metraje_avance'] / $rowM['metraje_total']) * 100);
        }

        // El avance global es el mínimo (cuello de botella).
        $pctFinal = ($pctMat > 0 || $pctMet > 0)
            ? ($pctMet > 0 ? min($pctMat, $pctMet) : $pctMat)
            : 0;

        $pdo->prepare(
            'UPDATE seguimiento_obras
             SET avance_material_pct = :mat, avance_metraje_pct = :met, avance_pct = :final
             WHERE id = :o'
        )->execute(['mat' => round($pctMat,2), 'met' => round($pctMet,2), 'final' => round($pctFinal,2), 'o' => $obraId]);
    } catch (Throwable $e) { /* silencioso */ }
}
