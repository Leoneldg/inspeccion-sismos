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
    aplicarScopeParroquia($conds, $params, 'i');

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
               i.latitud, i.longitud, i.uso_edificacion, i.num_pisos,
               (COALESCE(i.hombres,0)+COALESCE(i.mujeres,0)+COALESCE(i.ninos,0)
                +COALESCE(i.adultos_tercera_edad,0)+COALESCE(i.gestantes,0)) AS personas,
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
/**
 * Edificaciones registradas en campo (las que no estaban en el listado).
 * Se identifican por la bitácora, que es lo más confiable: el prefijo del
 * código no distingue las creadas por el formulario antiguo.
 *
 * $soloConteo = true devuelve solo el número (para el KPI).
 */
function segEdificacionesAgregadas(bool $soloConteo = false)
{
    recAsegurarAuditoria();
    recAsegurarColumnasEtiqueta();

    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $where = $conds ? (' AND ' . implode(' AND ', $conds)) : '';

    try {
        if ($soloConteo) {
            $st = db()->prepare("
                SELECT COUNT(DISTINCT i.id)
                  FROM inspecciones i
                  LEFT JOIN rec_auditoria a ON a.inspeccion_id = i.id
                                           AND a.accion = 'edificacion_agregada'
                 WHERE a.id IS NOT NULL $where
            ");
            $st->execute($params);
            return (int)$st->fetchColumn();
        }

        // Se identifican por la bitácora O por el prefijo del código:
        // las registradas en campo usan INS-, las importadas usan IMP-.
        // Así siguen apareciendo aunque falte el registro de auditoría.
        $st = db()->prepare("
            SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.municipio,
                   TRIM(CONCAT_WS(', ', NULLIF(i.avenida_calle,''), NULLIF(i.sector,''), NULLIF(i.urbanizacion,''))) AS direccion,
                   i.latitud, i.longitud, i.decision_final,
                   i.uso_edificacion, i.num_pisos, i.familias AS numero_familias, i.numero_personas,
                   i.observaciones, i.fecha_inspeccion, i.creado_en,
                   re.id AS edificio_id, re.completado,
                   re.sin_etiqueta, re.etiqueta_motivo, re.etiqueta_obs,
                   COALESCE(MIN(a.creado_en), i.creado_en) AS registrada_en,
                   COALESCE(MIN(a.usuario_nombre), u.nombre_completo) AS registrada_por,
                   (SELECT COUNT(*) FROM rec_foto f
                     WHERE f.nivel = 'edificio' AND f.ref_id = re.id
                       AND f.parte = 'etiqueta') AS fotos_etiqueta
              FROM inspecciones i
              LEFT JOIN rec_auditoria a ON a.inspeccion_id = i.id
                                       AND a.accion = 'edificacion_agregada'
              LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
              LEFT JOIN usuarios u ON u.id = i.creado_por
             WHERE a.id IS NOT NULL $where
             GROUP BY i.id
             ORDER BY COALESCE(MIN(a.creado_en), i.creado_en) DESC
        ");
        $st->execute($params);
        return $st->fetchAll();

    } catch (Throwable $e) {
        return $soloConteo ? 0 : [];
    }
}

function segKpis(): array
{
    $pdo = db();
    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

    // Flujo tipo embudo:
    //   INSPECCIONES   = todas las edificaciones (total).
    //   RECONSTRUCCIÓN = levantamiento técnico CERRADO y avance < 100%.
    //                    (entra al cerrar la inspección técnica, aunque el
    //                     avance siga en 0%: ya está lista para reconstruir)
    //   CULMINADAS     = avance = 100%.
    //   SIN ASIGNAR    = el resto (sin levantamiento cerrado).
    // El avance de cada edificio = promedio de sus apartamentos.
    // Se garantiza que: INSPECCIONES = SIN ASIGNAR + RECONSTRUCCIÓN + CULMINADAS.
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_edificios,
            SUM(CASE WHEN re.completado = 1 AND COALESCE(av.avance,0) < 100 THEN 1 ELSE 0 END) AS en_ejecucion,
            SUM(CASE WHEN re.completado = 1 AND COALESCE(av.avance,0) >= 100 THEN 1 ELSE 0 END) AS culminadas,
            SUM(CASE WHEN re.completado IS NULL OR re.completado = 0 THEN 1 ELSE 0 END) AS sin_seguimiento,
            COALESCE(AVG(CASE WHEN re.completado = 1 THEN COALESCE(av.avance,0) END), 0) AS avance_promedio
        FROM inspecciones i
        LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
        LEFT JOIN (
            SELECT re2.inspeccion_id, AVG(COALESCE(aa.porcentaje, 0)) AS avance
              FROM rec_edificio re2
              JOIN rec_piso pi ON pi.edificio_id = re2.id
              JOIN rec_apartamento ap ON ap.piso_id = pi.id
              LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap.id
             GROUP BY re2.inspeccion_id
        ) av ON av.inspeccion_id = i.id
        $where
    ");
    $stmt->execute($params);
    $r = $stmt->fetch();

    // Blindaje: forzar que la suma cuadre exactamente con el total.
    // (Si por algún dato raro no sumara, "sin asignar" absorbe la diferencia.)
    $total = (int)$r['total_edificios'];
    $recon = (int)$r['en_ejecucion'];
    $culm  = (int)$r['culminadas'];
    $r['sin_seguimiento'] = max(0, $total - $recon - $culm);

    // Edificaciones registradas en campo (no venían en el listado original).
    $r['agregadas'] = segEdificacionesAgregadas(true);

    // Apartamentos que necesitan reparación: basta un ambiente marcado.
    $ar = segAptosAReparar();
    $r['aptos_reparar']     = $ar['aptos_reparar'];
    $r['aptos_total']       = $ar['aptos_total'];
    $r['ambientes_reparar'] = $ar['ambientes'];

    return $r;
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

// =====================================================================
// FASES DE RECUPERACIÓN (para el mapa de Seguimiento y Control)
// ---------------------------------------------------------------------
// Se derivan de los campos que YA existen en seguimiento_obras
// (estado_obra + avance_pct); no requieren cambios en la base de datos.
//
//   Sin asignar → la obra aún no tiene seguimiento (o está "Sin iniciar")
//   Fase 1      → Evaluación y remoción      (En ejecución, avance 0%)
//   Fase 2      → Rehabilitación/mantenimiento (En ejecución, con avance > 0)
//   Fase 3      → Culminada                   (estado_obra = Culminada)
//
// La fase 2 es la que muestra el ícono de mantenimiento en el botón.
// =====================================================================

/** Catálogo de las fases de recuperación. */
function segFasesRecuperacion(): array
{
    return [
        0 => ['nombre' => 'Sin asignar',                  'color' => '#767c94', 'icono' => 'bi-plus-circle'],
        1 => ['nombre' => 'Evaluación y remoción',        'color' => '#C9A227', 'icono' => 'bi-cone-striped'],
        2 => ['nombre' => 'Rehabilitación / mantenimiento','color' => '#2d4488', 'icono' => 'bi-tools'],
        3 => ['nombre' => 'Culminada',                    'color' => '#2E7D32', 'icono' => 'bi-check-circle-fill'],
    ];
}

/**
 * Deduce la fase de recuperación (0-3) a partir del estado de obra y el avance.
 * Recibe la fila tal como la devuelve segListaEdificios().
 */
function segFaseDe(?string $estadoObra, $avancePct = 0): int
{
    if ($estadoObra === null || $estadoObra === '' || $estadoObra === 'Sin iniciar') return 0;
    if ($estadoObra === 'Culminada') return 3;
    // "En ejecución" o "Suspendida": si ya hay avance registrado, va en fase 2.
    return ((float)$avancePct > 0) ? 2 : 1;
}

/**
 * Asigna/avanza la fase de recuperación de una inspección, escribiendo en los
 * campos existentes de seguimiento_obras. Devuelve la nueva fase (0-3).
 * Las fases avanzan en orden: 0 → 1 → 2 → 3.
 */
function segAsignarFase(int $inspeccionId, int $faseDestino): int
{
    $faseDestino = max(1, min(3, $faseDestino));
    $obra   = segObtenerOCrearObra($inspeccionId);   // crea la obra si no existía
    $obraId = (int)$obra['id'];

    // Traducción de fase -> campos reales existentes.
    if ($faseDestino === 3) {
        $estado = 'Culminada';
        $avance = 100.00;
    } elseif ($faseDestino === 2) {
        $estado = 'En ejecución';
        // Si aún no hay avance, se marca un avance mínimo para que la fase 2
        // quede registrada de forma consistente con segFaseDe().
        $avance = ((float)($obra['avance_pct'] ?? 0) > 0) ? (float)$obra['avance_pct'] : 1.00;
    } else {
        $estado = 'En ejecución';
        $avance = 0.00;
    }

    $sql = 'UPDATE seguimiento_obras
               SET estado_obra = :e, avance_pct = :a, actualizado_por = :u';
    $params = [
        'e' => $estado,
        'a' => $avance,
        'u' => $_SESSION['user_id'] ?? null,
        'o' => $obraId,
    ];
    // Al iniciar la recuperación se registra la fecha de inicio si no había.
    if ($faseDestino >= 1 && empty($obra['fecha_inicio'])) {
        $sql .= ', fecha_inicio = CURDATE()';
    }
    // Al culminar se registra la fecha de fin real.
    if ($faseDestino === 3) {
        $sql .= ', fecha_fin_real = CURDATE()';
    }
    $sql .= ' WHERE id = :o';

    db()->prepare($sql)->execute($params);

    $fases = segFasesRecuperacion();
    segBitacora($obraId, 'Fase de recuperación', 'Asignada fase ' . $faseDestino . ': ' . $fases[$faseDestino]['nombre']);

    return $faseDestino;
}

/**
 * Asigna un ente a la obra de una inspección (fase de recuperación).
 * Si la obra no existía, se crea. Al asignar el ente la obra pasa a
 * "En ejecución" (queda formalmente en fase de recuperación).
 * Devuelve los datos del ente asignado.
 */
function segAsignarEnte(int $inspeccionId, int $enteId): array
{
    $obra   = segObtenerOCrearObra($inspeccionId);
    $obraId = (int)$obra['id'];

    // El ente debe existir y estar activo.
    $st = db()->prepare('SELECT id, nombre FROM entes WHERE id = :e AND activo = 1 LIMIT 1');
    $st->execute(['e' => $enteId]);
    $ente = $st->fetch();
    if (!$ente) {
        throw new RuntimeException('El ente seleccionado no existe o no está activo.');
    }

    // Al asignar el ente la obra entra en ejecución (si aún no estaba culminada).
    $estadoActual = $obra['estado_obra'] ?? 'Sin iniciar';
    $nuevoEstado  = ($estadoActual === 'Culminada') ? 'Culminada' : 'En ejecución';

    $sql = 'UPDATE seguimiento_obras
               SET ente_id = :e, estado_obra = :eo, actualizado_por = :u';
    if (empty($obra['fecha_inicio'])) {
        $sql .= ', fecha_inicio = CURDATE()';
    }
    $sql .= ' WHERE id = :o';

    db()->prepare($sql)->execute([
        'e'  => (int)$ente['id'],
        'eo' => $nuevoEstado,
        'u'  => $_SESSION['user_id'] ?? null,
        'o'  => $obraId,
    ]);

    segBitacora($obraId, 'ente_asignado', 'Ente asignado para fase de recuperación: ' . $ente['nombre']);

    return ['id' => (int)$ente['id'], 'nombre' => $ente['nombre']];
}

// =====================================================================
// REPRESENTANTES POR PARROQUIA (Fase 1 de Reconstrucción)
// =====================================================================

/** Lista todos los representantes activos con sus parroquias asignadas. */
function repListar(): array
{
    repAsegurarTablas();
    $pdo = db();
    $reps = $pdo->query('SELECT * FROM representantes WHERE activo = 1 ORDER BY nombre')->fetchAll();
    if (!$reps) return [];
    // Cargar las parroquias de cada uno.
    $ids = array_column($reps, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM representante_parroquia WHERE representante_id IN ($in) ORDER BY parroquia");
    $st->execute($ids);
    $porRep = [];
    foreach ($st->fetchAll() as $rp) {
        $porRep[$rp['representante_id']][] = $rp;
    }
    foreach ($reps as &$r) {
        $r['parroquias'] = $porRep[$r['id']] ?? [];
    }
    return $reps;
}

/** Representantes asignados a una parroquia dada. */
function repDeParroquia(string $estado, string $parroquia): array
{
    $st = db()->prepare(
        'SELECT r.*
           FROM representantes r
           JOIN representante_parroquia rp ON rp.representante_id = r.id
          WHERE r.activo = 1 AND rp.estado = :e AND rp.parroquia = :p
          ORDER BY r.nombre'
    );
    $st->execute(['e' => $estado, 'p' => $parroquia]);
    return $st->fetchAll();
}

/** Crea un representante y devuelve su id. */
/**
 * Asegura que existan las tablas de representantes (por si no se corrió el SQL).
 */
// =====================================================================
// FRENTES DE TRABAJO POR PARROQUIA
// =====================================================================

/** Crea la tabla de frentes de trabajo si no existe. */
// =====================================================================
// FRENTES DE TRABAJO NUMERADOS (con supervisión y cuadrillas)
// =====================================================================

/** Crea las tablas de la nueva estructura si faltan. */
// =====================================================================
// ROL FRENTE DE TRABAJO: alcance limitado a su propio frente
// =====================================================================

/** Asegura la columna que vincula usuario y frente, y la tabla obra-cuadrilla. */
function frenteRolAsegurar(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('frente_id', $cols, true)) {
            db()->exec("ALTER TABLE usuarios ADD COLUMN frente_id INT UNSIGNED DEFAULT NULL");
        }
        db()->exec("CREATE TABLE IF NOT EXISTS obra_cuadrilla (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspeccion_id INT UNSIGNED NOT NULL,
            cuadrilla_id INT UNSIGNED NOT NULL,
            tarea VARCHAR(150) DEFAULT NULL,
            asignado_por INT UNSIGNED DEFAULT NULL,
            asignado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_obra_cuadrilla (inspeccion_id, cuadrilla_id),
            KEY idx_oc_cuadrilla (cuadrilla_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir */ }
}

/** Frente al que pertenece el usuario actual (0 si no está limitado). */
function frenteDelUsuario(): int
{
    if (usuarioEsMaster()) return 0;
    if (isset($_SESSION['frente_id'])) return (int)$_SESSION['frente_id'];
    frenteRolAsegurar();
    try {
        $st = db()->prepare('SELECT frente_id FROM usuarios WHERE id = :id');
        $st->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
        $_SESSION['frente_id'] = (int)($st->fetchColumn() ?: 0);
        return (int)$_SESSION['frente_id'];
    } catch (Throwable $e) { return 0; }
}

/** True si el usuario solo debe ver lo de su frente. */
function usuarioLimitadoAFrente(): bool
{
    return frenteDelUsuario() > 0;
}

/**
 * Edificaciones asignadas a un frente, con su avance y las cuadrillas
 * que ya tienen trabajo en cada una.
 */
function obrasDeFrente(int $frenteId): array
{
    frenteNumAsegurarTablas();
    frenteRolAsegurar();
    if ($frenteId <= 0) return [];

    try {
        $st = db()->prepare("
            SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, TRIM(CONCAT_WS(', ', NULLIF(i.avenida_calle,''), NULLIF(i.sector,''), NULLIF(i.urbanizacion,''))) AS direccion,
                   i.decision_final, i.latitud, i.longitud,
                   re.id AS edificio_id, re.completado,
                   a.asignado_en,
                   COALESCE(ROUND(x.pct), 0) AS avance
              FROM asignacion_frente_obra a
              JOIN inspecciones i ON i.id = a.inspeccion_id
              LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
              LEFT JOIN (
                  SELECT re2.inspeccion_id, AVG(COALESCE(aa.porcentaje, 0)) AS pct
                    FROM rec_edificio re2
                    JOIN rec_piso pi ON pi.edificio_id = re2.id
                    JOIN rec_apartamento ap ON ap.piso_id = pi.id
                    LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap.id
                   GROUP BY re2.inspeccion_id
              ) x ON x.inspeccion_id = i.id
             WHERE a.frente_id = :f
             ORDER BY i.parroquia, i.nombre_edificio
        ");
        $st->execute(['f' => $frenteId]);
        $obras = $st->fetchAll();
        if (!$obras) return [];

        // Brigadas asignadas a cada obra.
        $ids = implode(',', array_map(fn($o) => (int)$o['id'], $obras));
        $porObra = [];
        try {
            foreach (db()->query("
                SELECT ob.inspeccion_id, b.id AS brigada_id, b.numero
                  FROM obra_brigada ob
                  JOIN brigada b ON b.id = ob.brigada_id
                 WHERE ob.inspeccion_id IN ($ids)
                 ORDER BY b.numero
            ")->fetchAll() as $r) {
                $porObra[(int)$r['inspeccion_id']][] = $r;
            }
        } catch (Throwable $e) { /* sin brigadas */ }

        foreach ($obras as &$o) {
            $o['brigadas'] = $porObra[(int)$o['id']] ?? [];
            $o['cuadrillas'] = $o['brigadas'];   // compatibilidad
        }
        unset($o);
        return $obras;

    } catch (Throwable $e) { return []; }
}

/** Asigna una cuadrilla a una edificación (varias pueden trabajar a la vez). */
function asignarCuadrillaAObra(int $inspeccionId, int $cuadrillaId, ?string $tarea = null): void
{
    frenteRolAsegurar();
    db()->prepare(
        'INSERT INTO obra_cuadrilla (inspeccion_id, cuadrilla_id, tarea, asignado_por)
         VALUES (:i, :c, :t, :u)
         ON DUPLICATE KEY UPDATE tarea = VALUES(tarea), asignado_por = VALUES(asignado_por)'
    )->execute([
        'i' => $inspeccionId, 'c' => $cuadrillaId,
        't' => $tarea ?: null, 'u' => $_SESSION['user_id'] ?? null,
    ]);

    try {
        $st = db()->prepare('SELECT numero, nombre FROM cuadrilla WHERE id = :c');
        $st->execute(['c' => $cuadrillaId]);
        $c = $st->fetch();
        recAuditar('cuadrilla_asignada', $inspeccionId, null,
            'Cuadrilla ' . ($c['numero'] ?? $cuadrillaId)
            . ($tarea ? ' · ' . $tarea : ''));
    } catch (Throwable $e) { /* no interrumpir */ }
}

/** Quita una cuadrilla de una edificación. */
function quitarCuadrillaDeObra(int $inspeccionId, int $cuadrillaId): void
{
    frenteRolAsegurar();
    db()->prepare('DELETE FROM obra_cuadrilla WHERE inspeccion_id = :i AND cuadrilla_id = :c')
        ->execute(['i' => $inspeccionId, 'c' => $cuadrillaId]);
    recAuditar('cuadrilla_removida', $inspeccionId, null, 'Cuadrilla #' . $cuadrillaId);
}

/** Carga de trabajo de cada brigada del frente. */
function cargaDeCuadrillas(int $frenteId): array
{
    frenteRespAsegurar();
    try {
        $st = db()->prepare("
            SELECT b.id, b.numero,
                   COUNT(DISTINCT ob.inspeccion_id) AS obras
              FROM brigada b
              LEFT JOIN obra_brigada ob ON ob.brigada_id = b.id
             WHERE b.frente_id = :f AND b.activa = 1
             GROUP BY b.id
             ORDER BY b.numero
        ");
        $st->execute(['f' => $frenteId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// =====================================================================
// FRENTES POR RESPONSABLE Y BRIGADAS NUMERADAS
// =====================================================================

/** Asegura las columnas y tablas del modelo por responsable. */
function frenteRespAsegurar(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    frenteNumAsegurarTablas();
    try {
        $cols = db()->query("SHOW COLUMNS FROM frente")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('responsable_id', $cols, true)) {
            db()->exec("ALTER TABLE frente ADD COLUMN responsable_id INT UNSIGNED DEFAULT NULL");
        }
        if (!in_array('parroquia', $cols, true)) {
            db()->exec("ALTER TABLE frente ADD COLUMN parroquia VARCHAR(120) DEFAULT NULL");
        }
        db()->exec("CREATE TABLE IF NOT EXISTS brigada (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            frente_id INT UNSIGNED NOT NULL,
            numero INT UNSIGNED NOT NULL,
            activa TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_brigada (frente_id, numero)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS obra_brigada (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspeccion_id INT UNSIGNED NOT NULL,
            brigada_id INT UNSIGNED NOT NULL,
            asignado_por INT UNSIGNED DEFAULT NULL,
            asignado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_obra_brigada (inspeccion_id, brigada_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir */ }
}

/**
 * Siguiente número de frente. La numeración es CORRELATIVA GLOBAL y
 * REUTILIZA los huecos: si se borró el Frente 3, el próximo será el 3.
 * Así la secuencia no deja saltos cuando se elimina alguno.
 */
function frenteSiguienteGlobal(): int
{
    frenteRespAsegurar();
    try {
        // Números en uso (solo los activos: los desactivados liberan el suyo).
        $usados = db()->query('SELECT numero FROM frente WHERE activo = 1 ORDER BY numero')
                      ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $usados = array_map('intval', $usados);

        // Buscar el primer hueco disponible.
        $n = 1;
        foreach ($usados as $u) {
            if ($u > $n) break;   // encontramos el hueco
            if ($u === $n) $n++;
        }
        return $n;
    } catch (Throwable $e) { return 1; }
}

/** Siguiente número de brigada dentro de un frente (empieza en 1). */
function brigadaSiguiente(int $frenteId): int
{
    frenteRespAsegurar();
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM brigada WHERE frente_id = :f');
        $st->execute(['f' => $frenteId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 1; }
}

/**
 * Crea un frente asignándole el siguiente número correlativo.
 * Devuelve [id, numero].
 */
function frenteCrear(int $responsableId, string $parroquia,
                    string $estado = 'Distrito Capital', string $nombre = ''): array
{
    frenteRespAsegurar();
    $numero = frenteSiguienteGlobal();
    $nombre = trim($nombre) ?: null;   // nombre del equipo, opcional

    // Reintento defensivo: si dos personas crean a la vez, avanzar.
    for ($i = 0; $i < 20; $i++) {
        try {
            db()->prepare(
                'INSERT INTO frente (numero, nombre, responsable_id, parroquia, estado, creado_por)
                 VALUES (:n, :nom, :r, :p, :e, :u)'
            )->execute([
                'n' => $numero, 'nom' => $nombre,
                'r' => $responsableId ?: null,
                'p' => $parroquia ?: null, 'e' => $estado,
                'u' => $_SESSION['user_id'] ?? null,
            ]);
            $id = (int)db()->lastInsertId();
            recAuditar('frente_creado', null, null,
                'Frente de Trabajo ' . $numero . ($nombre ? ' · ' . $nombre : '')
                . ' · ' . $parroquia);
            return ['id' => $id, 'numero' => $numero, 'nombre' => $nombre];
        } catch (Throwable $e) {
            $numero++;   // número tomado, probar el siguiente
        }
    }
    throw new RuntimeException('No se pudo asignar un número de frente.');
}

/** Cambia el nombre del equipo de un frente. */
function frenteRenombrar(int $frenteId, string $nombre): void
{
    frenteRespAsegurar();
    db()->prepare('UPDATE frente SET nombre = :n WHERE id = :id')
        ->execute(['n' => trim($nombre) ?: null, 'id' => $frenteId]);
}

/** Frentes de un responsable, con sus brigadas y obras. */
function frentesDeResponsable(int $responsableId): array
{
    frenteRespAsegurar();
    try {
        $st = db()->prepare('SELECT * FROM frente
                              WHERE responsable_id = :r AND activo = 1
                              ORDER BY numero');
        $st->execute(['r' => $responsableId]);
        $frentes = $st->fetchAll();
        return frenteAdjuntarBrigadas($frentes);
    } catch (Throwable $e) { return []; }
}

/** Frentes que operan en una parroquia. */
function frentesEnParroquia(string $parroquia): array
{
    frenteRespAsegurar();
    try {
        $st = db()->prepare('SELECT * FROM frente
                              WHERE parroquia = :p AND activo = 1
                              ORDER BY numero');
        $st->execute(['p' => $parroquia]);
        return frenteAdjuntarBrigadas($st->fetchAll());
    } catch (Throwable $e) { return []; }
}

/** Agrega a cada frente sus brigadas y el conteo de obras. */
function frenteAdjuntarBrigadas(array $frentes): array
{
    if (!$frentes) return [];
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $frentes));

    $brigadas = [];
    try {
        foreach (db()->query("SELECT b.*,
                     (SELECT COUNT(*) FROM obra_brigada ob WHERE ob.brigada_id = b.id) AS obras
                   FROM brigada b
                  WHERE b.frente_id IN ($ids) AND b.activa = 1
                  ORDER BY b.numero")->fetchAll() as $b) {
            $brigadas[(int)$b['frente_id']][] = $b;
        }
    } catch (Throwable $e) { /* sin brigadas */ }

    $obras = [];
    try {
        foreach (db()->query("SELECT frente_id, COUNT(*) AS n
                                FROM asignacion_frente_obra
                               WHERE frente_id IN ($ids) GROUP BY frente_id")->fetchAll() as $r) {
            $obras[(int)$r['frente_id']] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* sin obras */ }

    foreach ($frentes as &$f) {
        $id = (int)$f['id'];
        $f['etiqueta'] = 'Frente de Trabajo ' . (int)$f['numero'];
        $f['brigadas'] = $brigadas[$id] ?? [];
        $f['obras']    = $obras[$id] ?? 0;
    }
    unset($f);
    return $frentes;
}

/**
 * Totales para el panel del responsable: cuántos frentes y cuántas
 * brigadas tiene en total, sumando todas sus parroquias.
 */
function totalesDeResponsable(int $responsableId): array
{
    frenteRespAsegurar();
    try {
        $st = db()->prepare("
            SELECT COUNT(DISTINCT f.id) AS frentes,
                   COUNT(DISTINCT b.id) AS brigadas,
                   COUNT(DISTINCT a.inspeccion_id) AS obras
              FROM frente f
              LEFT JOIN brigada b ON b.frente_id = f.id AND b.activa = 1
              LEFT JOIN asignacion_frente_obra a ON a.frente_id = f.id
             WHERE f.responsable_id = :r AND f.activo = 1
        ");
        $st->execute(['r' => $responsableId]);
        $r = $st->fetch() ?: [];
        return [
            'frentes'  => (int)($r['frentes'] ?? 0),
            'brigadas' => (int)($r['brigadas'] ?? 0),
            'obras'    => (int)($r['obras'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['frentes' => 0, 'brigadas' => 0, 'obras' => 0];
    }
}

/** Totales por parroquia, para el desglose del responsable. */
function totalesPorParroquia(array $parroquias): array
{
    frenteRespAsegurar();
    if (!$parroquias) return [];
    try {
        $marcas = [];
        $params = [];
        foreach ($parroquias as $i => $p) {
            $marcas[] = ':p' . $i;
            $params['p' . $i] = $p;
        }
        $in = implode(',', $marcas);
        $st = db()->prepare("
            SELECT f.parroquia,
                   COUNT(DISTINCT f.id) AS frentes,
                   COUNT(DISTINCT b.id) AS brigadas
              FROM frente f
              LEFT JOIN brigada b ON b.frente_id = f.id AND b.activa = 1
             WHERE f.parroquia IN ($in) AND f.activo = 1
             GROUP BY f.parroquia
        ");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['parroquia']] = [
                'frentes'  => (int)$r['frentes'],
                'brigadas' => (int)$r['brigadas'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Progreso de cada frente en una parroquia: cuántas obras tiene
 * y cómo van. Reemplaza al progreso por integrante del equipo GDC.
 */
function progresoFrentesParroquia(string $estado, string $parroquia): array
{
    frenteRespAsegurar();
    $avances = recAvancesDeParroquia($estado, $parroquia);
    try {
        $st = db()->prepare("
            SELECT f.id, f.numero,
                   a.inspeccion_id,
                   (SELECT COUNT(*) FROM brigada b
                     WHERE b.frente_id = f.id AND b.activa = 1) AS brigadas
              FROM frente f
              LEFT JOIN asignacion_frente_obra a ON a.frente_id = f.id
             WHERE f.activo = 1 AND f.parroquia = :p
             ORDER BY f.numero
        ");
        $st->execute(['p' => $parroquia]);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $fid = (int)$r['id'];
            if (!isset($out[$fid])) {
                $out[$fid] = [
                    'numero' => (int)$r['numero'],
                    'brigadas' => (int)$r['brigadas'],
                    'total' => 0, 'culminadas' => 0, 'en_proceso' => 0,
                    'sin_comenzar' => 0, 'suma' => 0, 'avance' => 0,
                ];
            }
            if ($r['inspeccion_id']) {
                $pct = (int)($avances[(int)$r['inspeccion_id']] ?? 0);
                $out[$fid]['total']++;
                $out[$fid]['suma'] += $pct;
                if ($pct >= 100)   $out[$fid]['culminadas']++;
                elseif ($pct > 0)  $out[$fid]['en_proceso']++;
                else               $out[$fid]['sin_comenzar']++;
            }
        }
        foreach ($out as $k => $v) {
            $out[$k]['avance'] = $v['total'] > 0 ? (int)round($v['suma'] / $v['total']) : 0;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Crea una brigada en un frente, con número correlativo interno. */
function brigadaCrear(int $frenteId): array
{
    frenteRespAsegurar();
    $numero = brigadaSiguiente($frenteId);
    for ($i = 0; $i < 20; $i++) {
        try {
            db()->prepare('INSERT INTO brigada (frente_id, numero, creado_por)
                           VALUES (:f, :n, :u)')
                ->execute(['f' => $frenteId, 'n' => $numero, 'u' => $_SESSION['user_id'] ?? null]);
            $id = (int)db()->lastInsertId();
            recAuditar('brigada_creada', null, null,
                'Brigada ' . $numero . ' del frente #' . $frenteId);
            return ['id' => $id, 'numero' => $numero];
        } catch (Throwable $e) {
            $numero++;
        }
    }
    throw new RuntimeException('No se pudo crear la brigada.');
}

function frenteNumAsegurarTablas(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    $t = [
        "CREATE TABLE IF NOT EXISTS frente (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero INT UNSIGNED NOT NULL,
            nombre VARCHAR(120) DEFAULT NULL,
            ente_id INT UNSIGNED DEFAULT NULL,
            estado VARCHAR(100) NOT NULL DEFAULT 'Distrito Capital',
            observaciones VARCHAR(400) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_frente_numero (numero, estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS frente_parroquia (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            frente_id INT UNSIGNED NOT NULL,
            estado VARCHAR(100) NOT NULL DEFAULT 'Distrito Capital',
            parroquia VARCHAR(120) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_fp (frente_id, estado, parroquia),
            KEY idx_fp_parroquia (estado, parroquia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS frente_supervisor (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            frente_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            cedula VARCHAR(20) DEFAULT NULL,
            telefono VARCHAR(40) DEFAULT NULL,
            cargo VARCHAR(80) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY idx_fs_frente (frente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS cuadrilla (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            frente_id INT UNSIGNED NOT NULL,
            numero INT UNSIGNED NOT NULL,
            nombre VARCHAR(120) DEFAULT NULL,
            especialidad VARCHAR(80) DEFAULT NULL,
            activa TINYINT(1) NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_cuadrilla (frente_id, numero)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS cuadrilla_integrante (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cuadrilla_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            cedula VARCHAR(20) DEFAULT NULL,
            telefono VARCHAR(40) DEFAULT NULL,
            oficio VARCHAR(80) DEFAULT NULL,
            es_jefe TINYINT(1) NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY idx_ci_cuadrilla (cuadrilla_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS asignacion_frente_obra (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspeccion_id INT UNSIGNED NOT NULL,
            frente_id INT UNSIGNED NOT NULL,
            cuadrilla_id INT UNSIGNED DEFAULT NULL,
            asignado_por INT UNSIGNED DEFAULT NULL,
            asignado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_afo_inspeccion (inspeccion_id),
            KEY idx_afo_frente (frente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($t as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* seguir */ }
    }
}

/** Etiqueta legible de un frente: "Frente de Trabajo 3". */
function frenteEtiqueta(array $f): string
{
    $txt = 'Frente de Trabajo ' . (int)($f['numero'] ?? 0);
    if (!empty($f['nombre'])) $txt .= ' · ' . $f['nombre'];
    return $txt;
}

/** Lista los frentes con sus parroquias, supervisores y cuadrillas. */
function frentesNumerados(?string $estado = null, bool $soloActivos = true): array
{
    frenteNumAsegurarTablas();
    try {
        $conds = [];
        $params = [];
        if ($soloActivos) $conds[] = 'f.activo = 1';
        if ($estado)      { $conds[] = 'f.estado = :e'; $params['e'] = $estado; }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $st = db()->prepare("
            SELECT f.*, e.nombre AS ente_nombre
              FROM frente f
              LEFT JOIN entes e ON e.id = f.ente_id
              $where
             ORDER BY f.numero
        ");
        $st->execute($params);
        $frentes = $st->fetchAll();
        if (!$frentes) return [];

        $ids = implode(',', array_map(fn($f) => (int)$f['id'], $frentes));

        // Parroquias
        $parr = [];
        foreach (db()->query("SELECT frente_id, parroquia FROM frente_parroquia
                               WHERE frente_id IN ($ids) ORDER BY parroquia")->fetchAll() as $r) {
            $parr[(int)$r['frente_id']][] = $r['parroquia'];
        }
        // Supervisores
        $sup = [];
        foreach (db()->query("SELECT * FROM frente_supervisor
                               WHERE frente_id IN ($ids) AND activo = 1 ORDER BY id")->fetchAll() as $r) {
            $sup[(int)$r['frente_id']][] = $r;
        }
        // Cuadrillas con sus integrantes
        $cuad = [];
        $cuadrillas = db()->query("SELECT * FROM cuadrilla
                                    WHERE frente_id IN ($ids) AND activa = 1 ORDER BY numero")->fetchAll();
        if ($cuadrillas) {
            $cids = implode(',', array_map(fn($c) => (int)$c['id'], $cuadrillas));
            $ints = [];
            foreach (db()->query("SELECT * FROM cuadrilla_integrante
                                   WHERE cuadrilla_id IN ($cids) AND activo = 1
                                   ORDER BY es_jefe DESC, nombre")->fetchAll() as $r) {
                $ints[(int)$r['cuadrilla_id']][] = $r;
            }
            foreach ($cuadrillas as $c) {
                $c['integrantes'] = $ints[(int)$c['id']] ?? [];
                $cuad[(int)$c['frente_id']][] = $c;
            }
        }
        // Obras asignadas
        $obras = [];
        foreach (db()->query("SELECT frente_id, COUNT(*) AS n FROM asignacion_frente_obra
                               WHERE frente_id IN ($ids) GROUP BY frente_id")->fetchAll() as $r) {
            $obras[(int)$r['frente_id']] = (int)$r['n'];
        }

        foreach ($frentes as &$f) {
            $id = (int)$f['id'];
            $f['etiqueta']    = frenteEtiqueta($f);
            $f['parroquias']  = $parr[$id] ?? [];
            $f['supervisores']= $sup[$id] ?? [];
            $f['cuadrillas']  = $cuad[$id] ?? [];
            $f['obras']       = $obras[$id] ?? 0;
        }
        unset($f);
        return $frentes;

    } catch (Throwable $e) { return []; }
}

/** Frentes que cubren una parroquia concreta. */
function frentesDePar(string $estado, string $parroquia): array
{
    frenteNumAsegurarTablas();
    try {
        $st = db()->prepare("
            SELECT f.*
              FROM frente f
              JOIN frente_parroquia fp ON fp.frente_id = f.id
             WHERE f.activo = 1 AND fp.estado = :e AND fp.parroquia = :p
             ORDER BY f.numero
        ");
        $st->execute(['e' => $estado, 'p' => $parroquia]);
        $r = $st->fetchAll();
        foreach ($r as &$f) $f['etiqueta'] = frenteEtiqueta($f);
        unset($f);
        return $r;
    } catch (Throwable $e) { return []; }
}

/** Siguiente número libre de frente. */
function frenteSiguienteNumero(string $estado = 'Distrito Capital'): int
{
    frenteNumAsegurarTablas();
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM frente WHERE estado = :e');
        $st->execute(['e' => $estado]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 1; }
}

/** Siguiente número de cuadrilla dentro de un frente. */
function cuadrillaSiguienteNumero(int $frenteId): int
{
    frenteNumAsegurarTablas();
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM cuadrilla WHERE frente_id = :f');
        $st->execute(['f' => $frenteId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 1; }
}

/** Asigna una edificación a un frente (y opcionalmente a una cuadrilla). */
function asignarObraAFrente(int $inspeccionId, int $frenteId, ?int $cuadrillaId = null): void
{
    frenteNumAsegurarTablas();
    if ($frenteId <= 0) {
        db()->prepare('DELETE FROM asignacion_frente_obra WHERE inspeccion_id = :i')
            ->execute(['i' => $inspeccionId]);
        recAuditar('frente_removido', $inspeccionId, null, 'Sin frente asignado');
        return;
    }
    db()->prepare(
        'INSERT INTO asignacion_frente_obra (inspeccion_id, frente_id, cuadrilla_id, asignado_por)
         VALUES (:i, :f, :c, :u)
         ON DUPLICATE KEY UPDATE frente_id = VALUES(frente_id),
             cuadrilla_id = VALUES(cuadrilla_id), asignado_por = VALUES(asignado_por)'
    )->execute([
        'i' => $inspeccionId, 'f' => $frenteId,
        'c' => $cuadrillaId ?: null, 'u' => $_SESSION['user_id'] ?? null,
    ]);

    try {
        $st = db()->prepare('SELECT numero, nombre FROM frente WHERE id = :f');
        $st->execute(['f' => $frenteId]);
        $f = $st->fetch();
        recAuditar('frente_asignado', $inspeccionId, null,
            $f ? frenteEtiqueta($f) : ('Frente #' . $frenteId));
    } catch (Throwable $e) { /* no interrumpir */ }
}

/** Frente asignado a una edificación. */
function frenteDeObra(int $inspeccionId): ?array
{
    frenteNumAsegurarTablas();
    try {
        $st = db()->prepare("
            SELECT f.*, a.cuadrilla_id, c.numero AS cuadrilla_numero, c.nombre AS cuadrilla_nombre
              FROM asignacion_frente_obra a
              JOIN frente f ON f.id = a.frente_id
              LEFT JOIN cuadrilla c ON c.id = a.cuadrilla_id
             WHERE a.inspeccion_id = :i
        ");
        $st->execute(['i' => $inspeccionId]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['etiqueta'] = frenteEtiqueta($r);
        return $r;
    } catch (Throwable $e) { return null; }
}

/** Progreso de cada frente: obras asignadas y su avance. */
function frenteProgreso(?string $estado = null): array
{
    frenteNumAsegurarTablas();
    try {
        $conds = ['f.activo = 1'];
        $params = [];
        if ($estado) { $conds[] = 'f.estado = :e'; $params['e'] = $estado; }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $st = db()->prepare("
            SELECT f.id, f.numero, f.nombre,
                   COUNT(DISTINCT a.inspeccion_id) AS obras,
                   COALESCE(ROUND(AVG(x.pct)), 0) AS avance,
                   SUM(CASE WHEN x.pct >= 100 THEN 1 ELSE 0 END) AS culminadas
              FROM frente f
              LEFT JOIN asignacion_frente_obra a ON a.frente_id = f.id
              LEFT JOIN (
                  SELECT re.inspeccion_id, AVG(COALESCE(aa.porcentaje, 0)) AS pct
                    FROM rec_edificio re
                    JOIN rec_piso pi ON pi.edificio_id = re.id
                    JOIN rec_apartamento ap ON ap.piso_id = pi.id
                    LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap.id
                   GROUP BY re.inspeccion_id
              ) x ON x.inspeccion_id = a.inspeccion_id
              $where
             GROUP BY f.id
             ORDER BY f.numero
        ");
        $st->execute($params);
        $r = $st->fetchAll();
        foreach ($r as &$f) $f['etiqueta'] = frenteEtiqueta($f);
        unset($f);
        return $r;
    } catch (Throwable $e) { return []; }
}

function frenteAsegurarTabla(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS frente_trabajo (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            estado VARCHAR(100) NOT NULL DEFAULT 'Distrito Capital',
            parroquia VARCHAR(120) NOT NULL,
            tipo VARCHAR(60) NOT NULL,
            nombre VARCHAR(200) NOT NULL,
            telefono VARCHAR(60) DEFAULT NULL,
            sector VARCHAR(120) DEFAULT NULL,
            orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_frente_parroquia (estado, parroquia),
            KEY idx_frente_orden (orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir */ }
}

/** Etiquetas legibles de cada tipo de frente. */
function frenteTipos(): array
{
    return [
        'gdc'             => 'Equipo de trabajo GDC',
        'sistematizador'  => 'Sistematizador',
        'corporacion'     => 'Corporación de Servicios',
        'movilizaciones'  => 'Vicepresidencia de Movilizaciones',
    ];
}

/** Frentes de trabajo de una parroquia, en orden. */
function frentesDeParroquia(string $estado, string $parroquia): array
{
    frenteAsegurarTabla();
    try {
        $st = db()->prepare(
            'SELECT * FROM frente_trabajo
              WHERE estado = :e AND parroquia = :p AND activo = 1
              ORDER BY orden, sector IS NULL DESC, sector, nombre'
        );
        $st->execute(['e' => $estado, 'p' => $parroquia]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function repAsegurarTablas(): void
{
    static $verificado = false;
    if ($verificado) return;
    $verificado = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS representantes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(180) NOT NULL,
            cedula VARCHAR(30) DEFAULT NULL,
            telefono VARCHAR(30) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            cargo VARCHAR(120) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), KEY idx_rep_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS representante_parroquia (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            representante_id INT UNSIGNED NOT NULL,
            estado VARCHAR(100) NOT NULL,
            municipio VARCHAR(120) DEFAULT NULL,
            parroquia VARCHAR(120) NOT NULL,
            asignado_por INT UNSIGNED DEFAULT NULL,
            asignado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_rep_parroquia (representante_id, estado, parroquia),
            KEY idx_rp_parroquia (estado, parroquia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* si no se puede, seguir */ }
}

function repCrear(array $datos): int
{
    repAsegurarTablas();
    $st = db()->prepare(
        'INSERT INTO representantes (nombre, cedula, telefono, email, cargo, creado_por)
         VALUES (:n, :c, :t, :e, :ca, :u)'
    );
    $st->execute([
        'n'  => trim($datos['nombre'] ?? ''),
        'c'  => trim($datos['cedula'] ?? '') ?: null,
        't'  => trim($datos['telefono'] ?? '') ?: null,
        'e'  => trim($datos['email'] ?? '') ?: null,
        'ca' => trim($datos['cargo'] ?? '') ?: null,
        'u'  => $_SESSION['user_id'] ?? null,
    ]);
    return (int)db()->lastInsertId();
}

/** Asigna un representante a una parroquia (idempotente). */
function repAsignarParroquia(int $representanteId, string $estado, string $municipio, string $parroquia): void
{
    $st = db()->prepare(
        'INSERT IGNORE INTO representante_parroquia
            (representante_id, estado, municipio, parroquia, asignado_por)
         VALUES (:r, :e, :m, :p, :u)'
    );
    $st->execute([
        'r' => $representanteId,
        'e' => $estado,
        'm' => $municipio ?: null,
        'p' => $parroquia,
        'u' => $_SESSION['user_id'] ?? null,
    ]);
}

/** Quita la asignación de un representante a una parroquia. */
function repQuitarParroquia(int $representanteId, string $estado, string $parroquia): void
{
    $st = db()->prepare(
        'DELETE FROM representante_parroquia
          WHERE representante_id = :r AND estado = :e AND parroquia = :p'
    );
    $st->execute(['r' => $representanteId, 'e' => $estado, 'p' => $parroquia]);
}

/** Desactiva (borrado lógico) un representante. */
function repDesactivar(int $representanteId): void
{
    db()->prepare('UPDATE representantes SET activo = 0 WHERE id = :id')
        ->execute(['id' => $representanteId]);
}

// =====================================================================
// LEVANTAMIENTO TÉCNICO DEL EDIFICIO (Reconstrucción)
// =====================================================================

/** Devuelve el registro rec_edificio de una inspección, creándolo vacío si no existe. */
/**
 * ¿Puede este usuario EDITAR el levantamiento?
 *
 * Solo lo edita quien lo hizo, o un administrador. Los demás lo ven
 * en modo consulta: así nadie modifica el trabajo de otro por error.
 */
function recPuedeEditarLevantamiento(int $edificioId): bool
{
    if (usuarioEsMaster()) return true;

    // Los roles administrativos pueden editar cualquiera.
    $rol = $_SESSION['rol_nombre'] ?? '';
    if (in_array($rol, ['Administrador', 'Superadministrador'], true)) return true;

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;

    try {
        $st = db()->prepare('SELECT creado_por, completado_por, completado
                               FROM rec_edificio WHERE id = :e');
        $st->execute(['e' => $edificioId]);
        $ed = $st->fetch();
        if (!$ed) return false;

        // Todavía sin creador registrado: el primero que entra lo toma.
        if (empty($ed['creado_por'])) return true;

        return (int)$ed['creado_por'] === $uid
            || (int)($ed['completado_por'] ?? 0) === $uid;
    } catch (Throwable $e) {
        return false;
    }
}

/** Nombre de quien hizo el levantamiento, para mostrarlo en pantalla. */
function recAutorLevantamiento(int $edificioId): array
{
    try {
        $st = db()->prepare("
            SELECT re.creado_en, re.completado_en,
                   uc.nombre_completo AS creado_nombre,
                   uf.nombre_completo AS completado_nombre
              FROM rec_edificio re
              LEFT JOIN usuarios uc ON uc.id = re.creado_por
              LEFT JOIN usuarios uf ON uf.id = re.completado_por
             WHERE re.id = :id
        ");
        $st->execute(['id' => $edificioId]);
        return $st->fetch() ?: [];
    } catch (Throwable $e) { return []; }
}

function recEdificio(int $inspeccionId): array
{
    recAsegurarColumnasEtiqueta();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM rec_edificio WHERE inspeccion_id = :i');
    $st->execute(['i' => $inspeccionId]);
    $ed = $st->fetch();
    if ($ed) return $ed;

    $pdo->prepare('INSERT INTO rec_edificio (inspeccion_id, creado_por) VALUES (:i, :u)')
        ->execute(['i' => $inspeccionId, 'u' => $_SESSION['user_id'] ?? null]);
    $st->execute(['i' => $inspeccionId]);
    return $st->fetch();
}

/** Guarda los datos generales del edificio (Paso 1). */
function recGuardarEdificio(int $inspeccionId, array $d): int
{
    $ed = recEdificio($inspeccionId);
    $estados = ['Buena','Regular','Requiere reparación','No aplica'];
    $norm = fn($v) => in_array($v, $estados, true) ? $v : null;

    db()->prepare(
        'UPDATE rec_edificio SET
            num_pisos = :np, aptos_por_piso = :app,
            tiene_areas_comunes = :tac, areas_comunes_desc = :acd,
            azotea_estado = :ae, azotea_obs = :ao,
            tanques_estado = :te, tanques_obs = :to,
            impermeabilizacion_estado = :ie, impermeabilizacion_obs = :io,
            completado = 1
         WHERE id = :id'
    )->execute([
        'np'  => ($d['num_pisos'] ?? '') !== '' ? (int)$d['num_pisos'] : null,
        'app' => ($d['aptos_por_piso'] ?? '') !== '' ? (int)$d['aptos_por_piso'] : null,
        'tac' => !empty($d['tiene_areas_comunes']) ? 1 : 0,
        'acd' => trim($d['areas_comunes_desc'] ?? '') ?: null,
        'ae'  => $norm($d['azotea_estado'] ?? null),
        'ao'  => trim($d['azotea_obs'] ?? '') ?: null,
        'te'  => $norm($d['tanques_estado'] ?? null),
        'to'  => trim($d['tanques_obs'] ?? '') ?: null,
        'ie'  => $norm($d['impermeabilizacion_estado'] ?? null),
        'io'  => trim($d['impermeabilizacion_obs'] ?? '') ?: null,
        'id'  => $ed['id'],
    ]);
    return (int)$ed['id'];
}

/** Genera automáticamente los pisos del edificio según num_pisos (si no existen). */
function recGenerarPisos(int $edificioId, int $numPisos): void
{
    $pdo = db();
    $existentes = $pdo->prepare('SELECT numero_piso FROM rec_piso WHERE edificio_id = :e');
    $existentes->execute(['e' => $edificioId]);
    $ya = array_column($existentes->fetchAll(), 'numero_piso');

    $ins = $pdo->prepare('INSERT IGNORE INTO rec_piso (edificio_id, numero_piso) VALUES (:e, :n)');
    for ($n = 1; $n <= $numPisos; $n++) {
        if (!in_array($n, $ya)) $ins->execute(['e' => $edificioId, 'n' => $n]);
    }
}

/** Lista los pisos de un edificio. */
function recPisos(int $edificioId): array
{
    $st = db()->prepare('SELECT * FROM rec_piso WHERE edificio_id = :e ORDER BY numero_piso');
    $st->execute(['e' => $edificioId]);
    return $st->fetchAll();
}

/** Guarda datos de un piso (áreas comunes del piso). */
function recGuardarPiso(int $pisoId, array $d): void
{
    db()->prepare(
        'UPDATE rec_piso SET tiene_areas_comunes = :tac, areas_comunes_desc = :acd, completado = 1 WHERE id = :id'
    )->execute([
        'tac' => !empty($d['tiene_areas_comunes']) ? 1 : 0,
        'acd' => trim($d['areas_comunes_desc'] ?? '') ?: null,
        'id'  => $pisoId,
    ]);
}

/** Catálogo de elementos que puede tener un piso. */
function recTiposElementoPiso(): array
{
    return [
        'ascensor'       => 'Ascensor',
        'escaleras'      => 'Escaleras',
        'bajante_basura' => 'Bajante de basura',
        'jardinera'      => 'Jardineras',
        'pasillo'        => 'Pasillos',
        'iluminacion'    => 'Iluminación común',
    ];
}

/** Elementos registrados de un piso. */
function recElementosPiso(int $pisoId): array
{
    $st = db()->prepare('SELECT * FROM rec_elemento_piso WHERE piso_id = :p');
    $st->execute(['p' => $pisoId]);
    $out = [];
    foreach ($st->fetchAll() as $e) $out[$e['tipo']] = $e;
    return $out;
}

/** Guarda un elemento de piso (presente, estado, si necesita reparación). */
function recGuardarElementoPiso(int $pisoId, string $tipo, array $d): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM rec_elemento_piso WHERE piso_id = :p AND tipo = :t');
    $st->execute(['p' => $pisoId, 't' => $tipo]);
    $existe = $st->fetchColumn();

    $estados = ['Bueno','Regular','Requiere reparación','No funciona'];
    $estado = in_array($d['estado'] ?? '', $estados, true) ? $d['estado'] : null;
    $params = [
        'pres' => !empty($d['presente']) ? 1 : 0,
        'est'  => $estado,
        'rep'  => !empty($d['necesita_reparacion']) ? 1 : 0,
        'obs'  => trim($d['observaciones'] ?? '') ?: null,
    ];

    if ($existe) {
        $params['id'] = $existe;
        $pdo->prepare('UPDATE rec_elemento_piso SET presente=:pres, estado=:est, necesita_reparacion=:rep, observaciones=:obs WHERE id=:id')
            ->execute($params);
        return (int)$existe;
    }
    $params['p'] = $pisoId; $params['t'] = $tipo;
    $pdo->prepare('INSERT INTO rec_elemento_piso (piso_id, tipo, presente, estado, necesita_reparacion, observaciones) VALUES (:p,:t,:pres,:est,:rep,:obs)')
        ->execute($params);
    return (int)$pdo->lastInsertId();
}

/** Guarda una foto del levantamiento, en cualquier nivel. */
function recGuardarFoto(string $nivel, int $refId, string $ruta, ?string $parte = null, ?string $desc = null): int
{
    db()->prepare(
        'INSERT INTO rec_foto (nivel, ref_id, parte, ruta, descripcion, subido_por)
         VALUES (:n, :r, :p, :ru, :d, :u)'
    )->execute([
        'n' => $nivel, 'r' => $refId, 'p' => $parte, 'ru' => $ruta,
        'd' => $desc, 'u' => $_SESSION['user_id'] ?? null,
    ]);
    return (int)db()->lastInsertId();
}

/** Fotos de un registro de un nivel. */
function recFotos(string $nivel, int $refId): array
{
    $st = db()->prepare('SELECT * FROM rec_foto WHERE nivel = :n AND ref_id = :r ORDER BY creado_en');
    $st->execute(['n' => $nivel, 'r' => $refId]);
    return $st->fetchAll();
}

// =====================================================================

// ---------------------------------------------------------------------
// APARTAMENTOS Y AMBIENTES (Paso 3 del levantamiento)
// ---------------------------------------------------------------------

/** Lista los apartamentos de un piso. */
function recApartamentos(int $pisoId): array
{
    recAsegurarColumnasApartamento();
    $st = db()->prepare('SELECT * FROM rec_apartamento WHERE piso_id = :p ORDER BY id');
    $st->execute(['p' => $pisoId]);
    return $st->fetchAll();
}

/** Genera los apartamentos de un piso según una cantidad (si no existen ya). */
function recGenerarApartamentos(int $pisoId, int $cantidad, int $numeroPiso): array
{
    $existentes = recApartamentos($pisoId);
    $n = count($existentes);

    // Crear los que falten.
    for ($i = $n + 1; $i <= $cantidad; $i++) {
        // Identificador tipo "3-A", "3-B"… (piso + letra)
        $letra = chr(64 + $i); // A, B, C…
        $ident = $numeroPiso . '-' . ($i <= 26 ? $letra : $i);
        db()->prepare(
            'INSERT INTO rec_apartamento (piso_id, identificador) VALUES (:p, :id)'
        )->execute(['p' => $pisoId, 'id' => $ident]);
    }

    // Si se redujo la cantidad, eliminar los sobrantes (los últimos)
    // junto con sus ambientes, avances y fotos, para no dejar basura.
    if ($cantidad < $n) {
        $sobrantes = array_slice($existentes, $cantidad);
        foreach ($sobrantes as $ap) {
            recEliminarApartamento((int)$ap['id']);
        }
    }

    return recApartamentos($pisoId);
}

/**
 * Elimina un apartamento con todo lo que cuelga de él:
 * ambientes, avances y fotos (incluidos los archivos en disco).
 */
function recEliminarApartamento(int $apartamentoId): void
{
    $pdo = db();
    try {
        // Ambientes del apartamento.
        $st = $pdo->prepare('SELECT id FROM rec_ambiente WHERE apartamento_id = :a');
        $st->execute(['a' => $apartamentoId]);
        $ambIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Fotos (archivo + registro).
        $niveles = ['apartamento' => [$apartamentoId], 'ambiente' => $ambIds];
        foreach ($niveles as $nivel => $ids) {
            if (!$ids) continue;
            $in = implode(',', array_map('intval', $ids));
            $fotos = $pdo->query("SELECT ruta FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)")->fetchAll();
            foreach ($fotos as $f) {
                $abs = dirname(__DIR__) . '/' . ltrim($f['ruta'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            $pdo->exec("DELETE FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)");
        }

        if ($ambIds) {
            $in = implode(',', array_map('intval', $ambIds));
            try { $pdo->exec("DELETE FROM rec_avance_ambiente WHERE ambiente_id IN ($in)"); } catch (Throwable $e) {}
            try { $pdo->exec("DELETE FROM rec_reparacion WHERE nivel='ambiente' AND ref_id IN ($in)"); } catch (Throwable $e) {}
            $pdo->exec("DELETE FROM rec_ambiente WHERE id IN ($in)");
        }
        try { $pdo->prepare('DELETE FROM rec_avance_apto WHERE apartamento_id = :a')->execute(['a' => $apartamentoId]); } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM rec_apartamento WHERE id = :a')->execute(['a' => $apartamentoId]);
    } catch (Throwable $e) { /* no interrumpir la regeneración */ }
}

/** Actualiza las cantidades de ambientes de un apartamento y los genera. */
/**
 * Asegura que rec_apartamento tenga las columnas del jefe de familia y baños.
 * Si faltan, las crea. También asegura que rec_ambiente.tipo acepte 'Baño'.
 */
/**
 * Asegura las columnas para dejar constancia de que una edificación
 * no tiene etiqueta. Se crean solas si falta correr el SQL.
 */
function recAsegurarColumnasEtiqueta(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM rec_edificio")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('sin_etiqueta', $cols, true)) {
            db()->exec("ALTER TABLE rec_edificio ADD COLUMN sin_etiqueta TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('etiqueta_motivo', $cols, true)) {
            db()->exec("ALTER TABLE rec_edificio ADD COLUMN etiqueta_motivo VARCHAR(60) DEFAULT NULL");
        }
        if (!in_array('etiqueta_obs', $cols, true)) {
            db()->exec("ALTER TABLE rec_edificio ADD COLUMN etiqueta_obs VARCHAR(300) DEFAULT NULL");
        }
    } catch (Throwable $e) { /* seguir */ }
}

function recAsegurarColumnasApartamento(): void
{
    static $verificado = false;
    if ($verificado) return;
    $verificado = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM rec_apartamento")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('jefe_nombre', $cols, true))   db()->exec("ALTER TABLE rec_apartamento ADD COLUMN jefe_nombre VARCHAR(150) DEFAULT NULL");
        if (!in_array('jefe_cedula', $cols, true))   db()->exec("ALTER TABLE rec_apartamento ADD COLUMN jefe_cedula VARCHAR(20) DEFAULT NULL");
        if (!in_array('jefe_telefono', $cols, true)) db()->exec("ALTER TABLE rec_apartamento ADD COLUMN jefe_telefono VARCHAR(30) DEFAULT NULL");
        if (!in_array('num_banos', $cols, true))     db()->exec("ALTER TABLE rec_apartamento ADD COLUMN num_banos TINYINT UNSIGNED DEFAULT 0");
        // Asegurar que el enum de tipo de ambiente acepte 'Baño'.
        db()->exec("ALTER TABLE rec_ambiente MODIFY COLUMN tipo ENUM('Habitación','Sala','Baño','Balcón','Cocina','Otro') NOT NULL DEFAULT 'Habitación'");
    } catch (Throwable $e) { /* si no se puede, seguir */ }
}

function recGuardarApartamento(int $apartamentoId, array $d): void
{
    // Asegurar columnas del jefe de familia y baños (por si falta el SQL).
    recAsegurarColumnasApartamento();

    // Si un campo llega vacío (no enviado), se conserva lo que ya había:
    // un formulario incompleto no debe borrar ambientes con fotos.
    $actual = [];
    try {
        $stA = db()->prepare('SELECT num_habitaciones, num_salas, num_balcones,
                                     num_cocinas, num_banos
                                FROM rec_apartamento WHERE id = :a');
        $stA->execute(['a' => $apartamentoId]);
        $actual = $stA->fetch() ?: [];
    } catch (Throwable $e) {}

    $leerCant = function (string $clave, string $col) use ($d, $actual): int {
        // Vacío o ausente → mantener lo guardado.
        if (!isset($d[$clave]) || $d[$clave] === '' || $d[$clave] === null) {
            return max(0, (int)($actual[$col] ?? 0));
        }
        return max(0, (int)$d[$clave]);
    };

    $nh   = $leerCant('num_habitaciones', 'num_habitaciones');
    $ns   = $leerCant('num_salas',        'num_salas');
    $nb   = $leerCant('num_balcones',     'num_balcones');
    $nc   = $leerCant('num_cocinas',      'num_cocinas');
    $nban = $leerCant('num_banos',        'num_banos');

    db()->prepare(
        'UPDATE rec_apartamento SET num_habitaciones=:h, num_salas=:s, num_balcones=:b, num_cocinas=:c,
            num_banos=:ban, jefe_nombre=:jn, jefe_cedula=:jc, jefe_telefono=:jt, completado=1,
            registrado_por=:rp, registrado_en=NOW() WHERE id=:id'
    )->execute([
        'h'=>$nh, 's'=>$ns, 'b'=>$nb, 'c'=>$nc, 'ban'=>$nban,
        'jn'=>trim($d['jefe_nombre'] ?? '') ?: null,
        'jc'=>trim($d['jefe_cedula'] ?? '') ?: null,
        'jt'=>trim($d['jefe_telefono'] ?? '') ?: null,
        'rp'=>$_SESSION['user_id'] ?? null,
        'id'=>$apartamentoId,
    ]);

    // Auditoría: quién registró este apartamento.
    try {
        $st = db()->prepare(
            'SELECT ap.identificador, pi.edificio_id, re.inspeccion_id
               FROM rec_apartamento ap
               JOIN rec_piso pi ON pi.id = ap.piso_id
               JOIN rec_edificio re ON re.id = pi.edificio_id
              WHERE ap.id = :a'
        );
        $st->execute(['a' => $apartamentoId]);
        if ($r = $st->fetch()) {
            recAuditar('apartamento_registrado', (int)$r['inspeccion_id'], (int)$r['edificio_id'],
                'Apto ' . $r['identificador'] . ' · jefe de familia: ' . (trim($d['jefe_nombre'] ?? '') ?: 'sin nombre'));
        }
    } catch (Throwable $e) { /* no interrumpir */ }

    // Generar los ambientes según las cantidades (sin duplicar los existentes).
    $tipos = [
        'Habitación' => $nh,
        'Sala'       => $ns,
        'Baño'       => $nban,
        'Balcón'     => $nb,
        'Cocina'     => $nc,
    ];
    foreach ($tipos as $tipo => $cant) {
        $st = db()->prepare('SELECT COUNT(*) FROM rec_ambiente WHERE apartamento_id=:a AND tipo=:t');
        $st->execute(['a'=>$apartamentoId, 't'=>$tipo]);
        $ya = (int)$st->fetchColumn();
        // Crear los que falten
        for ($n = $ya + 1; $n <= $cant; $n++) {
            db()->prepare(
                'INSERT INTO rec_ambiente (apartamento_id, tipo, numero) VALUES (:a, :t, :n)'
            )->execute(['a'=>$apartamentoId, 't'=>$tipo, 'n'=>$n]);
        }
        // Si redujeron la cantidad, borrar los sobrantes. PERO nunca se
        // borra un ambiente que ya tenga fotos o metros registrados: eso
        // sería perder trabajo de campo. El técnico debe quitarlos a mano.
        if ($cant < $ya) {
            $del = db()->prepare('SELECT id FROM rec_ambiente
                                   WHERE apartamento_id=:a AND tipo=:t AND numero > :c
                                   ORDER BY numero DESC');
            $del->execute(['a'=>$apartamentoId, 't'=>$tipo, 'c'=>$cant]);

            foreach ($del->fetchAll(PDO::FETCH_COLUMN) as $ambId) {
                $ambId = (int)$ambId;

                // ¿Tiene fotos?
                $nf = db()->prepare("SELECT COUNT(*) FROM rec_foto
                                      WHERE nivel='ambiente' AND ref_id=:r");
                $nf->execute(['r' => $ambId]);
                if ((int)$nf->fetchColumn() > 0) continue;   // se conserva

                // ¿Tiene metros o avance registrado?
                $nm = db()->prepare("SELECT COUNT(*) FROM rec_reparacion
                                      WHERE nivel='ambiente' AND ref_id=:r
                                        AND metros_cuadrados > 0");
                $nm->execute(['r' => $ambId]);
                if ((int)$nm->fetchColumn() > 0) continue;   // se conserva

                // Vacío: se puede borrar sin perder nada.
                try { db()->prepare('DELETE FROM rec_avance_ambiente WHERE ambiente_id=:r')
                          ->execute(['r' => $ambId]); } catch (Throwable $e) {}
                try { db()->prepare("DELETE FROM rec_reparacion
                                      WHERE nivel='ambiente' AND ref_id=:r")
                          ->execute(['r' => $ambId]); } catch (Throwable $e) {}
                db()->prepare('DELETE FROM rec_ambiente WHERE id=:r')->execute(['r' => $ambId]);
            }
        }
    }
}

/** Lista los ambientes de un apartamento, agrupados por tipo. */
function recAmbientes(int $apartamentoId): array
{
    recAsegurarTablasTrabajo();
    // Se trae el tipo de trabajo guardado en sus reparaciones, para que
    // el selector aparezca marcado al reabrir el apartamento.
    $st = db()->prepare("
        SELECT am.*,
               (SELECT rr.tipo_trabajo FROM rec_reparacion rr
                 WHERE rr.nivel = 'ambiente' AND rr.ref_id = am.id
                   AND rr.tipo_trabajo IS NOT NULL LIMIT 1) AS tipo_trabajo
          FROM rec_ambiente am
         WHERE am.apartamento_id = :a
         ORDER BY am.tipo, am.numero
    ");
    $st->execute(['a' => $apartamentoId]);
    return $st->fetchAll();
}

/** Marca si un ambiente necesita reparación (y observación). */
function recGuardarAmbiente(int $ambienteId, array $d): void
{
    db()->prepare(
        'UPDATE rec_ambiente SET necesita_reparacion=:r, observaciones=:o, completado=1 WHERE id=:id'
    )->execute([
        'r'  => !empty($d['necesita_reparacion']) ? 1 : 0,
        'o'  => trim($d['observaciones'] ?? '') ?: null,
        'id' => $ambienteId,
    ]);
}

// =====================================================================
// MÉTRICAS DE REPARACIÓN Y CÁLCULO DE MATERIALES
// =====================================================================

/** Tipos de superficie que se pueden reparar. */
function recTiposSuperficie(): array
{
    return [
        'pared' => 'Pared', 'techo' => 'Techo', 'piso' => 'Piso', 'closet' => 'Clóset',
        'mamposteria' => 'Mampostería', 'derrumbar' => 'Derrumbar / demoler', 'reconstruccion' => 'Reconstrucción',
    ];
}

/** Guarda (reemplaza) las reparaciones de un ambiente o elemento. */
function recGuardarReparaciones(string $nivel, int $refId, array $reparaciones): void
{
    recAsegurarTablasTrabajo();
    $pdo = db();
    // Se reemplazan las existentes por las nuevas.
    $pdo->prepare('DELETE FROM rec_reparacion WHERE nivel = :n AND ref_id = :r')
        ->execute(['n' => $nivel, 'r' => $refId]);

    $tipos = array_keys(recTiposSuperficie());
    $ins = $pdo->prepare(
        'INSERT INTO rec_reparacion (nivel, ref_id, tipo_superficie, metros_cuadrados, observaciones, tipo_trabajo)
         VALUES (:n, :r, :t, :m, :o, :tr)'
    );
    // El tipo de trabajo llega junto a las reparaciones y se conserva
    // aunque se vuelvan a guardar los metros.
    $trabajo = trim($reparaciones['tipo_trabajo'] ?? '') ?: null;
    if ($trabajo === null && $refId > 0) {
        try {
            $q = db()->prepare('SELECT tipo_trabajo FROM rec_reparacion
                                 WHERE nivel = :n AND ref_id = :r AND tipo_trabajo IS NOT NULL LIMIT 1');
            $q->execute(['n' => $nivel, 'r' => $refId]);
            $trabajo = $q->fetchColumn() ?: null;
        } catch (Throwable $e) {}
    }

    foreach ($reparaciones as $k => $rep) {
        if (!is_array($rep)) continue;   // saltar 'tipo_trabajo'
        $tipo = $rep['tipo_superficie'] ?? '';
        $m2   = (float)($rep['metros_cuadrados'] ?? 0);
        if (!in_array($tipo, $tipos, true) || $m2 <= 0) continue;
        $ins->execute([
            'n' => $nivel, 'r' => $refId, 't' => $tipo, 'm' => $m2,
            'o' => trim($rep['observaciones'] ?? '') ?: null,
            'tr' => $trabajo,
        ]);
    }
}

/** Reparaciones de un ambiente o elemento. */
function recReparaciones(string $nivel, int $refId): array
{
    $st = db()->prepare('SELECT * FROM rec_reparacion WHERE nivel = :n AND ref_id = :r ORDER BY tipo_superficie');
    $st->execute(['n' => $nivel, 'r' => $refId]);
    return $st->fetchAll();
}

/** Recetas de materiales, indexadas por tipo de superficie. */
/**
 * Catálogo de tipos de trabajo, con sus recetas de materiales.
 * Cada trabajo tiene rendimientos propios: no consume lo mismo frisar
 * una pared que levantarla de nuevo.
 */
function recTiposTrabajo(): array
{
    recAsegurarTablasTrabajo();
    try {
        $tipos = db()->query(
            'SELECT * FROM rec_tipo_trabajo WHERE activo = 1 ORDER BY orden, nombre'
        )->fetchAll();
        foreach ($tipos as &$t) {
            $t['aplica'] = array_filter(array_map('trim', explode(',', $t['aplica_a'] ?? '')));
        }
        unset($t);
        return $tipos;
    } catch (Throwable $e) { return []; }
}

/** Recetas agrupadas por tipo de trabajo. */
function recRecetasTrabajo(): array
{
    recAsegurarTablasTrabajo();
    try {
        $out = [];
        foreach (db()->query('SELECT * FROM rec_receta_trabajo WHERE activo = 1')->fetchAll() as $r) {
            $out[$r['tipo_trabajo']][] = $r;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Crea las tablas de trabajos y recetas si faltan. */
function recAsegurarTablasTrabajo(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rec_tipo_trabajo (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            clave VARCHAR(40) NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            descripcion VARCHAR(300) DEFAULT NULL,
            unidad VARCHAR(10) NOT NULL DEFAULT 'm2',
            aplica_a VARCHAR(120) DEFAULT NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_tt_clave (clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS rec_receta_trabajo (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo_trabajo VARCHAR(40) NOT NULL,
            material VARCHAR(120) NOT NULL,
            unidad VARCHAR(20) NOT NULL,
            cantidad DECIMAL(12,4) NOT NULL,
            nota VARCHAR(200) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY idx_rt_trabajo (tipo_trabajo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = db()->query("SHOW COLUMNS FROM rec_reparacion")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tipo_trabajo', $cols, true)) {
            db()->exec("ALTER TABLE rec_reparacion ADD COLUMN tipo_trabajo VARCHAR(40) DEFAULT NULL");
        }
    } catch (Throwable $e) { /* seguir */ }
}

/**
 * Calcula materiales a partir de los trabajos registrados.
 * $trabajos = ['friso_completo' => 45.5, 'mamposteria_bloque_concreto' => 12]
 *
 * Devuelve cada material con su cantidad y unidad, redondeado hacia
 * arriba porque en obra no se compran fracciones de saco.
 */
function recMaterialesPorTrabajo(array $trabajos): array
{
    $recetas = recRecetasTrabajo();
    $totales = [];

    foreach ($trabajos as $clave => $cantidad) {
        $cantidad = (float)$cantidad;
        if ($cantidad <= 0 || empty($recetas[$clave])) continue;

        foreach ($recetas[$clave] as $ing) {
            $mat = $ing['material'];
            if (!isset($totales[$mat])) {
                $totales[$mat] = ['cantidad' => 0.0, 'unidad' => $ing['unidad']];
            }
            $totales[$mat]['cantidad'] += $cantidad * (float)$ing['cantidad'];
        }
    }

    // Los materiales que se compran por unidad entera se redondean hacia
    // arriba: no se puede pedir medio bloque ni 3,4 sacos.
    $enteros = ['unidad', 'saco', 'pieza', 'pliego'];
    foreach ($totales as $mat => $d) {
        $totales[$mat]['cantidad'] = in_array($d['unidad'], $enteros, true)
            ? (float)ceil($d['cantidad'])
            : round($d['cantidad'], 2);
    }

    ksort($totales);
    return $totales;
}

/**
 * Trabajos registrados en un edificio, sumados por tipo.
 * Se apoya en la columna tipo_trabajo de rec_reparacion.
 */
function recTrabajosDeEdificio(int $edificioId): array
{
    recAsegurarTablasTrabajo();
    try {
        // Cada trabajo se aplica solo a las superficies que le corresponden.
        // Levantar una pared de bloques consume metros de PARED, no de
        // techo ni de piso: sumarlos todos triplicaba los materiales.
        $st = db()->prepare("
            SELECT rr.tipo_trabajo, SUM(rr.metros_cuadrados) AS cantidad
              FROM rec_reparacion rr
              JOIN rec_tipo_trabajo tt ON tt.clave = rr.tipo_trabajo AND tt.activo = 1
             WHERE rr.tipo_trabajo IS NOT NULL AND rr.tipo_trabajo <> ''
               AND rr.metros_cuadrados > 0
               -- Se cuentan solo las superficies que aplican al trabajo.
               -- Si el trabajo no declara superficies, o el técnico usó
               -- una que no está en la lista (mampostería, derrumbar…),
               -- se cuenta igual: es mejor calcular de más que no calcular.
               AND (
                   tt.aplica_a IS NULL OR tt.aplica_a = ''
                   OR tt.unidad = 'm3'
                   OR rr.tipo_superficie IS NULL OR rr.tipo_superficie = ''
                   OR FIND_IN_SET(rr.tipo_superficie, REPLACE(tt.aplica_a, ' ', '')) > 0
                   OR rr.tipo_superficie NOT IN ('pared','techo','piso','closet')
               )
               AND (
                   (rr.nivel = 'ambiente' AND rr.ref_id IN (
                       SELECT am.id FROM rec_ambiente am
                         JOIN rec_apartamento ap ON ap.id = am.apartamento_id
                         JOIN rec_piso pi ON pi.id = ap.piso_id
                        WHERE pi.edificio_id = :e))
                OR (rr.nivel = 'elemento_piso' AND rr.ref_id IN (
                       SELECT ep.id FROM rec_elemento_piso ep
                         JOIN rec_piso pi2 ON pi2.id = ep.piso_id
                        WHERE pi2.edificio_id = :e2))
               )
             GROUP BY rr.tipo_trabajo
        ");
        $st->execute(['e' => $edificioId, 'e2' => $edificioId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['tipo_trabajo']] = (float)$r['cantidad'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function recRecetas(): array
{
    $rows = db()->query('SELECT * FROM rec_material_receta WHERE activo = 1')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['tipo_superficie']][] = $r;
    return $out;
}

/**
 * Calcula los materiales necesarios a partir de una lista de m² por superficie.
 * $m2PorSuperficie = ['pared' => 40.5, 'techo' => 12, ...]
 * Devuelve materiales sumados: ['Cemento (saco)' => 8.1, 'Bloques (unidad)' => 506, ...]
 */
function recCalcularMateriales(array $m2PorSuperficie): array
{
    $recetas = recRecetas();
    $totales = [];
    foreach ($m2PorSuperficie as $tipo => $m2) {
        if (empty($recetas[$tipo])) continue;
        foreach ($recetas[$tipo] as $ing) {
            $clave = $ing['material'] . ' (' . $ing['unidad'] . ')';
            $totales[$clave] = ($totales[$clave] ?? 0) + $m2 * (float)$ing['cantidad_por_m2'];
        }
    }
    // Redondear hacia arriba las unidades enteras (bloques, sacos…).
    foreach ($totales as $k => $v) {
        $totales[$k] = ceil($v * 100) / 100; // 2 decimales hacia arriba
    }
    ksort($totales);
    return $totales;
}

/**
 * Suma todos los m² por tipo de superficie de un edificio completo,
 * recorriendo sus ambientes (y elementos de piso) con reparaciones.
 */
function recM2PorSuperficieEdificio(int $edificioId): array
{
    // Ambientes con reparación del edificio (a través de piso -> apto -> ambiente).
    $sql = "
        SELECT rr.tipo_superficie, SUM(rr.metros_cuadrados) AS m2
        FROM rec_reparacion rr
        WHERE rr.nivel = 'ambiente' AND rr.ref_id IN (
            SELECT am.id FROM rec_ambiente am
            JOIN rec_apartamento ap ON ap.id = am.apartamento_id
            JOIN rec_piso pi ON pi.id = ap.piso_id
            WHERE pi.edificio_id = :e
        )
        GROUP BY rr.tipo_superficie
    ";
    $st = db()->prepare($sql);
    $st->execute(['e' => $edificioId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['tipo_superficie']] = (float)$row['m2'];
    }
    return $out;
}

/** Resumen de materiales de todo el edificio (para el formulario final). */
function recResumenMaterialesEdificio(int $edificioId): array
{
    $m2 = recM2PorSuperficieEdificio($edificioId);

    // Cálculo por TIPO DE TRABAJO (friso, mampostería, vaciado…), que es
    // el que refleja lo que realmente hay que hacer. Si no hay trabajos
    // indicados, se usa el cálculo antiguo por superficie.
    $materiales = [];
    $porTrabajo = [];
    try {
        $trabajos = recTrabajosDeEdificio($edificioId);
        if ($trabajos) {
            $det = recMaterialesPorTrabajo($trabajos);
            foreach ($det as $mat => $d) {
                $materiales[$mat . ' (' . $d['unidad'] . ')'] = $d['cantidad'];
            }
            $nombres = [];
            foreach (recTiposTrabajo() as $t) $nombres[$t['clave']] = $t['nombre'];
            foreach ($trabajos as $clave => $cant) {
                $porTrabajo[$nombres[$clave] ?? $clave] = round($cant, 2);
            }
        }
    } catch (Throwable $e) { /* se cae al cálculo por superficie */ }

    if (!$materiales) {
        try { $materiales = recCalcularMateriales($m2); }
        catch (Throwable $e) { $materiales = []; }
    }

    return [
        'm2_por_superficie' => $m2,
        'materiales'        => $materiales,
        'por_trabajo'       => $porTrabajo,
        'total_m2'          => array_sum($m2),
    ];
}

/** Guarda el plan de tiempo estimado del edificio (inicio/fin). */
function recGuardarPlan(int $edificioId, array $d): void
{
    // Asegurar que exista la tabla del plan (por si no se corrió el SQL).
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rec_plan_edificio (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            edificio_id INT UNSIGNED NOT NULL,
            fecha_inicio_estimada DATE DEFAULT NULL,
            fecha_fin_estimada DATE DEFAULT NULL,
            observaciones VARCHAR(500) DEFAULT NULL,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_plan_edificio (edificio_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* si ya existe o no se puede, seguir */ }

    $st = db()->prepare('SELECT id FROM rec_plan_edificio WHERE edificio_id = :e');
    $st->execute(['e' => $edificioId]);
    $existe = $st->fetchColumn();

    $ini = $d['fecha_inicio_estimada'] ?? null;
    $fin = $d['fecha_fin_estimada'] ?? null;
    $ini = ($ini && DateTime::createFromFormat('Y-m-d', $ini)) ? $ini : null;
    $fin = ($fin && DateTime::createFromFormat('Y-m-d', $fin)) ? $fin : null;
    $obs = trim($d['observaciones'] ?? '') ?: null;

    if ($existe) {
        db()->prepare(
            'UPDATE rec_plan_edificio SET fecha_inicio_estimada=:i, fecha_fin_estimada=:f, observaciones=:o WHERE edificio_id=:e'
        )->execute(['i' => $ini, 'f' => $fin, 'o' => $obs, 'e' => $edificioId]);
    } else {
        db()->prepare(
            'INSERT INTO rec_plan_edificio (edificio_id, fecha_inicio_estimada, fecha_fin_estimada, observaciones, creado_por)
             VALUES (:e, :i, :f, :o, :u)'
        )->execute(['e' => $edificioId, 'i' => $ini, 'f' => $fin, 'o' => $obs, 'u' => $_SESSION['user_id'] ?? null]);
    }
}

/**
 * Calcula el estado del plazo de una obra: cuántos días quedan,
 * si va retrasada y cómo mostrarlo.
 *
 * Devuelve null si no hay fecha de fin registrada.
 */
function recEstadoPlazo(?array $plan, int $avance = 0): ?array
{
    if (!$plan || empty($plan['fecha_fin_estimada'])) return null;

    try {
        $hoy = new DateTime('today');
        $fin = new DateTime($plan['fecha_fin_estimada']);
        $dias = (int)$hoy->diff($fin)->format('%r%a');   // negativo si ya pasó

        $ini = !empty($plan['fecha_inicio_estimada'])
            ? new DateTime($plan['fecha_inicio_estimada']) : null;

        // Días totales y transcurridos, para saber si el avance va a tiempo.
        $totales = $ini ? max(1, (int)$ini->diff($fin)->format('%a')) : null;
        $transcurridos = $ini ? max(0, (int)$ini->diff($hoy)->format('%r%a')) : null;
        $avanceEsperado = ($totales && $transcurridos !== null)
            ? min(100, (int)round($transcurridos / $totales * 100)) : null;

        // Estado según los días restantes y el avance real.
        if ($avance >= 100) {
            $estado = 'culminada';
            $texto  = 'Culminada';
            $color  = '#2E7D32';
            $icono  = 'bi-check-circle-fill';
        } elseif ($dias < 0) {
            $estado = 'vencida';
            $texto  = 'Vencida hace ' . abs($dias) . ' día' . (abs($dias) === 1 ? '' : 's');
            $color  = '#A61C1C';
            $icono  = 'bi-exclamation-triangle-fill';
        } elseif ($dias === 0) {
            $estado = 'hoy';
            $texto  = 'Vence hoy';
            $color  = '#A61C1C';
            $icono  = 'bi-exclamation-circle-fill';
        } elseif ($dias <= 7) {
            $estado = 'urgente';
            $texto  = 'Quedan ' . $dias . ' día' . ($dias === 1 ? '' : 's');
            $color  = '#C9A227';
            $icono  = 'bi-clock-fill';
        } else {
            $estado = 'a_tiempo';
            $texto  = 'Quedan ' . $dias . ' días';
            $color  = '#2d4488';
            $icono  = 'bi-calendar-check';
        }

        // ¿El avance va por detrás de lo esperado?
        $atrasada = ($avanceEsperado !== null && $avance < $avanceEsperado - 10 && $avance < 100);

        return [
            'dias'            => $dias,
            'estado'          => $estado,
            'texto'           => $texto,
            'color'           => $color,
            'icono'           => $icono,
            'fecha_inicio'    => $plan['fecha_inicio_estimada'] ?? null,
            'fecha_fin'       => $plan['fecha_fin_estimada'],
            'dias_totales'    => $totales,
            'avance_esperado' => $avanceEsperado,
            'atrasada'        => $atrasada,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Edificaciones EN RECONSTRUCCIÓN, para el buscador rápido.
 * Son las que tienen el levantamiento cerrado y avance menor a 100%.
 */
function segEnReconstruccion(array $filtros = []): array
{
    recAsegurarTablasAvance();

    $conds = ['re.completado = 1'];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');

    if (!empty($filtros['parroquia'])) {
        $conds[] = 'i.parroquia = :parr';
        $params['parr'] = $filtros['parroquia'];
    }
    if (!empty($filtros['texto'])) {
        $conds[] = '(i.nombre_edificio LIKE :txt OR i.codigo LIKE :txt)';
        $params['txt'] = '%' . $filtros['texto'] . '%';
    }
    $where = 'WHERE ' . implode(' AND ', $conds);

    try {
        $st = db()->prepare("
            SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final,
                   re.id AS edificio_id,
                   ent.nombre AS ente_nombre,
                   pl.fecha_inicio_estimada, pl.fecha_fin_estimada,
                   COALESCE(ROUND(x.pct), 0) AS avance
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
              LEFT JOIN entes ent ON ent.id = so.ente_id
              LEFT JOIN rec_plan_edificio pl ON pl.edificio_id = re.id
              LEFT JOIN (
                  SELECT re2.inspeccion_id, AVG(COALESCE(aa.porcentaje, 0)) AS pct
                    FROM rec_edificio re2
                    JOIN rec_piso pi ON pi.edificio_id = re2.id
                    JOIN rec_apartamento ap ON ap.piso_id = pi.id
                    LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap.id
                   GROUP BY re2.inspeccion_id
              ) x ON x.inspeccion_id = i.id
              $where
             HAVING avance < 100
             ORDER BY pl.fecha_fin_estimada IS NULL, pl.fecha_fin_estimada, i.nombre_edificio
        ");
        $st->execute($params);
        $lista = $st->fetchAll();

        foreach ($lista as &$e) {
            $e['plazo'] = recEstadoPlazo([
                'fecha_inicio_estimada' => $e['fecha_inicio_estimada'],
                'fecha_fin_estimada'    => $e['fecha_fin_estimada'],
            ], (int)$e['avance']);
        }
        unset($e);
        return $lista;

    } catch (Throwable $e) { return []; }
}

/** Plan de tiempo del edificio. */
function recPlan(int $edificioId): ?array
{
    $st = db()->prepare('SELECT * FROM rec_plan_edificio WHERE edificio_id = :e');
    $st->execute(['e' => $edificioId]);
    return $st->fetch() ?: null;
}

// =====================================================================
// ÁREAS COMUNES DEL EDIFICIO (lista de áreas típicas con estado)
// =====================================================================

/** Catálogo de áreas comunes típicas de un edificio (Venezuela). */
function recAreasComunesTipicas(): array
{
    return [
        'lobby'            => 'Lobby / Recepción',
        'ascensor'         => 'Ascensor(es)',
        'escaleras'        => 'Escaleras',
        'pasillos'         => 'Pasillos',
        'estacionamiento'  => 'Estacionamiento',
        'deposito_basura'  => 'Depósito de basura',
        'cuarto_maquinas'  => 'Cuarto de máquinas',
        'tanque_agua'      => 'Tanque de agua',
        'planta_electrica' => 'Planta eléctrica',
        'jardines'         => 'Jardines / Áreas verdes',
        'patio'            => 'Patio',
        'azotea'           => 'Azotea / Terraza',
        'salon_fiestas'    => 'Salón de fiestas',
        'piscina'          => 'Piscina',
        'gimnasio'         => 'Gimnasio',
        'parque_infantil'  => 'Parque infantil',
        'lavanderia'       => 'Lavandería',
        'porton_electrico' => 'Portón eléctrico / Acceso',
        'garita'           => 'Garita de vigilancia',
    ];
}

/** Áreas comunes registradas de un edificio, indexadas por tipo. */
function recAreasComunes(int $edificioId): array
{
    $st = db()->prepare('SELECT * FROM rec_area_comun WHERE edificio_id = :e');
    $st->execute(['e' => $edificioId]);
    $out = [];
    foreach ($st->fetchAll() as $a) $out[$a['tipo']] = $a;
    return $out;
}

/**
 * Asegura que rec_area_comun tenga las columnas tipo_trabajo y metros_cuadrados.
 * Si faltan (no se corrió el SQL), las crea. Silencioso y a prueba de errores.
 */
function recAsegurarColumnasAreaComun(): void
{
    static $verificado = false;
    if ($verificado) return;
    $verificado = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM rec_area_comun")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tipo_trabajo', $cols, true)) {
            db()->exec("ALTER TABLE rec_area_comun ADD COLUMN tipo_trabajo VARCHAR(30) DEFAULT NULL");
        }
        if (!in_array('metros_cuadrados', $cols, true)) {
            db()->exec("ALTER TABLE rec_area_comun ADD COLUMN metros_cuadrados DECIMAL(10,2) DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // Si no se puede alterar, se ignora: el INSERT lo reportará si aún falla.
    }
}

/** Guarda (reemplaza) las áreas comunes seleccionadas de un edificio. */
function recGuardarAreasComunes(int $edificioId, array $areas): void
{
    // Asegurar que existan las columnas nuevas (por si no se corrió el SQL).
    recAsegurarColumnasAreaComun();

    $tipos = array_keys(recAreasComunesTipicas());
    $trabajos = ['mamposteria','derrumbar','reconstruccion'];
    $st = db()->prepare(
        'INSERT INTO rec_area_comun (edificio_id, tipo, presente, necesita_reparacion, tipo_trabajo, metros_cuadrados)
         VALUES (:e, :t, 1, :nr, :tt, :m2)
         ON DUPLICATE KEY UPDATE presente=1,
            necesita_reparacion=VALUES(necesita_reparacion),
            tipo_trabajo=VALUES(tipo_trabajo), metros_cuadrados=VALUES(metros_cuadrados)'
    );
    foreach ($areas as $a) {
        $tipo = $a['tipo'] ?? '';
        if (!in_array($tipo, $tipos, true)) continue;
        $tt = in_array($a['tipo_trabajo'] ?? '', $trabajos, true) ? $a['tipo_trabajo'] : null;
        $st->execute([
            'e'  => $edificioId,
            't'  => $tipo,
            'nr' => !empty($a['necesita_reparacion']) ? 1 : 0,
            'tt' => $tt,
            'm2' => isset($a['metros_cuadrados']) ? (float)$a['metros_cuadrados'] : null,
        ]);
    }
    // Quitar las que se deseleccionaron.
    $marcados = array_column($areas, 'tipo');
    if ($marcados) {
        $in = implode(',', array_fill(0, count($marcados), '?'));
        $params = array_merge([$edificioId], $marcados);
        db()->prepare("DELETE FROM rec_area_comun WHERE edificio_id = ? AND tipo NOT IN ($in)")->execute($params);
    } else {
        db()->prepare('DELETE FROM rec_area_comun WHERE edificio_id = ?')->execute([$edificioId]);
    }
}

// =====================================================================
// PANEL DE PARROQUIA (dashboard de Caracas): encargado + resumen
// =====================================================================

/**
 * Reúne toda la información de una parroquia para el panel del dashboard:
 * encargado(s), conteo de edificaciones por color, cuántas comenzaron el
 * levantamiento, y el avance de cada edificación con levantamiento.
 */
function recPanelParroquia(string $estado, string $parroquia): array
{
    $pdo = db();

    // 1) Encargados (representantes) de la parroquia.
    $encargados = repDeParroquia($estado, $parroquia);

    // 2) Conteo de edificaciones por decisión/color.
    $st = $pdo->prepare(
        "SELECT decision_final, COUNT(*) AS n
           FROM inspecciones
          WHERE estado = :e AND parroquia = :p
          GROUP BY decision_final"
    );
    $st->execute(['e' => $estado, 'p' => $parroquia]);
    $cat = catalogoDecisionFinal();
    $porColor = ['rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'derrumbado' => 0, 'otro' => 0];
    $totalEdif = 0;
    foreach ($st->fetchAll() as $row) {
        $n = (int)$row['n'];
        $totalEdif += $n;
        $decision = $row['decision_final'] ?? '';
        $color = $cat[$decision]['color'] ?? '';
        if ($decision === 'Derrumbado') $porColor['derrumbado'] += $n;
        elseif ($color === '#A61C1C') $porColor['rojo'] += $n;
        elseif ($color === '#C9A227') $porColor['amarillo'] += $n;
        elseif ($color === '#2E7D32') $porColor['verde'] += $n;
        else $porColor['otro'] += $n;
    }

    // 3) Edificaciones con levantamiento (comenzadas) y su estado.
    //    "Comenzada" = tiene registro en rec_edificio.
    //    "Completada" = rec_edificio.completado = 1.
    $st = $pdo->prepare(
        "SELECT i.id AS inspeccion_id, i.codigo, i.nombre_edificio, i.decision_final,
                re.id AS edificio_id, re.completado,
                ent.nombre AS ente_nombre
           FROM inspecciones i
           JOIN rec_edificio re ON re.inspeccion_id = i.id
           LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
           LEFT JOIN entes ent ON ent.id = so.ente_id
          WHERE i.estado = :e AND i.parroquia = :p
          ORDER BY i.nombre_edificio"
    );
    $st->execute(['e' => $estado, 'p' => $parroquia]);
    $comenzadas = $st->fetchAll();

    // 4) Avance de cada edificación comenzada.
    //    Se calculan TODOS de una vez (1 consulta) en lugar de una por edificio.
    $avances = recAvancesDeParroquia($estado, $parroquia);
    $edificaciones = [];
    foreach ($comenzadas as $c) {
        $edificaciones[] = [
            'inspeccion_id'   => (int)$c['inspeccion_id'],
            'id'              => (int)$c['inspeccion_id'],
            'codigo'          => $c['codigo'] ?? '',
            'nombre'          => $c['nombre_edificio'],
            'decision'        => $c['decision_final'],
            'decision_final'  => $c['decision_final'],
            'ente'            => $c['ente_nombre'] ?? null,
            'color'           => $cat[$c['decision_final']]['color'] ?? '#767c94',
            'completado'      => (int)$c['completado'],
            'avance'          => $avances[(int)$c['inspeccion_id']] ?? 0,
        ];
    }

    return [
        'estado'         => $estado,
        'parroquia'      => $parroquia,
        'encargados'     => $encargados,
        // Frentes numerados que operan en la parroquia (reemplazan a los
        // equipos GDC por nombre: ahora es "Frente de Trabajo 1, 2, 3…").
        'frentes'        => frentesEnParroquia($parroquia),
        'total'          => $totalEdif,
        'por_color'      => $porColor,
        'comenzadas'     => count($comenzadas),
        'edificaciones'  => $edificaciones,
    ];
}

/**
 * Calcula el % de avance de reconstrucción de un edificio.
 * Por ahora se basa en cuántos ambientes que necesitan reparación ya tienen
 * su avance registrado. (El flujo Antes/Durante/Después afinará esto luego.)
 * Devuelve un entero 0..100.
 */
/**
 * Trae TODO el árbol del edificio (pisos → apartamentos) con sus porcentajes,
 * en una sola consulta, para cargar la ficha de seguimiento al instante.
 *
 * Jerarquía de porcentajes:
 *   - % apartamento = lo registrado en rec_avance_apto (0 si no tiene).
 *   - % piso        = promedio de los apartamentos de ese piso.
 *   - % edificio    = promedio de los pisos (que ya son promedio de sus aptos).
 */
/**
 * Metros cuadrados a reparar, sumados por apartamento, piso y total
 * del edificio. Los m² se registran en el levantamiento por cada
 * ambiente y elemento del piso.
 */
function recMetrosPorNivel(int $edificioId): array
{
    try {
        // m² de los ambientes, agrupados por apartamento.
        $st = db()->prepare("
            SELECT pi.id AS piso_id, pi.numero_piso,
                   ap.id AS apto_id, ap.identificador,
                   COALESCE(SUM(rr.metros_cuadrados), 0) AS m2
              FROM rec_piso pi
              JOIN rec_apartamento ap ON ap.piso_id = pi.id
              LEFT JOIN rec_ambiente am ON am.apartamento_id = ap.id
              LEFT JOIN rec_reparacion rr ON rr.nivel = 'ambiente' AND rr.ref_id = am.id
             WHERE pi.edificio_id = :e
             GROUP BY pi.id, ap.id
             ORDER BY pi.numero_piso, ap.identificador
        ");
        $st->execute(['e' => $edificioId]);

        $porApto = [];
        $porPiso = [];
        $total = 0.0;
        foreach ($st->fetchAll() as $r) {
            $m2 = (float)$r['m2'];
            $porApto[(int)$r['apto_id']] = $m2;
            $pid = (int)$r['piso_id'];
            if (!isset($porPiso[$pid])) {
                $porPiso[$pid] = ['numero_piso' => (int)$r['numero_piso'], 'm2' => 0.0];
            }
            $porPiso[$pid]['m2'] += $m2;
            $total += $m2;
        }

        // m² de los elementos del piso (escaleras, pasillos…).
        $st2 = db()->prepare("
            SELECT ep.piso_id, COALESCE(SUM(rr.metros_cuadrados), 0) AS m2
              FROM rec_elemento_piso ep
              JOIN rec_piso pi ON pi.id = ep.piso_id
              LEFT JOIN rec_reparacion rr ON rr.nivel = 'elemento_piso' AND rr.ref_id = ep.id
             WHERE pi.edificio_id = :e
             GROUP BY ep.piso_id
        ");
        $st2->execute(['e' => $edificioId]);
        $porPisoElem = [];
        foreach ($st2->fetchAll() as $r) {
            $m2 = (float)$r['m2'];
            $pid = (int)$r['piso_id'];
            $porPisoElem[$pid] = $m2;
            if (!isset($porPiso[$pid])) $porPiso[$pid] = ['numero_piso' => 0, 'm2' => 0.0];
            $porPiso[$pid]['m2'] += $m2;
            $total += $m2;
        }

        // Las áreas comunes no registran metros cuadrados en el
        // levantamiento (solo estado y si necesitan reparación), así que
        // no suman al total. Se deja en cero de forma explícita.
        $comunes = 0.0;

        // Desglose por tipo de superficie (Pared, Techo, Piso…), que es
        // lo que hace falta para calcular materiales.
        $porTipo = [];
        try {
            $st4 = db()->prepare("
                SELECT rr.tipo_superficie, COALESCE(SUM(rr.metros_cuadrados), 0) AS m2
                  FROM rec_reparacion rr
                 WHERE (rr.nivel = 'ambiente' AND rr.ref_id IN (
                          SELECT am.id FROM rec_ambiente am
                            JOIN rec_apartamento ap ON ap.id = am.apartamento_id
                            JOIN rec_piso pi ON pi.id = ap.piso_id
                           WHERE pi.edificio_id = :e))
                    OR (rr.nivel = 'elemento_piso' AND rr.ref_id IN (
                          SELECT ep.id FROM rec_elemento_piso ep
                            JOIN rec_piso pi2 ON pi2.id = ep.piso_id
                           WHERE pi2.edificio_id = :e2))
                 GROUP BY rr.tipo_superficie
                 ORDER BY m2 DESC
            ");
            $st4->execute(['e' => $edificioId, 'e2' => $edificioId]);
            foreach ($st4->fetchAll() as $r) {
                if (!empty($r['tipo_superficie'])) {
                    $porTipo[$r['tipo_superficie']] = round((float)$r['m2'], 2);
                }
            }
        } catch (Throwable $e) { /* sin desglose */ }

        return [
            'por_apartamento' => $porApto,
            'por_piso'        => $porPiso,
            'elementos_piso'  => $porPisoElem,
            'por_tipo'        => $porTipo,
            'areas_comunes'   => $comunes,
            'total'           => $total,
        ];

    } catch (Throwable $e) {
        return ['por_apartamento' => [], 'por_piso' => [], 'elementos_piso' => [],
                'por_tipo' => [], 'areas_comunes' => 0, 'total' => 0];
    }
}

/**
 * Cuenta los apartamentos que necesitan reparación en un edificio.
 * Basta con que UN ambiente esté marcado para que el apartamento cuente:
 * es la unidad con la que se planifica la obra.
 */
function recAptosConReparacion(int $edificioId): array
{
    try {
        $st = db()->prepare("
            SELECT
                COUNT(DISTINCT ap.id) AS total,
                COUNT(DISTINCT CASE WHEN am.necesita_reparacion = 1
                                    THEN ap.id END) AS con_reparacion,
                COUNT(DISTINCT CASE WHEN am.necesita_reparacion = 1
                                    THEN am.id END) AS ambientes_a_reparar
              FROM rec_piso pi
              JOIN rec_apartamento ap ON ap.piso_id = pi.id
              LEFT JOIN rec_ambiente am ON am.apartamento_id = ap.id
             WHERE pi.edificio_id = :e
        ");
        $st->execute(['e' => $edificioId]);
        $r = $st->fetch() ?: [];

        $total = (int)($r['total'] ?? 0);
        $con   = (int)($r['con_reparacion'] ?? 0);

        return [
            'total'               => $total,
            'con_reparacion'      => $con,
            'sin_reparacion'      => max(0, $total - $con),
            'ambientes_a_reparar' => (int)($r['ambientes_a_reparar'] ?? 0),
            'porcentaje'          => $total > 0 ? (int)round($con / $total * 100) : 0,
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'con_reparacion' => 0, 'sin_reparacion' => 0,
                'ambientes_a_reparar' => 0, 'porcentaje' => 0];
    }
}

/**
 * Total de apartamentos a reparar en todo el sistema, para el dashboard.
 * Respeta el alcance del usuario (estado y parroquias asignadas).
 */
function segAptosAReparar(): array
{
    $conds = [];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $where = $conds ? (' AND ' . implode(' AND ', $conds)) : '';

    try {
        $st = db()->prepare("
            SELECT
                COUNT(DISTINCT ap.id) AS aptos_total,
                COUNT(DISTINCT CASE WHEN am.necesita_reparacion = 1
                                    THEN ap.id END) AS aptos_reparar,
                COUNT(DISTINCT CASE WHEN am.necesita_reparacion = 1
                                    THEN am.id END) AS ambientes_reparar,
                COUNT(DISTINCT CASE WHEN am.necesita_reparacion = 1
                                    THEN i.id END) AS edificios_con_reparacion
              FROM inspecciones i
              JOIN rec_edificio re ON re.inspeccion_id = i.id
              JOIN rec_piso pi ON pi.edificio_id = re.id
              JOIN rec_apartamento ap ON ap.piso_id = pi.id
              LEFT JOIN rec_ambiente am ON am.apartamento_id = ap.id
             WHERE 1=1 $where
        ");
        $st->execute($params);
        $r = $st->fetch() ?: [];

        return [
            'aptos_total'    => (int)($r['aptos_total'] ?? 0),
            'aptos_reparar'  => (int)($r['aptos_reparar'] ?? 0),
            'ambientes'      => (int)($r['ambientes_reparar'] ?? 0),
            'edificios'      => (int)($r['edificios_con_reparacion'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['aptos_total' => 0, 'aptos_reparar' => 0, 'ambientes' => 0, 'edificios' => 0];
    }
}

function recArbolAvance(int $edificioId): array
{
    recAsegurarTablasAvance();
    $pdo = db();

    // Una sola consulta con TODO el arbol: piso -> apartamento -> ambiente.
    $st = $pdo->prepare(
        "SELECT pi.id AS piso_id, pi.numero_piso,
                ap.id AS apto_id, ap.identificador,
                ap.jefe_nombre, ap.jefe_cedula, ap.jefe_telefono,
                am.id AS amb_id, am.tipo AS amb_tipo, am.numero AS amb_numero,
                am.necesita_reparacion,
                COALESCE(ava.porcentaje, 0) AS amb_pct,
                (SELECT COUNT(*) FROM rec_foto f
                  WHERE f.nivel='ambiente' AND f.ref_id=am.id
                    AND (f.parte IS NULL OR f.parte = '' OR f.parte <> 'durante')) AS amb_fotos_antes,
                (SELECT COUNT(*) FROM rec_foto f
                  WHERE f.nivel='ambiente' AND f.ref_id=am.id AND f.parte='durante') AS amb_fotos_durante,
                (SELECT COUNT(*) FROM rec_foto f
                  WHERE f.nivel='apartamento' AND f.ref_id=ap.id AND f.parte='durante') AS apto_fotos_durante
           FROM rec_piso pi
           LEFT JOIN rec_apartamento ap ON ap.piso_id = pi.id
           LEFT JOIN rec_ambiente am ON am.apartamento_id = ap.id
           LEFT JOIN rec_avance_ambiente ava ON ava.ambiente_id = am.id
          WHERE pi.edificio_id = :e
          ORDER BY pi.numero_piso, ap.id, am.tipo, am.numero"
    );
    $st->execute(['e' => $edificioId]);

    $pisos = [];
    $vistosApto = [];
    foreach ($st->fetchAll() as $r) {
        $pid = (int)$r['piso_id'];
        if (!isset($pisos[$pid])) {
            $pisos[$pid] = [
                'piso_id'      => $pid,
                'numero_piso'  => (int)$r['numero_piso'],
                'etiqueta'     => (int)$r['numero_piso'] === 0 ? 'Planta Baja' : 'Piso ' . (int)$r['numero_piso'],
                'apartamentos' => [],
                'avance'       => 0,
            ];
        }
        if ($r['apto_id'] === null) continue;
        $aid = (int)$r['apto_id'];
        if (!isset($vistosApto[$aid])) {
            $vistosApto[$aid] = count($pisos[$pid]['apartamentos']);
            $pisos[$pid]['apartamentos'][] = [
                'id'            => $aid,
                'identificador' => $r['identificador'],
                'jefe_nombre'   => $r['jefe_nombre'],
                'jefe_cedula'   => $r['jefe_cedula'],
                'jefe_telefono' => $r['jefe_telefono'],
                'ambientes'     => [],
                'avance'        => 0,
                'tiene_foto_durante' => ((int)$r['apto_fotos_durante']) > 0,
            ];
        }
        $idx = $vistosApto[$aid];
        if ($r['amb_id'] !== null) {
            $pisos[$pid]['apartamentos'][$idx]['ambientes'][] = [
                'id'            => (int)$r['amb_id'],
                'tipo'          => $r['amb_tipo'],
                'numero'        => (int)$r['amb_numero'],
                'etiqueta'      => $r['amb_tipo'] . ' ' . (int)$r['amb_numero'],
                'necesita_reparacion' => (int)$r['necesita_reparacion'] === 1,
                'avance'        => (int)$r['amb_pct'],
                'fotos_antes'   => (int)$r['amb_fotos_antes'],
                'fotos_durante' => (int)$r['amb_fotos_durante'],
                'tiene_foto_durante' => ((int)$r['amb_fotos_durante']) > 0,
            ];
        }
    }

    // Promedios en cascada: ambiente -> apartamento -> piso -> edificio.
    $sumaPisos = 0; $nPisos = 0;
    foreach ($pisos as $pid => $p) {
        $sumaAptos = 0; $nAptos = 0;
        foreach ($p['apartamentos'] as $i => $ap) {
            $ambs = $ap['ambientes'];
            if ($ambs) {
                $suma = array_sum(array_column($ambs, 'avance'));
                $pisos[$pid]['apartamentos'][$i]['avance'] = (int)round($suma / count($ambs));
            } else {
                // Sin ambientes registrados: se usa el avance directo del apartamento.
                $pisos[$pid]['apartamentos'][$i]['avance'] = recAvanceApartamento((int)$ap['id']);
            }
            $sumaAptos += $pisos[$pid]['apartamentos'][$i]['avance'];
            $nAptos++;
        }
        $pisos[$pid]['avance'] = $nAptos > 0 ? (int)round($sumaAptos / $nAptos) : 0;
        $sumaPisos += $pisos[$pid]['avance'];
        $nPisos++;
    }
    $avanceEdificio = $nPisos > 0 ? (int)round($sumaPisos / $nPisos) : 0;

    // Fotos de los elementos del piso (escaleras, pasillos, fachada…),
    // que el levantamiento guarda aparte de las de cada ambiente.
    $fotosPiso = [];
    try {
        $stFP = db()->prepare("
            SELECT ep.piso_id, f.id, f.ruta, f.parte, f.descripcion, f.creado_en,
                   ep.tipo AS elemento
              FROM rec_elemento_piso ep
              JOIN rec_piso pi ON pi.id = ep.piso_id
              JOIN rec_foto f ON f.nivel = 'elemento_piso' AND f.ref_id = ep.id
             WHERE pi.edificio_id = :e
             ORDER BY pi.numero_piso, f.creado_en
        ");
        $stFP->execute(['e' => $edificioId]);
        foreach ($stFP->fetchAll() as $r) {
            $fotosPiso[(int)$r['piso_id']][] = [
                'id'    => (int)$r['id'],
                'ruta'  => APP_URL_BASE . ltrim($r['ruta'], '/'),
                'parte' => $r['parte'] ?: 'antes',
                'elemento' => $r['elemento'] ?? '',
                'descripcion' => $r['descripcion'] ?? '',
                'fecha' => !empty($r['creado_en']) ? date('d/m/Y H:i', strtotime($r['creado_en'])) : '',
            ];
        }
    } catch (Throwable $e) { /* sin fotos de elementos */ }

    // Fotos del edificio (fachada, etiqueta, azotea, tanques…).
    $fotosEdificio = [];
    try {
        $stFE = db()->prepare("
            SELECT id, ruta, parte, descripcion, creado_en
              FROM rec_foto
             WHERE nivel = 'edificio' AND ref_id = :e
             ORDER BY creado_en
        ");
        $stFE->execute(['e' => $edificioId]);
        foreach ($stFE->fetchAll() as $r) {
            $fotosEdificio[] = [
                'id'    => (int)$r['id'],
                'ruta'  => APP_URL_BASE . ltrim($r['ruta'], '/'),
                'parte' => $r['parte'] ?: 'antes',
                'descripcion' => $r['descripcion'] ?? '',
                'fecha' => !empty($r['creado_en']) ? date('d/m/Y H:i', strtotime($r['creado_en'])) : '',
            ];
        }
    } catch (Throwable $e) { /* sin fotos del edificio */ }

    // Metros cuadrados a reparar, por apartamento y por piso.
    // Si algo falla aquí, la ficha debe cargar igual: el avance y los
    // pisos son lo esencial, los metros son un extra.
    try {
        $m2 = recMetrosPorNivel($edificioId);
    } catch (Throwable $e) {
        $m2 = ['por_apartamento' => [], 'por_piso' => [], 'elementos_piso' => [],
               'por_tipo' => [], 'areas_comunes' => 0, 'total' => 0];
    }
    foreach ($pisos as $pid => $p) {
        $pisos[$pid]['m2'] = round($m2['por_piso'][$pid]['m2'] ?? 0, 2);
        $pisos[$pid]['fotos_elementos'] = $fotosPiso[$pid] ?? [];
        foreach ($p['apartamentos'] as $i => $ap) {
            $pisos[$pid]['apartamentos'][$i]['m2'] =
                round($m2['por_apartamento'][(int)$ap['id']] ?? 0, 2);
        }
    }

    // Materiales según el TIPO DE TRABAJO registrado (friso, mampostería,
    // vaciado…). Si no hay trabajos indicados, se cae al cálculo antiguo
    // por superficie para no dejar la ficha vacía.
    $materiales = [];
    $porTrabajo = [];
    try { $trabajos = recTrabajosDeEdificio($edificioId); }
    catch (Throwable $e) { $trabajos = []; }

    if ($trabajos) {
        try {
            $matDet = recMaterialesPorTrabajo($trabajos);
            foreach ($matDet as $mat => $d) {
                $materiales[$mat . ' (' . $d['unidad'] . ')'] = $d['cantidad'];
            }
            // Nombres legibles de cada trabajo, para mostrarlos.
            $nombres = [];
            foreach (recTiposTrabajo() as $t) $nombres[$t['clave']] = $t['nombre'];
            foreach ($trabajos as $clave => $cant) {
                $porTrabajo[$nombres[$clave] ?? $clave] = round($cant, 2);
            }
        } catch (Throwable $e) { $materiales = []; }
    } elseif (!empty($m2['por_tipo'])) {
        try { $materiales = recCalcularMateriales($m2['por_tipo']); }
        catch (Throwable $e) { $materiales = []; }
    }

    // Fotos que no cuelgan de un ambiente: elementos del piso y del
    // edificio (etiqueta, azotea, tanques). Antes no se mostraban.
    try {
    foreach ($pisos as $pid => $p) {
        $pisos[$pid]['fotos_elementos'] = [];
        try {
            $stFE = db()->prepare("
                SELECT f.id, f.ruta, f.parte, f.descripcion, f.creado_en,
                       ep.tipo AS elemento
                  FROM rec_elemento_piso ep
                  JOIN rec_foto f ON f.nivel = 'elemento_piso' AND f.ref_id = ep.id
                 WHERE ep.piso_id = :p
                 ORDER BY ep.tipo, f.id
            ");
            $stFE->execute(['p' => $pid]);
            foreach ($stFE->fetchAll() as $f) {
                $pisos[$pid]['fotos_elementos'][] = [
                    'ruta'      => APP_URL_BASE . ltrim($f['ruta'], '/'),
                    'elemento'  => $f['elemento'],
                    'parte'     => $f['parte'] ?: '',
                    'fecha'     => !empty($f['creado_en'])
                                   ? date('d/m/Y H:i', strtotime($f['creado_en'])) : '',
                ];
            }
        } catch (Throwable $e) { /* sin fotos de elementos */ }
    }

    } catch (Throwable $e) { /* sin fotos de elementos */ }

    // Fotos generales del edificio.
    $fotosEdificio = [];
    try {
        $stFG = db()->prepare("SELECT id, ruta, parte, descripcion, creado_en
                                 FROM rec_foto
                                WHERE nivel = 'edificio' AND ref_id = :e
                                ORDER BY id");
        $stFG->execute(['e' => $edificioId]);
        foreach ($stFG->fetchAll() as $f) {
            $fotosEdificio[] = [
                'ruta'  => APP_URL_BASE . ltrim($f['ruta'], '/'),
                'parte' => $f['parte'] ?: 'general',
                'fecha' => !empty($f['creado_en'])
                           ? date('d/m/Y H:i', strtotime($f['creado_en'])) : '',
            ];
        }
    } catch (Throwable $e) { /* sin fotos del edificio */ }

    return [
        'pisos'           => array_values($pisos),
        'fotos_edificio'  => $fotosEdificio,
        'avance_edificio' => $avanceEdificio,
        'total_pisos'     => $nPisos,
        'total_aptos'     => array_sum(array_map(fn($p) => count($p['apartamentos']), $pisos)),
        'm2_total'        => round($m2['total'], 2),
        'm2_comunes'      => round($m2['areas_comunes'], 2),
        'm2_por_tipo'     => $m2['por_tipo'] ?? [],
        'materiales'      => $materiales,
        'por_trabajo'     => $porTrabajo,
        'aptos_reparar'   => recAptosConReparacion($edificioId),
        'fotos_edificio'  => $fotosEdificio,
        'fotos_piso'      => $fotosPiso,
    ];
}

/** Guarda el % de un ambiente y recalcula el del apartamento. */
function recGuardarAvanceAmbiente(int $ambienteId, int $porcentaje, ?string $obs = null): array
{
    recAsegurarTablasAvance();
    $porcentaje = max(0, min(100, $porcentaje));
    db()->prepare(
        'INSERT INTO rec_avance_ambiente (ambiente_id, porcentaje, observaciones, actualizado_por)
         VALUES (:a, :p, :o, :u)
         ON DUPLICATE KEY UPDATE porcentaje=VALUES(porcentaje),
             observaciones=VALUES(observaciones), actualizado_por=VALUES(actualizado_por)'
    )->execute(['a' => $ambienteId, 'p' => $porcentaje, 'o' => $obs, 'u' => $_SESSION['user_id'] ?? null]);

    // Recalcular el % del apartamento como promedio de sus ambientes.
    $st = db()->prepare(
        'SELECT am.apartamento_id, AVG(COALESCE(av.porcentaje,0)) AS pct
           FROM rec_ambiente am
           LEFT JOIN rec_avance_ambiente av ON av.ambiente_id = am.id
          WHERE am.apartamento_id = (SELECT apartamento_id FROM rec_ambiente WHERE id = :a)
          GROUP BY am.apartamento_id'
    );
    $st->execute(['a' => $ambienteId]);
    $row = $st->fetch();
    $aptoId = (int)($row['apartamento_id'] ?? 0);
    $aptoPct = (int)round((float)($row['pct'] ?? 0));
    if ($aptoId > 0) {
        db()->prepare(
            'INSERT INTO rec_avance_apto (apartamento_id, porcentaje, actualizado_por)
             VALUES (:a, :p, :u)
             ON DUPLICATE KEY UPDATE porcentaje=VALUES(porcentaje), actualizado_por=VALUES(actualizado_por)'
        )->execute(['a' => $aptoId, 'p' => $aptoPct, 'u' => $_SESSION['user_id'] ?? null]);
    }

    // Auditoria
    try {
        $q = db()->prepare(
            'SELECT am.tipo, am.numero, ap.identificador, pi.edificio_id, re.inspeccion_id
               FROM rec_ambiente am
               JOIN rec_apartamento ap ON ap.id = am.apartamento_id
               JOIN rec_piso pi ON pi.id = ap.piso_id
               JOIN rec_edificio re ON re.id = pi.edificio_id
              WHERE am.id = :a'
        );
        $q->execute(['a' => $ambienteId]);
        if ($d = $q->fetch()) {
            recAuditar('avance_ambiente', (int)$d['inspeccion_id'], (int)$d['edificio_id'],
                'Apto ' . $d['identificador'] . ' · ' . $d['tipo'] . ' ' . $d['numero'] . ' → ' . $porcentaje . '%');
        }
    } catch (Throwable $e) { /* no interrumpir */ }

    return ['apartamento_id' => $aptoId, 'apartamento_pct' => $aptoPct];
}

/** Asegura que exista la tabla de avance por apartamento. */
// =====================================================================
// AUDITORÍA: quién hizo qué y cuándo.
// =====================================================================

/** Crea la tabla de auditoría y las columnas de trazabilidad si faltan. */
function recAsegurarAuditoria(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rec_auditoria (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspeccion_id INT UNSIGNED DEFAULT NULL,
            edificio_id INT UNSIGNED DEFAULT NULL,
            accion VARCHAR(60) NOT NULL,
            detalle VARCHAR(400) DEFAULT NULL,
            usuario_id INT UNSIGNED DEFAULT NULL,
            usuario_nombre VARCHAR(150) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_aud_inspeccion (inspeccion_id),
            KEY idx_aud_edificio (edificio_id),
            KEY idx_aud_fecha (creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = db()->query("SHOW COLUMNS FROM rec_edificio")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('completado_por', $cols, true)) db()->exec("ALTER TABLE rec_edificio ADD COLUMN completado_por INT UNSIGNED DEFAULT NULL");
        if (!in_array('completado_en', $cols, true))  db()->exec("ALTER TABLE rec_edificio ADD COLUMN completado_en DATETIME DEFAULT NULL");
        $ca = db()->query("SHOW COLUMNS FROM rec_apartamento")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('registrado_por', $ca, true)) db()->exec("ALTER TABLE rec_apartamento ADD COLUMN registrado_por INT UNSIGNED DEFAULT NULL");
        if (!in_array('registrado_en', $ca, true))  db()->exec("ALTER TABLE rec_apartamento ADD COLUMN registrado_en DATETIME DEFAULT NULL");
    } catch (Throwable $e) { /* seguir */ }
}

/** Registra una acción en la bitácora. Nunca interrumpe el flujo si falla. */
function recAuditar(string $accion, ?int $inspeccionId = null, ?int $edificioId = null, ?string $detalle = null): void
{
    try {
        recAsegurarAuditoria();
        $nombre = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? null);
        if (!$nombre && !empty($_SESSION['user_id'])) {
            $st = db()->prepare('SELECT nombre FROM usuarios WHERE id = :id');
            $st->execute(['id' => (int)$_SESSION['user_id']]);
            $nombre = $st->fetchColumn() ?: null;
        }
        db()->prepare(
            'INSERT INTO rec_auditoria (inspeccion_id, edificio_id, accion, detalle, usuario_id, usuario_nombre, ip)
             VALUES (:i, :e, :a, :d, :u, :un, :ip)'
        )->execute([
            'i'  => $inspeccionId,
            'e'  => $edificioId,
            'a'  => mb_substr($accion, 0, 60),
            'd'  => $detalle !== null ? mb_substr($detalle, 0, 400) : null,
            'u'  => $_SESSION['user_id'] ?? null,
            'un' => $nombre,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* la auditoría nunca debe romper la operación */ }
}

/** Historial de una inspección (para mostrarlo en la ficha). */
function recHistorial(int $inspeccionId, int $limite = 100): array
{
    recAsegurarAuditoria();
    try {
        $st = db()->prepare(
            'SELECT accion, detalle, usuario_nombre, ip, creado_en
               FROM rec_auditoria
              WHERE inspeccion_id = :i
              ORDER BY creado_en DESC, id DESC
              LIMIT ' . max(1, min(500, $limite))
        );
        $st->execute(['i' => $inspeccionId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Quién hizo el levantamiento técnico de un edificio (para la ficha). */
function recResponsableLevantamiento(int $edificioId): array
{
    recAsegurarAuditoria();
    try {
        $st = db()->prepare(
            "SELECT u1.nombre AS creado_por_nombre, re.creado_en,
                    u2.nombre AS completado_por_nombre, re.completado_en, re.completado
               FROM rec_edificio re
               LEFT JOIN usuarios u1 ON u1.id = re.creado_por
               LEFT JOIN usuarios u2 ON u2.id = re.completado_por
              WHERE re.id = :e"
        );
        $st->execute(['e' => $edificioId]);
        return $st->fetch() ?: [];
    } catch (Throwable $e) { return []; }
}

function recAsegurarTablasAvance(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rec_avance_apto (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            apartamento_id INT UNSIGNED NOT NULL,
            porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0,
            observaciones VARCHAR(400) DEFAULT NULL,
            actualizado_por INT UNSIGNED DEFAULT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_avance_apto (apartamento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS rec_avance_ambiente (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ambiente_id INT UNSIGNED NOT NULL,
            porcentaje TINYINT UNSIGNED NOT NULL DEFAULT 0,
            observaciones VARCHAR(400) DEFAULT NULL,
            actualizado_por INT UNSIGNED DEFAULT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id), UNIQUE KEY uq_avance_ambiente (ambiente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir */ }
}

/**
 * Avance de TODAS las edificaciones de una parroquia en UNA sola consulta.
 * Evita llamar recAvanceEdificio() cientos de veces (que traeria el arbol
 * completo de cada edificio). Devuelve [inspeccion_id => porcentaje].
 *
 * Respeta la misma jerarquia: ambiente -> apartamento -> piso -> edificio.
 */
/**
 * Prioridad de color para ordenar listados: amarillo, rojo, verde, derrumbado.
 * Menor numero = va primero.
 */
/**
 * Divide el nombre de un equipo en sus integrantes.
 * "LARRY-FIGUERA" -> ['LARRY', 'FIGUERA']
 * "CARLOS VIVAS-LAURA" -> ['CARLOS VIVAS', 'LAURA']
 * "RICARDO GARCES / JOSE GOMEZ" -> ['RICARDO GARCES', 'JOSE GOMEZ']
 */
function frenteIntegrantes(string $nombre): array
{
    // Primero por " / " (sistematizadores), luego por guion (equipos GDC).
    $partes = preg_split('/\s*\/\s*/u', $nombre);
    if (count($partes) < 2) {
        // Guion rodeado o no de espacios, pero sin partir nombres tipo "JUAN C"
        $partes = preg_split('/\s*-\s*/u', $nombre);
    }
    $partes = array_values(array_filter(array_map('trim', $partes), fn($p) => $p !== ''));
    return $partes ?: [trim($nombre)];
}

/** Asegura la tabla de sub-asignaciones. */
function asigAsegurarTabla(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS asignacion_frente (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inspeccion_id INT UNSIGNED NOT NULL,
            frente_id INT UNSIGNED DEFAULT NULL,
            frente_tipo VARCHAR(60) DEFAULT NULL,
            miembro VARCHAR(120) NOT NULL,
            asignado_por INT UNSIGNED DEFAULT NULL,
            asignado_en DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_asig_insp_tipo (inspeccion_id, frente_tipo),
            KEY idx_asig_miembro (miembro)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* seguir */ }
}

/** Asigna un edificio a un integrante concreto de un frente. */
function asigGuardar(int $inspeccionId, int $frenteId, string $tipo, string $miembro): void
{
    asigAsegurarTabla();
    db()->prepare(
        'INSERT INTO asignacion_frente (inspeccion_id, frente_id, frente_tipo, miembro, asignado_por)
         VALUES (:i, :f, :t, :m, :u)
         ON DUPLICATE KEY UPDATE frente_id=VALUES(frente_id), miembro=VALUES(miembro),
             asignado_por=VALUES(asignado_por), asignado_en=NOW()'
    )->execute([
        'i' => $inspeccionId, 'f' => $frenteId ?: null, 't' => $tipo,
        'm' => $miembro, 'u' => $_SESSION['user_id'] ?? null,
    ]);
    recAuditar('frente_asignado', $inspeccionId, null, $tipo . ' → ' . $miembro);
}

/** Sub-asignaciones de una inspección: [tipo => ['miembro'=>..., 'frente_id'=>...]] */
function asigDeInspeccion(int $inspeccionId): array
{
    asigAsegurarTabla();
    try {
        $st = db()->prepare('SELECT * FROM asignacion_frente WHERE inspeccion_id = :i');
        $st->execute(['i' => $inspeccionId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['frente_tipo']] = ['miembro' => $r['miembro'], 'frente_id' => (int)$r['frente_id']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Todas las sub-asignaciones de una parroquia: [inspeccion_id => [tipo => miembro]] */
function asigDeParroquia(string $estado, string $parroquia): array
{
    asigAsegurarTabla();
    try {
        $st = db()->prepare(
            'SELECT af.inspeccion_id, af.frente_tipo, af.miembro
               FROM asignacion_frente af
               JOIN inspecciones i ON i.id = af.inspeccion_id
              WHERE i.estado = :e AND i.parroquia = :p'
        );
        $st->execute(['e' => $estado, 'p' => $parroquia]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['inspeccion_id']][$r['frente_tipo']] = $r['miembro'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Progreso de cada integrante en una parroquia.
 * Devuelve, por persona: cuantas edificaciones tiene, cuantas culminadas,
 * cuantas en proceso, cuantas sin comenzar y su avance promedio.
 */
function asigProgresoPorMiembro(string $estado, string $parroquia, ?string $tipo = 'gdc'): array
{
    asigAsegurarTabla();
    $avances = recAvancesDeParroquia($estado, $parroquia);
    try {
        $sql = 'SELECT af.miembro, af.inspeccion_id
                  FROM asignacion_frente af
                  JOIN inspecciones i ON i.id = af.inspeccion_id
                 WHERE i.estado = :e AND i.parroquia = :p';
        $params = ['e' => $estado, 'p' => $parroquia];
        if ($tipo !== null && $tipo !== '') {
            $sql .= ' AND af.frente_tipo = :t';
            $params['t'] = $tipo;
        }
        $st = db()->prepare($sql);
        $st->execute($params);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $m = $r['miembro'];
            $pct = (int)($avances[(int)$r['inspeccion_id']] ?? 0);
            if (!isset($out[$m])) {
                $out[$m] = ['total' => 0, 'culminadas' => 0, 'en_proceso' => 0,
                            'sin_comenzar' => 0, 'suma' => 0, 'avance' => 0];
            }
            $out[$m]['total']++;
            $out[$m]['suma'] += $pct;
            if ($pct >= 100)     $out[$m]['culminadas']++;
            elseif ($pct > 0)    $out[$m]['en_proceso']++;
            else                 $out[$m]['sin_comenzar']++;
        }
        foreach ($out as $m => $d) {
            $out[$m]['avance'] = $d['total'] > 0 ? (int)round($d['suma'] / $d['total']) : 0;
        }
        // Mayor avance primero.
        uasort($out, fn($a, $b) => $b['avance'] <=> $a['avance']);
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Carga de trabajo por integrante en una parroquia: [miembro => n edificios] */
function asigCargaPorMiembro(string $estado, string $parroquia, ?string $tipo = 'gdc'): array
{
    asigAsegurarTabla();
    try {
        $sql = 'SELECT af.miembro, COUNT(*) AS n
                  FROM asignacion_frente af
                  JOIN inspecciones i ON i.id = af.inspeccion_id
                 WHERE i.estado = :e AND i.parroquia = :p';
        $params = ['e' => $estado, 'p' => $parroquia];
        if ($tipo !== null) { $sql .= ' AND af.frente_tipo = :t'; $params['t'] = $tipo; }
        $sql .= ' GROUP BY af.miembro ORDER BY n DESC';
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['miembro']] = (int)$r['n'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Resumen de apartamentos de una parroquia (para el panel del responsable).
 * Devuelve, por inspeccion: total de apartamentos, cuantos culminados y
 * cuantos en proceso. Todo en UNA consulta.
 */
function recResumenAptosParroquia(string $estado, string $parroquia): array
{
    recAsegurarTablasAvance();
    try {
        $st = db()->prepare("
            SELECT re.inspeccion_id,
                   COUNT(DISTINCT ap.id) AS total_aptos,
                   SUM(CASE WHEN a.pct >= 100 THEN 1 ELSE 0 END) AS culminados,
                   SUM(CASE WHEN a.pct > 0 AND a.pct < 100 THEN 1 ELSE 0 END) AS en_proceso,
                   SUM(CASE WHEN a.pct = 0 THEN 1 ELSE 0 END) AS sin_iniciar
              FROM rec_edificio re
              JOIN rec_piso pi ON pi.edificio_id = re.id
              JOIN rec_apartamento ap ON ap.piso_id = pi.id
              JOIN inspecciones i ON i.id = re.inspeccion_id
              LEFT JOIN (
                  SELECT ap2.id AS apto_id,
                         COALESCE(
                             AVG(CASE WHEN am.id IS NOT NULL THEN COALESCE(ava.porcentaje,0) END),
                             COALESCE(MAX(aa.porcentaje), 0)
                         ) AS pct
                    FROM rec_apartamento ap2
                    LEFT JOIN rec_ambiente am ON am.apartamento_id = ap2.id
                    LEFT JOIN rec_avance_ambiente ava ON ava.ambiente_id = am.id
                    LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap2.id
                   GROUP BY ap2.id
              ) a ON a.apto_id = ap.id
             WHERE i.estado = :e AND i.parroquia = :p
             GROUP BY re.inspeccion_id
        ");
        $st->execute(['e' => $estado, 'p' => $parroquia]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['inspeccion_id']] = [
                'total'       => (int)$r['total_aptos'],
                'culminados'  => (int)$r['culminados'],
                'en_proceso'  => (int)$r['en_proceso'],
                'sin_iniciar' => (int)$r['sin_iniciar'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Simbolo y etiqueta accesible de cada clasificacion.
 * El color por si solo no basta: al imprimir en blanco y negro el rojo y el
 * verde se ven casi iguales, y hay personas que no distinguen colores.
 * Por eso cada clasificacion lleva ademas una FORMA y una LETRA.
 */
function recSimboloDecision(?string $decisionFinal): array
{
    $cat = catalogoDecisionFinal();
    if ($decisionFinal === 'Derrumbado') {
        return ['letra' => 'D', 'forma' => '■', 'icono' => 'bi-x-octagon-fill',
                'color' => '#2B2B2B', 'texto' => 'DERRUMBADO'];
    }
    $color = $cat[$decisionFinal ?? '']['color'] ?? '';
    return match ($color) {
        '#C9A227' => ['letra' => 'A', 'forma' => '▲', 'icono' => 'bi-exclamation-triangle-fill',
                      'color' => '#C9A227', 'texto' => 'AMARILLO'],
        '#A61C1C' => ['letra' => 'R', 'forma' => '✕', 'icono' => 'bi-x-circle-fill',
                      'color' => '#A61C1C', 'texto' => 'ROJO'],
        '#2E7D32' => ['letra' => 'V', 'forma' => '●', 'icono' => 'bi-check-circle-fill',
                      'color' => '#2E7D32', 'texto' => 'VERDE'],
        default   => ['letra' => '?', 'forma' => '○', 'icono' => 'bi-question-circle',
                      'color' => '#767c94', 'texto' => 'SIN CLASIFICAR'],
    };
}

function recPrioridadColor(?string $decisionFinal): int
{
    $cat = catalogoDecisionFinal();
    if ($decisionFinal === 'Derrumbado') return 4;
    $color = $cat[$decisionFinal ?? '']['color'] ?? '';
    return match ($color) {
        '#C9A227' => 1,   // Amarillo
        '#A61C1C' => 2,   // Rojo
        '#2E7D32' => 3,   // Verde
        default   => 5,   // Sin clasificar
    };
}

/**
 * Ordena edificaciones por color (amarillo, rojo, verde, derrumbado)
 * y dentro de cada grupo por mayor avance.
 */
function recOrdenarPorColor(array &$edificaciones): void
{
    usort($edificaciones, function ($a, $b) {
        $pa = recPrioridadColor($a['decision_final'] ?? ($a['decision'] ?? null));
        $pb = recPrioridadColor($b['decision_final'] ?? ($b['decision'] ?? null));
        if ($pa !== $pb) return $pa <=> $pb;
        return ((int)($b['avance'] ?? 0)) <=> ((int)($a['avance'] ?? 0));
    });
}

function recAvancesDeParroquia(string $estado, string $parroquia): array
{
    recAsegurarTablasAvance();
    try {
        $st = db()->prepare("
            SELECT x.inspeccion_id, ROUND(AVG(x.pct_piso)) AS avance
            FROM (
                SELECT re.inspeccion_id, pi.id AS piso_id, AVG(a.pct_apto) AS pct_piso
                FROM (
                    SELECT ap.id AS apto_id, ap.piso_id,
                           COALESCE(
                               AVG(CASE WHEN am.id IS NOT NULL THEN COALESCE(ava.porcentaje,0) END),
                               COALESCE(MAX(aa.porcentaje), 0)
                           ) AS pct_apto
                      FROM rec_apartamento ap
                      LEFT JOIN rec_ambiente am ON am.apartamento_id = ap.id
                      LEFT JOIN rec_avance_ambiente ava ON ava.ambiente_id = am.id
                      LEFT JOIN rec_avance_apto aa ON aa.apartamento_id = ap.id
                     GROUP BY ap.id, ap.piso_id
                ) a
                JOIN rec_piso pi ON pi.id = a.piso_id
                JOIN rec_edificio re ON re.id = pi.edificio_id
                JOIN inspecciones i ON i.id = re.inspeccion_id
               WHERE i.estado = :e AND i.parroquia = :p
               GROUP BY re.inspeccion_id, pi.id
            ) x
            GROUP BY x.inspeccion_id
        ");
        $st->execute(['e' => $estado, 'p' => $parroquia]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['inspeccion_id']] = (int)$r['avance'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function recAvanceEdificio(int $edificioId): int
{
    // Coherente con recArbolAvance: promedio de pisos (cada piso = promedio de sus aptos).
    $arbol = recArbolAvance($edificioId);
    return (int)$arbol['avance_edificio'];
}

/** Avance registrado de un apartamento (0 si no tiene). */
function recAvanceApartamento(int $apartamentoId): int
{
    $st = db()->prepare('SELECT porcentaje FROM rec_avance_apto WHERE apartamento_id = :a');
    $st->execute(['a' => $apartamentoId]);
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

/** Guarda el % de avance de un apartamento (solo sistematizador). */
function recGuardarAvanceApto(int $apartamentoId, int $porcentaje, ?string $obs = null): void
{
    recAsegurarTablasAvance();
    $porcentaje = max(0, min(100, $porcentaje));
    db()->prepare(
        'INSERT INTO rec_avance_apto (apartamento_id, porcentaje, observaciones, actualizado_por)
         VALUES (:a, :p, :o, :u)
         ON DUPLICATE KEY UPDATE porcentaje=VALUES(porcentaje), observaciones=VALUES(observaciones), actualizado_por=VALUES(actualizado_por)'
    )->execute([
        'a' => $apartamentoId, 'p' => $porcentaje, 'o' => $obs, 'u' => $_SESSION['user_id'] ?? null,
    ]);

    // Auditoría: quién movió el avance y a cuánto.
    try {
        $st = db()->prepare(
            'SELECT ap.identificador, pi.edificio_id, re.inspeccion_id
               FROM rec_apartamento ap
               JOIN rec_piso pi ON pi.id = ap.piso_id
               JOIN rec_edificio re ON re.id = pi.edificio_id
              WHERE ap.id = :a'
        );
        $st->execute(['a' => $apartamentoId]);
        if ($r = $st->fetch()) {
            recAuditar('avance_actualizado', (int)$r['inspeccion_id'], (int)$r['edificio_id'],
                'Apto ' . $r['identificador'] . ' → ' . $porcentaje . '%');
        }
    } catch (Throwable $e) { /* no interrumpir */ }
}

/** ¿El apartamento tiene al menos una foto del "durante"? (requisito para subir %) */
function recAptoTieneFotoDurante(int $apartamentoId): bool
{
    // Fotos del "durante" a nivel apartamento o de cualquiera de sus ambientes.
    $pdo = db();
    // A nivel apartamento
    $st = $pdo->prepare("SELECT COUNT(*) FROM rec_foto WHERE nivel='apartamento' AND ref_id=:a AND parte='durante'");
    $st->execute(['a' => $apartamentoId]);
    if ((int)$st->fetchColumn() > 0) return true;
    // A nivel de sus ambientes
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM rec_foto f
           JOIN rec_ambiente am ON am.id = f.ref_id
          WHERE f.nivel='ambiente' AND f.parte='durante' AND am.apartamento_id=:a"
    );
    $st->execute(['a' => $apartamentoId]);
    return (int)$st->fetchColumn() > 0;
}

/** ¿El usuario actual es sistematizador? (puede cargar avance/fotos durante) */
function esSistematizador(?int $userId = null): bool
{
    $userId = $userId ?? ($_SESSION['user_id'] ?? 0);
    if (!$userId) return false;
    // Los master siempre pueden.
    if (function_exists('usuarioEsMaster') && usuarioEsMaster()) return true;

    // 1) Por rol: si el usuario tiene el rol "Sistematizador", lo es.
    $rol = $_SESSION['rol_nombre'] ?? '';
    if ($rol !== '' && mb_stripos($rol, 'sistematizador') !== false) return true;

    // 2) Por marca explícita en la tabla (permite designar a cualquiera).
    try {
        $st = db()->prepare('SELECT 1 FROM rec_sistematizador WHERE user_id = :u AND activo = 1');
        $st->execute(['u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Si la tabla aún no existe, no bloquear: se decide solo por rol.
        return false;
    }
}

// =====================================================================
// MAPA POR PARROQUIA (rendimiento): conteo agregado + puntos bajo demanda
// =====================================================================

/**
 * Conteo de edificaciones por parroquia (para las burbujas del mapa).
 * Rápido: no trae los puntos, solo cuántos hay por parroquia y su color
 * predominante. Respeta el scope territorial del usuario.
 */
function segConteoPorParroquia(): array
{
    $pdo = db();
    $conds = ["i.parroquia IS NOT NULL", "i.parroquia <> ''"];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $where = 'WHERE ' . implode(' AND ', $conds);

    // "total" sigue siendo el total de inspecciones, para no perder el dato.
    // "en_obra" cuenta solo las que tienen el levantamiento cerrado: es lo
    // que se muestra en el mapa, porque son las que están en reconstrucción.
    $sql = "SELECT i.estado, i.parroquia,
                   COUNT(*) AS total,
                   SUM(CASE WHEN re.completado = 1 THEN 1 ELSE 0 END) AS en_obra,
                   SUM(i.decision_final = 'Edificación Insegura - Acceso No Permitido') AS rojos,
                   SUM(i.decision_final = 'Acceso Restringido - Precaución al Entrar') AS amarillos,
                   SUM(i.decision_final = 'Edificación Inspeccionada - Acceso Permitido') AS verdes,
                   SUM(i.decision_final = 'Derrumbado') AS derrumbados
              FROM inspecciones i
              LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where
             GROUP BY i.estado, i.parroquia";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Puntos (edificaciones) de UNA parroquia. Se carga bajo demanda cuando
 * el usuario selecciona la parroquia en el mapa, para no dibujar miles a la vez.
 */
/**
 * Puntos de una parroquia para el mapa.
 *
 * $fase controla qué se muestra:
 *   'reconstruccion' (por defecto) → solo las que tienen el levantamiento
 *                                    CERRADO, que son las que están en obra.
 *   'todas'                        → todas las inspecciones.
 *
 * Por defecto se ocultan las inspecciones sin levantamiento: con miles de
 * registros el mapa quedaba ilegible y no se distinguía el avance real.
 */
function segPuntosDeParroquia(string $estado, string $parroquia, string $fase = 'reconstruccion'): array
{
    $pdo = db();
    $conds = ['i.parroquia = :p', 'i.estado = :e'];
    $params = ['p' => $parroquia, 'e' => $estado];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');

    if ($fase === 'reconstruccion') {
        $conds[] = 're.completado = 1';
    }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $sql = "SELECT i.id AS inspeccion_id, i.codigo, i.nombre_edificio,
                   i.latitud, i.longitud, i.parroquia, i.municipio, i.estado,
                   i.uso_edificacion, i.num_pisos, i.numero_personas,
                   i.decision_final, i.fecha_inspeccion,
                   so.estado_obra, so.avance_pct, so.ente_id, e.nombre AS ente_nombre,
                   re.id AS rec_edificio_id, re.completado
              FROM inspecciones i
              LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
              LEFT JOIN entes e ON e.id = so.ente_id
              LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
              $where";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
