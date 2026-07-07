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

/**
 * Calcula el AVANCE (%) de una obra a partir del consumo de recursos.
 *
 * Fórmula: avance = SUMA(cantidad_utilizada) / SUMA(cantidad_estimada) * 100,
 * considerando SOLO los recursos que tienen una cantidad estimada > 0. Es un
 * promedio PONDERADO por tamaño: un recurso grande (p. ej. 500 m² de losa)
 * pesa más que uno pequeño (10 sacos), reflejando el avance físico real de la
 * reconstrucción. Cada recurso aporta como máximo su propio estimado (un
 * consumo por encima del 100% de ese recurso no infla el total).
 *
 * Si no hay recursos con estimado, devuelve null (no se puede calcular).
 */
function segCalcularAvance(int $obraId): ?float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(cantidad_estimada),0) AS estimado,
                COALESCE(SUM(LEAST(cantidad_utilizada, cantidad_estimada)),0) AS usado
         FROM seguimiento_recursos
         WHERE obra_id = :o AND cantidad_estimada IS NOT NULL AND cantidad_estimada > 0'
    );
    $stmt->execute(['o' => $obraId]);
    $r = $stmt->fetch();
    $estimado = (float)$r['estimado'];
    if ($estimado <= 0) return null;
    $pct = ((float)$r['usado'] / $estimado) * 100;
    return round(max(0, min(100, $pct)), 2);
}

/**
 * Recalcula y guarda el avance de la obra en base a los recursos, y ajusta el
 * estado_obra en consecuencia (0% y "Sin iniciar" → queda igual; >0% pasa a
 * "En ejecución" si estaba "Sin iniciar"; 100% → "Culminada"). No pisa un
 * estado "Suspendida" puesto manualmente. Devuelve el nuevo avance (o el
 * existente si no se pudo calcular).
 */
function segRecalcularAvance(int $obraId): void
{
    $avance = segCalcularAvance($obraId);
    if ($avance === null) return; // sin recursos estimados: no se toca el avance

    $obra = segObraPorId($obraId);
    if (!$obra) return;
    $estado = $obra['estado_obra'];

    if ($estado !== 'Suspendida') {
        if ($avance >= 100) {
            $estado = 'Culminada';
        } elseif ($avance > 0) {
            // Si estaba "Sin iniciar" o quedó "Culminada" pero el avance ya no
            // es 100% (p. ej. se corrigió un consumo), pasa a "En ejecución".
            if ($estado === 'Sin iniciar' || $estado === 'Culminada') {
                $estado = 'En ejecución';
            }
        } else { // avance == 0
            if ($estado === 'Culminada') $estado = 'En ejecución';
        }
    }

    db()->prepare('UPDATE seguimiento_obras SET avance_pct = :av, estado_obra = :eo WHERE id = :id')
        ->execute(['av' => $avance, 'eo' => $estado, 'id' => $obraId]);
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

    // Alcance por ENTE (reemplaza el antiguo "solo asignadas a mí"): un
    // usuario que pertenece a un ente (ente/gobernante) ve automáticamente
    // SOLO las obras asignadas a su ente. El master y quien no pertenece a
    // ningún ente no se filtran por este criterio (ven todo su ámbito).
    $miEnte = enteDelUsuario();
    if ($miEnte !== null && !usuarioEsMaster()) {
        $conds[] = 'so.ente_id = :mi_ente';
        $params['mi_ente'] = $miEnte;
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
    // Alcance por ente (igual que la lista): un usuario con ente solo cuenta
    // sus obras. Nota: esto filtra por so.ente_id, así que las inspecciones
    // sin obra (sin_seguimiento) no aplican a un usuario de ente.
    $miEnte = enteDelUsuario();
    if ($miEnte !== null && !usuarioEsMaster()) {
        $conds[] = 'so.ente_id = :mi_ente';
        $params['mi_ente'] = $miEnte;
    }
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
