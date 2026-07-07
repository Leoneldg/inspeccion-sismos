<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) {
    flash('error', 'Edificación no encontrada.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

// Alcance nacional: un usuario estadal no puede abrir fichas de otro estado.
if (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario()) {
    http_response_code(403);
    include __DIR__ . '/../403.php';
    exit;
}

// Dos niveles de acción en el módulo:
//  - GESTIONAR (permiso 'crear'): define el plan de acción — ente asignado,
//    responsable, tiempo de acción/fechas, prioridad, y agrega/quita recursos.
//    Es quien "arma" la ficha.
//  - REPORTAR (permiso 'ver'): la persona sistematizadora / ente / gobernante
//    que reporta el CONSUMO de cada recurso (estilo inventario) y sube el
//    registro fotográfico. NO define el plan ni agrega recursos nuevos.
// El avance ya NO se fija a mano: se calcula del consumo de recursos.
$puedeGestionar = puede('seguimiento', 'crear');
$puedeReportar  = puede('seguimiento', 'ver') || $puedeGestionar;

// La ficha (obra) se crea al abrirla solo si quien entra puede gestionar; un
// reportero sin obra creada ve la ficha en modo consulta.
$obra = $puedeGestionar ? segObtenerOCrearObra($inspeccionId) : (function () use ($inspeccionId) {
    $stmt = db()->prepare('SELECT * FROM seguimiento_obras WHERE inspeccion_id = :i');
    $stmt->execute(['i' => $inspeccionId]);
    return $stmt->fetch() ?: ['id' => 0, 'estado_obra' => 'Sin iniciar', 'avance_pct' => 0, 'ente_id' => null,
        'fecha_inicio' => null, 'fecha_fin_estimada' => null, 'fecha_fin_real' => null,
        'tiempo_accion_dias' => null, 'presupuesto_estimado' => null, 'prioridad' => 'Media',
        'observaciones' => null, 'responsable_id' => null];
})();

// Si el usuario pertenece a un ente, no puede abrir fichas de otro ente.
$miEnte = enteDelUsuario();
if ($miEnte !== null && !usuarioEsMaster()) {
    $obraEnte = $obra['ente_id'] ?? null;
    if ($obraEnte !== null && (int)$obraEnte !== (int)$miEnte) {
        http_response_code(403);
        include __DIR__ . '/../403.php';
        exit;
    }
}

$obraId      = (int)($obra['id'] ?? 0);
$entes       = segEntes(usuarioEsMaster() ? null : estadoDelUsuario());
$recursos    = $obraId ? segRecursos($obraId) : [];
$fotosPorFase = $obraId ? segFotos($obraId) : ['Inicio' => [], 'Avance' => [], 'Culminada' => []];
$bitacora    = $obraId ? segBitacoraLista($obraId) : [];
$estadosObra = segEstadosObra();
$fases       = segFasesFoto();
$decisiones  = catalogoDecisionFinal();
$decMeta     = $decisiones[$insp['decision_final']] ?? ['color' => '#767c94', 'corto' => $insp['decision_final']];
$tiempo      = segTiempoRestante($obra['fecha_fin_estimada'] ?? null, $obra['estado_obra'] ?? 'Sin iniciar');

// Responsables posibles: usuarios que pueden cargar seguimiento (mismo estado si aplica).
$respStmt = db()->prepare(
    'SELECT u.id, u.nombre_completo, u.es_master, u.estado_asignado
     FROM usuarios u WHERE u.activo = 1 ORDER BY u.nombre_completo'
);
$respStmt->execute();
$posiblesResponsables = array_filter($respStmt->fetchAll(), function ($u) use ($insp) {
    return $u['es_master'] || $u['estado_asignado'] === ($insp['estado'] ?? null) || $u['estado_asignado'] === null;
});

$pageTitle    = 'Ficha de seguimiento';
$pageSubtitle = $insp['nombre_edificio'];
$activeModule = 'seguimiento';

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm" style="margin-bottom:12px;">
    <i class="bi bi-arrow-left"></i> Volver al listado
</a>

<!-- Cabecera de la ficha -->
<div class="card seg-ficha-head" style="margin-bottom:14px;">
    <div class="seg-ficha-head-main">
        <div>
            <div class="seg-ficha-code"><?= e($insp['codigo']) ?></div>
            <h1 class="seg-ficha-title"><?= e($insp['nombre_edificio']) ?></h1>
            <div class="seg-ficha-loc">
                <i class="bi bi-geo-alt-fill"></i>
                <?= e($insp['parroquia']) ?><?php if (!empty($insp['municipio'])): ?>, <?= e($insp['municipio']) ?><?php endif; ?>, <?= e($insp['estado']) ?>
            </div>
        </div>
        <div class="seg-ficha-head-badges">
            <span class="badge" style="background:<?= $decMeta['color'] ?>22;color:<?= $decMeta['color'] ?>;font-size:13px;">
                <?= e($decMeta['corto']) ?>
            </span>
            <?php $colorObra = $estadosObra[$obra['estado_obra']] ?? '#767c94'; ?>
            <span class="badge" style="background:<?= $colorObra ?>22;color:<?= $colorObra ?>;font-size:13px;">
                <i class="bi bi-hammer"></i> <?= e($obra['estado_obra']) ?>
            </span>
        </div>
    </div>
    <!-- Barra de avance grande -->
    <div class="seg-ficha-progress">
        <div class="seg-progress seg-progress-lg">
            <div class="seg-progress-bar" id="ficha-progress-bar" style="width:<?= round((float)$obra['avance_pct']) ?>%;background:<?= $colorObra ?>;"></div>
            <span class="seg-progress-txt" id="ficha-progress-txt"><?= round((float)$obra['avance_pct']) ?>% completado</span>
        </div>
        <?php if ($tiempo['estado'] !== 'sin_fecha' && $tiempo['estado'] !== 'culminada'): ?>
        <div class="seg-tiempo-chip seg-tiempo-<?= $tiempo['estado'] ?>">
            <i class="bi bi-clock-history"></i>
            <?= $tiempo['dias'] < 0 ? abs($tiempo['dias']) . ' días de retraso' : $tiempo['dias'] . ' días restantes' ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Total de personas afectadas (suma de los grupos poblacionales).
$totalPersonas = 0;
foreach (['ninos','mujeres','hombres','adultos_tercera_edad','gestantes','movilidad_reducida'] as $gp) {
    $totalPersonas += (int)($insp[$gp] ?? 0);
}
$enteNombre = $insp['ente_nombre'] ?? null;
$enteTipo   = null;
if ($enteNombre && !empty($obra['ente_id'])) {
    foreach ($entes as $en) {
        if ((int)$en['id'] === (int)$obra['ente_id']) { $enteTipo = $en['tipo']; break; }
    }
}
?>
<!-- Bloque destacado: ENTE ASIGNADO + resumen breve de la inspección -->
<div class="seg-resumen-top">
    <!-- Ente asignado, resaltado con ícono grande -->
    <div class="seg-ente-destacado <?= $enteNombre ? 'tiene-ente' : 'sin-ente' ?>">
        <div class="seg-ente-icono"><i class="bi bi-building-fill-gear"></i></div>
        <div class="seg-ente-info">
            <span class="seg-ente-label">Ente asignado</span>
            <span class="seg-ente-nombre"><?= $enteNombre ? e($enteNombre) : 'Sin asignar' ?></span>
            <?php if ($enteTipo): ?><span class="seg-ente-tipo"><?= e($enteTipo) ?></span><?php endif; ?>
            <?php if (!$enteNombre && $puedeGestionar): ?>
                <span class="seg-ente-hint">Asígnelo en el <strong>Plan de acción</strong>, más abajo.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resumen breve de la inspección (no todos los detalles) -->
    <div class="seg-resumen-insp">
        <div class="seg-resumen-titulo"><i class="bi bi-clipboard-data"></i> Resumen de la inspección</div>
        <div class="seg-resumen-grid">
            <div class="seg-resumen-item seg-resumen-item-full">
                <span class="k"><i class="bi bi-geo-alt-fill"></i> Ubicación</span>
                <span class="v"><?= e($insp['parroquia'] ?? '—') ?><?php if (!empty($insp['municipio'])): ?>, <?= e($insp['municipio']) ?><?php endif; ?>, <?= e($insp['estado'] ?? '—') ?></span>
            </div>
            <div class="seg-resumen-item seg-resumen-item-full">
                <span class="k">Decisión final</span>
                <span class="v"><span class="badge" style="background:<?= $decMeta['color'] ?>22;color:<?= $decMeta['color'] ?>;"><?= e($decMeta['corto']) ?></span></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Pisos</span>
                <span class="v"><?= (int)($insp['num_pisos'] ?? 0) ?></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Apartamentos</span>
                <span class="v"><?= (int)($insp['cantidad_apartamentos'] ?? 0) ?></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Uso</span>
                <span class="v"><?= e($insp['uso_edificacion'] ?: '—') ?></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Familias</span>
                <span class="v"><?= (int)($insp['familias'] ?? 0) ?></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Personas afectadas</span>
                <span class="v"><?= $totalPersonas ?></span>
            </div>
            <div class="seg-resumen-item">
                <span class="k">Ver ficha completa</span>
                <span class="v"><a href="<?= APP_URL_BASE ?>formulario/view.php?id=<?= (int)$inspeccionId ?>" class="seg-resumen-link">Abrir <i class="bi bi-box-arrow-up-right"></i></a></span>
            </div>
        </div>
    </div>
</div>

<div class="seg-ficha-grid">

    <!-- Columna izquierda: datos de planificación + recursos -->
    <div class="seg-ficha-col">

        <!-- Panel de planificación / ente / tiempos -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-clipboard-check"></i> Plan de acción</h2></div>
            <div class="card-body">
                <div class="text-sm text-muted" style="margin-bottom:10px;">
                    <i class="bi bi-info-circle"></i> El <strong>avance</strong> se calcula automáticamente según el consumo de recursos reportado más abajo.
                </div>
                <?php if ($puedeGestionar): ?>
                <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_obra.php" id="form-plan">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">

                    <div class="field">
                        <label><i class="bi bi-building"></i> Ente asignado</label>
                        <select name="ente_id" class="form-control">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ($entes as $ente): ?>
                                <option value="<?= (int)$ente['id'] ?>" <?= (int)$obra['ente_id'] === (int)$ente['id'] ? 'selected' : '' ?>>
                                    <?= e($ente['nombre']) ?> (<?= e($ente['tipo']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-sm text-muted" style="margin-top:3px;">
                            ¿No aparece? <a href="<?= APP_URL_BASE ?>seguimiento/entes.php">Registrar un ente</a>.
                        </div>
                    </div>

                    <div class="field">
                        <label><i class="bi bi-person-badge"></i> Responsable de carga</label>
                        <select name="responsable_id" class="form-control">
                            <option value="">— Sin responsable —</option>
                            <?php foreach ($posiblesResponsables as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= (int)$obra['responsable_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid cols-2">
                        <div class="field">
                            <label>Fecha de inicio</label>
                            <input type="date" name="fecha_inicio" id="f-inicio" class="form-control" value="<?= e($obra['fecha_inicio']) ?>">
                        </div>
                        <div class="field">
                            <label>Tiempo de acción (días)</label>
                            <input type="number" min="0" name="tiempo_accion_dias" id="f-dias" class="form-control" value="<?= e($obra['tiempo_accion_dias']) ?>"
                                   placeholder="Duración estimada">
                        </div>
                        <div class="field">
                            <label>Fecha fin estimada</label>
                            <input type="date" name="fecha_fin_estimada" id="f-fin" class="form-control" value="<?= e($obra['fecha_fin_estimada']) ?>">
                            <div class="text-sm text-muted" id="f-fin-auto" style="margin-top:3px;"></div>
                        </div>
                        <div class="field">
                            <label>Fecha fin real</label>
                            <input type="date" name="fecha_fin_real" class="form-control" value="<?= e($obra['fecha_fin_real']) ?>">
                        </div>
                    </div>

                    <div class="form-grid cols-2">
                        <div class="field">
                            <label>Estado de obra</label>
                            <select name="estado_obra" class="form-control">
                                <?php foreach (array_keys($estadosObra) as $eo): ?>
                                    <option value="<?= e($eo) ?>" <?= $obra['estado_obra'] === $eo ? 'selected' : '' ?>><?= e($eo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Prioridad</label>
                            <select name="prioridad" class="form-control">
                                <?php foreach (['Alta', 'Media', 'Baja'] as $pr): ?>
                                    <option value="<?= $pr ?>" <?= ($obra['prioridad'] ?? 'Media') === $pr ? 'selected' : '' ?>><?= $pr ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Presupuesto estimado</label>
                            <input type="number" min="0" step="0.01" name="presupuesto_estimado" class="form-control" value="<?= e($obra['presupuesto_estimado']) ?>" placeholder="Bs. / USD">
                        </div>
                    </div>

                    <div class="field">
                        <label>Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="2"><?= e($obra['observaciones']) ?></textarea>
                    </div>

                    <button class="btn btn-primary w-full" style="justify-content:center;"><i class="bi bi-save-fill"></i> Guardar plan de acción</button>
                </form>
                <?php else: ?>
                    <div class="text-muted text-sm" style="margin-bottom:8px;">Solo un usuario con permiso de gestión define el plan de acción. Usted puede reportar el consumo de recursos y subir fotos.</div>
                    <dl class="seg-datos">
                        <div><dt>Estado</dt><dd><?= e($obra['estado_obra']) ?></dd></div>
                        <div><dt>Inicio</dt><dd><?= e($obra['fecha_inicio'] ?? '—') ?></dd></div>
                        <div><dt>Fin estimado</dt><dd><?= e($obra['fecha_fin_estimada'] ?? '—') ?></dd></div>
                        <div><dt>Tiempo de acción</dt><dd><?= $obra['tiempo_accion_dias'] !== null ? e($obra['tiempo_accion_dias']) . ' días' : '—' ?></dd></div>
                        <div><dt>Prioridad</dt><dd><?= e($obra['prioridad'] ?? '—') ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recursos para la recuperación -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-box-seam"></i> Recursos para la recuperación</h2></div>
            <div class="card-body">
                <div class="text-sm text-muted" style="margin-bottom:8px;">
                    Los recursos marcados <span class="badge badge-gris">Inspección</span> se cargaron automáticamente desde los datos de la inspección. A medida que se reporta el <strong>consumo</strong> de cada recurso, el avance de la obra se actualiza solo.
                </div>
                <div class="table-wrap">
                    <table class="data-table seg-recursos-table">
                        <thead><tr>
                            <th>Recurso</th><th>Unidad</th><th>Estimado</th>
                            <th style="min-width:170px;">Consumido</th><th>Origen</th>
                            <?php if ($puedeGestionar): ?><th></th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                        <?php if (!$recursos): ?>
                            <tr><td colspan="6" class="text-muted text-sm">Aún no hay recursos registrados.</td></tr>
                        <?php else: foreach ($recursos as $rec):
                            $est = $rec['cantidad_estimada'] !== null ? (float)$rec['cantidad_estimada'] : null;
                            $uso = (float)$rec['cantidad_utilizada'];
                            $pct = ($est && $est > 0) ? min(100, round($uso / $est * 100)) : null;
                        ?>
                            <tr>
                                <td><?= e($rec['recurso']) ?></td>
                                <td class="text-sm"><?= e($rec['unidad'] ?? '—') ?></td>
                                <td><?= $est !== null ? e(rtrim(rtrim(number_format($est, 2), '0'), '.')) : '—' ?></td>
                                <td>
                                    <?php if ($puedeReportar && $obraId): ?>
                                    <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_recurso.php" class="seg-consumo-form">
                                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="accion" value="consumo">
                                        <input type="hidden" name="recurso_id" value="<?= (int)$rec['id'] ?>">
                                        <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                                        <input type="number" step="0.01" min="0" name="cantidad_utilizada"
                                               value="<?= e(rtrim(rtrim(number_format($uso, 2), '0'), '.')) ?>"
                                               class="form-control seg-consumo-input" title="Cantidad consumida">
                                        <button class="btn btn-primary btn-sm" title="Guardar consumo"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <?php if ($pct !== null): ?>
                                        <div class="seg-progress seg-progress-sm" style="margin-top:4px;">
                                            <div class="seg-progress-bar" style="width:<?= $pct ?>%;"></div>
                                            <span class="seg-progress-txt"><?= $pct ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                        <?= e(rtrim(rtrim(number_format($uso, 2), '0'), '.')) ?><?= $pct !== null ? " ($pct%)" : '' ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $rec['origen'] === 'Inspección' ? 'badge-gris' : 'badge-verde' ?>"><?= e($rec['origen']) ?></span></td>
                                <?php if ($puedeGestionar): ?>
                                <td>
                                    <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_recurso.php" onsubmit="return confirm('¿Eliminar este recurso?');" style="display:inline;">
                                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="recurso_id" value="<?= (int)$rec['id'] ?>">
                                        <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                                        <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($puedeGestionar): ?>
                <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_recurso.php" class="seg-add-recurso">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="accion" value="agregar">
                    <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                    <input type="text" name="recurso" class="form-control" placeholder="Recurso (ej. Cemento)" required>
                    <input type="text" name="unidad" class="form-control" placeholder="Unidad" style="max-width:90px;">
                    <input type="number" step="0.01" name="cantidad_estimada" class="form-control" placeholder="Estimado" style="max-width:110px;">
                    <button class="btn btn-outline btn-sm"><i class="bi bi-plus-lg"></i> Agregar</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Columna derecha: registro fotográfico + bitácora -->
    <div class="seg-ficha-col">

        <!-- Registro fotográfico (línea de tiempo) -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-camera-fill"></i> Registro fotográfico de la obra</h2></div>
            <div class="card-body">
                <?php if ($puedeReportar && $obraId): ?>
                <form method="post" action="<?= APP_URL_BASE ?>seguimiento/subir_foto.php" enctype="multipart/form-data" class="seg-foto-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                    <div class="seg-foto-form-row">
                        <select name="fase" class="form-control" style="max-width:150px;">
                            <?php foreach (array_keys($fases) as $f): ?>
                                <option value="<?= e($f) ?>"><?= e($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="fecha_registro" class="form-control" value="<?= date('Y-m-d') ?>" style="max-width:160px;">
                        <input type="text" name="descripcion" class="form-control" placeholder="Descripción (opcional)">
                    </div>
                    <div class="seg-foto-form-row">
                        <label class="seg-foto-drop" for="seg-foto-input">
                            <i class="bi bi-camera"></i> <span>Tomar / elegir foto</span>
                        </label>
                        <input type="file" name="foto" id="seg-foto-input" accept="image/*" capture="environment" required style="display:none;"
                               onchange="document.getElementById('seg-foto-name').textContent=this.files[0]?this.files[0].name:'';">
                        <span class="text-sm text-muted" id="seg-foto-name"></span>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Subir</button>
                    </div>
                </form>
                <?php endif; ?>

                <!-- Timeline por fase -->
                <div class="seg-timeline">
                    <?php foreach ($fases as $faseNombre => $faseMeta): ?>
                        <?php $fotos = $fotosPorFase[$faseNombre] ?? []; ?>
                        <div class="seg-timeline-fase">
                            <div class="seg-timeline-label" style="color:<?= $faseMeta['color'] ?>;">
                                <i class="bi <?= $faseMeta['icono'] ?>"></i> <?= e($faseNombre) ?>
                                <span class="seg-timeline-count"><?= count($fotos) ?></span>
                            </div>
                            <?php if ($faseNombre === 'Culminada' && !$fotos): ?>
                                <div class="seg-timeline-empty">Se mostrará aquí el registro de la obra culminada al finalizar.</div>
                            <?php elseif (!$fotos): ?>
                                <div class="seg-timeline-empty">Sin fotos en esta fase.</div>
                            <?php else: ?>
                                <div class="seg-foto-grid">
                                    <?php foreach ($fotos as $foto): ?>
                                        <figure class="seg-foto-card">
                                            <a href="<?= APP_URL_BASE . e($foto['ruta']) ?>" target="_blank">
                                                <img src="<?= APP_URL_BASE . e($foto['ruta']) ?>" alt="" loading="lazy">
                                            </a>
                                            <figcaption>
                                                <span class="seg-foto-fecha"><?= e($foto['fecha_registro']) ?></span>
                                                <?php if ($foto['avance_pct'] !== null): ?><span class="seg-foto-pct"><?= round((float)$foto['avance_pct']) ?>%</span><?php endif; ?>
                                                <?php if ($foto['descripcion']): ?><span class="seg-foto-desc"><?= e($foto['descripcion']) ?></span><?php endif; ?>
                                            </figcaption>
                                            <?php if ($puedeReportar): ?>
                                            <form method="post" action="<?= APP_URL_BASE ?>seguimiento/subir_foto.php" onsubmit="return confirm('¿Eliminar esta foto?');" class="seg-foto-del">
                                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="foto_id" value="<?= (int)$foto['id'] ?>">
                                                <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                                                <button title="Eliminar"><i class="bi bi-x"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </figure>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Bitácora -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-journal-text"></i> Bitácora</h2></div>
            <div class="card-body">
                <?php if (!$bitacora): ?>
                    <div class="text-muted text-sm">Sin eventos registrados.</div>
                <?php else: ?>
                    <ul class="seg-bitacora">
                        <?php foreach ($bitacora as $b): ?>
                            <li>
                                <div class="seg-bitacora-dot"></div>
                                <div class="seg-bitacora-body">
                                    <strong><?= e($b['evento']) ?></strong>
                                    <?php if ($b['detalle']): ?><div class="text-sm"><?= e($b['detalle']) ?></div><?php endif; ?>
                                    <div class="text-sm text-muted"><?= e($b['creado_en']) ?> · <?= e($b['usuario_nombre'] ?? 'Sistema') ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Cálculo automático de fecha fin estimada = inicio + tiempo de acción.
(function () {
    const fInicio = document.getElementById('f-inicio');
    const fDias   = document.getElementById('f-dias');
    const fFin    = document.getElementById('f-fin');
    const fFinAuto = document.getElementById('f-fin-auto');
    if (!fInicio || !fDias || !fFin) return;
    function calcular() {
        if (fInicio.value && fDias.value) {
            const d = new Date(fInicio.value + 'T00:00:00');
            d.setDate(d.getDate() + parseInt(fDias.value, 10));
            const iso = d.toISOString().slice(0, 10);
            if (!fFin.value || fFin.dataset.auto === '1') {
                fFin.value = iso; fFin.dataset.auto = '1';
                if (fFinAuto) fFinAuto.textContent = 'Calculada automáticamente';
            }
        }
    }
    fInicio.addEventListener('change', calcular);
    fDias.addEventListener('input', calcular);
    fFin.addEventListener('change', () => { fFin.dataset.auto = '0'; if (fFinAuto) fFinAuto.textContent = ''; });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
