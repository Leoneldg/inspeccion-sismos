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

$puedeEditar = puede('seguimiento', 'editar') || puede('seguimiento', 'crear');

// Se crea la ficha si aún no existe (y pre-carga recursos de la inspección).
$obra = $puedeEditar ? segObtenerOCrearObra($inspeccionId) : (function () use ($inspeccionId) {
    $stmt = db()->prepare('SELECT * FROM seguimiento_obras WHERE inspeccion_id = :i');
    $stmt->execute(['i' => $inspeccionId]);
    return $stmt->fetch() ?: ['id' => 0, 'estado_obra' => 'Sin iniciar', 'avance_pct' => 0, 'ente_id' => null,
        'fecha_inicio' => null, 'fecha_fin_estimada' => null, 'fecha_fin_real' => null,
        'tiempo_accion_dias' => null, 'presupuesto_estimado' => null, 'prioridad' => 'Media',
        'observaciones' => null, 'responsable_id' => null];
})();

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

<div class="seg-ficha-grid">

    <!-- Columna izquierda: datos de planificación + recursos -->
    <div class="seg-ficha-col">

        <!-- Panel de planificación / ente / tiempos -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-clipboard-check"></i> Plan de acción</h2></div>
            <div class="card-body">
                <?php if ($puedeEditar): ?>
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
                            <label>Avance (%)</label>
                            <input type="range" min="0" max="100" step="5" name="avance_pct" id="f-avance-range" value="<?= (int)$obra['avance_pct'] ?>"
                                   oninput="document.getElementById('f-avance-val').textContent=this.value+'%'">
                            <div class="text-sm" style="text-align:center;font-weight:600;" id="f-avance-val"><?= (int)$obra['avance_pct'] ?>%</div>
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
                    <div class="text-muted text-sm">No tiene permisos para editar el plan de acción. Consulta de solo lectura.</div>
                    <dl class="seg-datos">
                        <div><dt>Ente</dt><dd><?= e($insp['ente_nombre'] ?? 'Sin asignar') ?></dd></div>
                        <div><dt>Estado</dt><dd><?= e($obra['estado_obra']) ?></dd></div>
                        <div><dt>Inicio</dt><dd><?= e($obra['fecha_inicio'] ?? '—') ?></dd></div>
                        <div><dt>Fin estimado</dt><dd><?= e($obra['fecha_fin_estimada'] ?? '—') ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recursos para la recuperación -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-box-seam"></i> Recursos para la recuperación</h2></div>
            <div class="card-body">
                <div class="text-sm text-muted" style="margin-bottom:8px;">
                    Los recursos marcados <span class="badge badge-gris">Inspección</span> se cargaron automáticamente desde los datos de la inspección (m² de losas, muros a reconstruir).
                </div>
                <div class="table-wrap">
                    <table class="data-table seg-recursos-table">
                        <thead><tr><th>Recurso</th><th>Unidad</th><th>Estimado</th><th>Usado</th><th>Origen</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php if (!$recursos): ?>
                            <tr><td colspan="6" class="text-muted text-sm">Aún no hay recursos registrados.</td></tr>
                        <?php else: foreach ($recursos as $rec): ?>
                            <tr>
                                <td><?= e($rec['recurso']) ?></td>
                                <td class="text-sm"><?= e($rec['unidad'] ?? '—') ?></td>
                                <td><?= $rec['cantidad_estimada'] !== null ? e(rtrim(rtrim(number_format((float)$rec['cantidad_estimada'], 2), '0'), '.')) : '—' ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float)$rec['cantidad_utilizada'], 2), '0'), '.')) ?></td>
                                <td><span class="badge <?= $rec['origen'] === 'Inspección' ? 'badge-gris' : 'badge-verde' ?>"><?= e($rec['origen']) ?></span></td>
                                <?php if ($puedeEditar): ?>
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
                <?php if ($puedeEditar): ?>
                <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_recurso.php" class="seg-add-recurso">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="accion" value="agregar">
                    <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">
                    <input type="text" name="recurso" class="form-control" placeholder="Recurso (ej. Cemento)" required>
                    <input type="text" name="unidad" class="form-control" placeholder="Unidad" style="max-width:90px;">
                    <input type="number" step="0.01" name="cantidad_estimada" class="form-control" placeholder="Cantidad" style="max-width:110px;">
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
                <?php if ($puedeEditar && $obraId): ?>
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
                                            <?php if ($puedeEditar): ?>
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
