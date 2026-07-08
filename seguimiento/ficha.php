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
// Plan de acción — materiales y reportes de inventario.
$materiales        = $obraId ? segMaterialesDe($obraId) : [];
$reportesInv       = $obraId ? segReportesObra($obraId) : [];
$catMateriales     = segCatalogoMateriales();
$tiposConstruccion = segTiposConstruccion();
$unidadesMat       = segUnidadesMateriales();
// Permisos para seguimiento: crear = elabora el plan; ver = reporta inventario.
// "Editar" no aplica en seguimiento (se omite).
$puedeCrearPlan    = puede('seguimiento', 'crear');
$puedeReportar     = puede('seguimiento', 'ver') || $puedeCrearPlan;
$puedeEliminar     = puede('seguimiento', 'eliminar');
$puedeEditar       = $puedeCrearPlan; // alias para compatibilidad con el resto de la ficha


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
<?php if ($puedeEliminar && usuarioEsMaster()): ?>
<button type="button" class="btn btn-danger btn-sm" style="margin-bottom:12px;float:right;" onclick="confirmarEliminarFicha()">
    <i class="bi bi-trash-fill"></i> Eliminar ficha de seguimiento
</button>
<div id="modal-eliminar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:24px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);">
        <h3 style="margin:0 0 8px;color:#a61c1c;"><i class="bi bi-exclamation-triangle-fill"></i> Eliminar ficha de seguimiento</h3>
        <p style="font-size:14px;margin:0 0 16px;">Esta acción eliminará permanentemente el plan de acción, materiales, reportes de inventario, fotos y bitácora de seguimiento de <strong><?= e($insp['nombre_edificio']) ?></strong>. La inspección original no se afecta.</p>
        <p style="font-size:14px;margin:0 0 16px;color:#a61c1c;">Esta operación solo puede realizarla el superadministrador y no puede deshacerse.</p>
        <form method="post" action="<?= APP_URL_BASE ?>seguimiento/eliminar_ficha.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="inspeccion_id" value="<?= $inspeccionId ?>">
            <div class="field" style="margin-bottom:14px;">
                <label>Motivo de la eliminación (requerido)</label>
                <textarea name="motivo" class="form-control" rows="2" required placeholder="Explique por qué se elimina esta ficha…"></textarea>
            </div>
            <div class="flex gap-8">
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash-fill"></i> Confirmar eliminación</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModalEliminar()">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<script>
function confirmarEliminarFicha() { document.getElementById('modal-eliminar').style.display='flex'; }
function cerrarModalEliminar() { document.getElementById('modal-eliminar').style.display='none'; }
</script>
<?php endif; ?>

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

        <!-- =====================================================================
             PLAN DE ACCIÓN UNIFICADO
             Quien CREA: llena todos los campos (datos generales + materiales).
             Quien VE: solo lectura de lo llenado.
             ===================================================================== -->
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-clipboard-check"></i> Plan de acción</h2></div>
            <div class="card-body">

            <?php if ($puedeCrearPlan): ?>
            <!-- FORMULARIO COMPLETO para quien crea el plan -->
            <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_plan.php" id="form-plan-completo">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="inspeccion_id" value="<?= (int)$inspeccionId ?>">

                <!-- Sección: Datos generales -->
                <div class="section-title" style="margin-bottom:10px;"><i class="bi bi-info-circle"></i> Datos generales</div>
                <div class="form-grid cols-2" style="margin-bottom:14px;">
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
                    </div>
                    <div class="field">
                        <label><i class="bi bi-person-badge"></i> Responsable</label>
                        <select name="responsable_id" class="form-control">
                            <option value="">— Sin responsable —</option>
                            <?php foreach ($posiblesResponsables as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= (int)$obra['responsable_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                        <label>Fecha de inicio</label>
                        <input type="date" name="fecha_inicio" id="f-inicio" class="form-control" value="<?= e($obra['fecha_inicio']) ?>">
                    </div>
                    <div class="field">
                        <label>Días de duración estimada</label>
                        <input type="number" min="0" name="tiempo_accion_dias" id="f-dias" class="form-control" value="<?= e($obra['tiempo_accion_dias']) ?>" placeholder="Días">
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
                    <div class="field">
                        <label>Presupuesto estimado</label>
                        <input type="number" min="0" step="0.01" name="presupuesto_estimado" class="form-control" value="<?= e($obra['presupuesto_estimado']) ?>" placeholder="Bs. / USD">
                    </div>
                    <div class="field">
                        <label>Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="2"><?= e($obra['observaciones']) ?></textarea>
                    </div>
                </div>

                <!-- Sección: Tipo de construcción y metraje -->
                <div class="section-title" style="margin-bottom:10px;"><i class="bi bi-hammer"></i> Intervenciones del plan</div>
                <div class="text-sm text-muted" style="margin-bottom:10px;">
                    Agregue cada tipo de construcción con su metraje. Calcule con IA y los materiales se acumularán en la lista de abajo.
                </div>

                <!-- Filas de intervenciones (tipo + metraje + botón calcular IA) -->
                <div id="lista-intervenciones" style="margin-bottom:8px;">
                    <div class="interv-row" style="display:grid;grid-template-columns:1.5fr 1fr 80px auto auto;gap:6px;align-items:center;margin-bottom:6px;">
                        <select class="form-control form-control-sm interv-tipo">
                            <option value="">— Tipo de construcción —</option>
                            <option value="Pared">Pared</option>
                            <option value="Piso">Piso</option>
                            <option value="Techo">Techo</option>
                            <option value="Viga">Viga</option>
                            <option value="Columna">Columna</option>
                            <option value="Fundación">Fundación</option>
                            <option value="Escalera">Escalera</option>
                            <option value="Fachada">Fachada</option>
                            <option value="Instalación eléctrica">Instalación eléctrica</option>
                            <option value="Instalación sanitaria">Instalación sanitaria</option>
                            <option value="Cielo raso">Cielo raso</option>
                            <option value="Estructura general">Estructura general</option>
                            <option value="Otro">Otro</option>
                        </select>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm interv-metraje" placeholder="Metraje">
                        <select class="form-control form-control-sm interv-unidad">
                            <option value="m²">m²</option><option value="ml">ml</option>
                            <option value="m³">m³</option><option value="und">und</option>
                        </select>
                        <button type="button" class="btn btn-primary btn-sm btn-calcular-interv" title="Calcular materiales para esta intervención">
                            <i class="bi bi-stars"></i> Calcular
                        </button>
                        <button type="button" class="btn btn-outline btn-sm btn-quitar-interv" style="display:none;" title="Quitar esta fila">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

                <button type="button" id="btn-add-intervencion" class="btn btn-outline btn-sm" style="margin-bottom:12px;">
                    <i class="bi bi-plus-lg"></i> Agregar otra intervención
                </button>

                <!-- Estado de cálculos IA -->
                <div id="ia-estado" style="display:none;margin-bottom:12px;font-size:13px;padding:10px 14px;border-radius:8px;background:#f4f6fd;border:1px solid #c7d7f9;"></div>

                <!-- Campos ocultos para el guardado (toman el valor de la primera fila) -->
                <input type="hidden" name="tipo_construccion" id="campo-tipo-principal">
                <input type="hidden" name="metraje_total"     id="campo-metraje-principal">
                <input type="hidden" name="metraje_unidad"    id="campo-unidad-principal">

                <!-- Sección: Materiales del plan (acumulados de todas las intervenciones) -->
                <div class="section-title" style="margin-bottom:8px;margin-top:8px;"><i class="bi bi-box-seam"></i> Materiales del plan</div>
                <div class="text-sm text-muted" style="margin-bottom:8px;">Los materiales calculados por la IA se acumulan aquí. Puede editarlos manualmente o quitar los que no apliquen.</div>
                <div id="tabla-materiales">

                    <?php if ($materiales): foreach ($materiales as $mat): ?>
                    <div class="seg-material-row" style="display:grid;grid-template-columns:1fr 1.2fr 80px 110px auto;gap:6px;align-items:center;margin-bottom:6px;">
                        <input type="hidden" name="mat_id[]" value="<?= (int)$mat['id'] ?>">
                        <select name="mat_categoria[]" class="form-control form-control-sm seg-mat-cat" onchange="actualizarSubtipos(this)">
                            <option value="">— Categoría —</option>
                            <?php foreach ($catMateriales as $cat => $subs): ?>
                                <option value="<?= e($cat) ?>" <?= $mat['categoria'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="mat_subtipo[]" class="form-control form-control-sm seg-mat-sub">
                            <option value="">— Subtipo —</option>
                            <?php $subActual = $mat['subtipo'] ?? ''; $catActual = $mat['categoria'] ?? ''; ?>
                            <?php foreach ($catMateriales[$catActual] ?? [] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $subActual === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="mat_unidad[]" class="form-control form-control-sm">
                            <?php foreach (segUnidadesMateriales() as $u => $ul): ?>
                                <option value="<?= e($u) ?>" <?= ($mat['unidad'] ?? 'und') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" step="0.01" min="0" name="mat_cantidad[]" class="form-control form-control-sm"
                               placeholder="Cantidad" value="<?= e(rtrim(rtrim(number_format((float)$mat['cantidad_asignada'],2),'0'),'.')) ?>">
                        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.seg-material-row').remove()"><i class="bi bi-trash"></i></button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                </div>
                <button type="button" id="btn-add-material" class="btn btn-outline btn-sm" style="margin-top:6px;">
                    <i class="bi bi-plus-lg"></i> Agregar material manualmente
                </button>


                <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--gris-200);">
                    <button class="btn btn-primary"><i class="bi bi-save-fill"></i> Guardar plan de acción</button>
                </div>
            </form>

            <?php else: ?>
            <!-- VISTA SOLO LECTURA para quien tiene permiso VER -->
            <dl class="seg-datos">
                <div><dt>Ente</dt><dd><?= e($insp['ente_nombre'] ?? '—') ?></dd></div>
                <div><dt>Estado</dt><dd><?= e($obra['estado_obra'] ?? '—') ?></dd></div>
                <div><dt>Prioridad</dt><dd><?= e($obra['prioridad'] ?? '—') ?></dd></div>
                <div><dt>Tipo construcción</dt><dd><?= e($obra['tipo_construccion'] ?? '—') ?></dd></div>
                <div><dt>Metraje</dt><dd><?= e($obra['metraje_total'] ?? '—') ?> <?= e($obra['metraje_unidad'] ?? '') ?></dd></div>
                <div><dt>Inicio</dt><dd><?= e($obra['fecha_inicio'] ?? '—') ?></dd></div>
                <div><dt>Fin estimado</dt><dd><?= e($obra['fecha_fin_estimada'] ?? '—') ?></dd></div>
                <div><dt>Avance</dt><dd><?= round((float)($obra['avance_pct'] ?? 0)) ?>%</dd></div>
            </dl>
            <?php if ($materiales): ?>
            <div class="section-title" style="margin-top:10px;"><i class="bi bi-box-seam"></i> Materiales</div>
            <div class="table-wrap"><table class="data-table" style="font-size:13px;">
                <thead><tr><th>Material</th><th>Subtipo</th><th>Asignado</th><th>Stock actual</th></tr></thead>
                <tbody>
                <?php foreach ($materiales as $mat): ?>
                <tr>
                    <td><?= e($mat['categoria']) ?></td>
                    <td class="text-sm text-muted"><?= e($mat['subtipo'] ?? '—') ?></td>
                    <td><?= e(rtrim(rtrim(number_format((float)$mat['cantidad_asignada'],2),'0'),'.')) ?> <?= e($mat['unidad']) ?></td>
                    <td><?= e(rtrim(rtrim(number_format((float)$mat['cantidad_actual'],2),'0'),'.')) ?> <?= e($mat['unidad']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
            <?php endif; ?>

            </div>
        <!-- REPORTE DE INVENTARIO (quien ve = responsable en campo) -->
        <?php if ($puedeReportar && $materiales): ?>
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h2><i class="bi bi-clipboard2-data-fill"></i> Reportar inventario</h2>
                <span class="text-sm text-muted">Actualice el stock restante de cada material</span>
            </div>
            <div class="card-body">
                <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_inventario.php">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="inspeccion_id" value="<?= $inspeccionId ?>">
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Material</th><th>Asignado</th><th>Stock actual</th><th>Restante a reportar</th></tr></thead>
                            <tbody>
                            <?php foreach ($materiales as $mat):
                                $usadoPct = $mat['cantidad_asignada'] > 0
                                    ? min(100, round((($mat['cantidad_asignada']-$mat['cantidad_actual'])/$mat['cantidad_asignada'])*100)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="inv_mat_id[]" value="<?= (int)$mat['id'] ?>">
                                    <strong><?= e($mat['categoria']) ?></strong>
                                    <?php if ($mat['subtipo']): ?><div class="text-sm text-muted"><?= e($mat['subtipo']) ?></div><?php endif; ?>
                                </td>
                                <td><?= e(rtrim(rtrim(number_format((float)$mat['cantidad_asignada'],2),'0'),'.')) ?> <?= e($mat['unidad']) ?></td>
                                <td>
                                    <?= e(rtrim(rtrim(number_format((float)$mat['cantidad_actual'],2),'0'),'.')) ?> <?= e($mat['unidad']) ?>
                                    <div class="seg-progress-wrap" style="margin-top:4px;">
                                        <div class="seg-progress-bar" style="width:<?= $usadoPct ?>%;background:<?= $usadoPct>=80?'#1c6b3d':($usadoPct>=50?'#C9A227':'#2d4488') ?>;height:4px;border-radius:2px;"></div>
                                    </div>
                                    <div class="text-sm text-muted"><?= $usadoPct ?>% usado</div>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="inv_restante[]"
                                           class="form-control form-control-sm"
                                           placeholder="Cantidad restante"
                                           value="<?= e(rtrim(rtrim(number_format((float)$mat['cantidad_actual'],2),'0'),'.')) ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-grid cols-2" style="margin-top:10px;">
                        <div class="field">
                            <label>Metraje completado hasta ahora (<?= e($obra['metraje_unidad'] ?? 'm²') ?>)</label>
                            <input type="number" step="0.01" min="0" name="inv_metraje" class="form-control"
                                   placeholder="Ej: 12.5"
                                   max="<?= e($obra['metraje_total'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Nota del reporte (opcional)</label>
                            <input type="text" name="inv_nota" class="form-control" placeholder="Observaciones del día…">
                        </div>
                    </div>
                    <button class="btn btn-primary" style="margin-top:8px;"><i class="bi bi-clipboard2-check-fill"></i> Registrar reporte</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- BITÁCORA DE INVENTARIO (historial compacto) -->
        <?php if ($reportesInv): ?>
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><h2><i class="bi bi-journal-bookmark-fill"></i> Historial de reportes</h2></div>
            <div class="card-body" style="padding:0;">
                <?php
                $reportesPorFecha = [];
                foreach ($reportesInv as $r) {
                    $fecha = substr($r['reportado_en'], 0, 10);
                    $reportesPorFecha[$fecha][] = $r;
                }
                ?>
                <?php foreach ($reportesPorFecha as $fecha => $rows): ?>
                <details style="border-bottom:1px solid var(--gris-200);" <?= $fecha === array_key_first($reportesPorFecha) ? 'open' : '' ?>>
                    <summary style="padding:10px 16px;cursor:pointer;font-weight:600;font-size:14px;list-style:none;display:flex;justify-content:space-between;align-items:center;">
                        <span><i class="bi bi-calendar3" style="margin-right:6px;"></i><?= date('d/m/Y', strtotime($fecha)) ?></span>
                        <span class="text-sm text-muted"><?= count($rows) ?> material(es) · <?= e($rows[0]['reportado_nombre'] ?? 'Sistema') ?></span>
                    </summary>
                    <div class="table-wrap" style="padding:0 12px 12px;">
                        <table class="data-table" style="font-size:12px;">
                            <thead><tr><th>Material</th><th>Asignado</th><th>Restante</th><th>Usado</th><th>Metraje</th><th>Nota</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= e($r['categoria']) ?><?= $r['subtipo'] ? '<div class="text-sm text-muted">'.e($r['subtipo']).'</div>' : '' ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float)$r['cantidad_asignada'],2),'0'),'.')) ?> <?= e($r['unidad']) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float)$r['cantidad_restante'],2),'0'),'.')) ?></td>
                                <td><?= $r['cantidad_usada'] !== null ? e(rtrim(rtrim(number_format((float)$r['cantidad_usada'],2),'0'),'.')) : '—' ?></td>
                                <td><?= $r['metraje_avance'] !== null ? e(rtrim(rtrim(number_format((float)$r['metraje_avance'],2),'0'),'.')) : '—' ?></td>
                                <td class="text-sm text-muted"><?= e($r['nota'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

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

<script>
// ---- Plan de materiales: agregar filas y subtipos dinámicos ----
(function () {
    const CATALOGO = <?= json_encode(segCatalogoMateriales(), JSON_UNESCAPED_UNICODE) ?>;
    const UNIDADES = <?= json_encode(array_keys(segUnidadesMateriales()), JSON_UNESCAPED_UNICODE) ?>;

    function crearFilaMaterial() {
        const div = document.createElement('div');
        div.className = 'seg-material-row';
        div.style.cssText = 'display:grid;grid-template-columns:1fr 1.2fr 80px 110px auto;gap:6px;align-items:center;margin-bottom:6px;';
        div.innerHTML = `
            <input type="hidden" name="mat_id[]" value="0">
            <select name="mat_categoria[]" class="form-control form-control-sm seg-mat-cat">
                <option value="">— Categoría —</option>
                ${Object.keys(CATALOGO).map(c=>`<option value="${c}">${c}</option>`).join('')}
            </select>
            <select name="mat_subtipo[]" class="form-control form-control-sm seg-mat-sub">
                <option value="">— Subtipo —</option>
            </select>
            <select name="mat_unidad[]" class="form-control form-control-sm">
                ${UNIDADES.map(u=>`<option value="${u}">${u}</option>`).join('')}
            </select>
            <input type="number" step="0.01" min="0" name="mat_cantidad[]" class="form-control form-control-sm" placeholder="Cantidad">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.seg-material-row').remove()"><i class="bi bi-trash"></i></button>`;
        div.querySelector('.seg-mat-cat').addEventListener('change', function () {
            actualizarSubtipos(this);
        });
        return div;
    }

    window.actualizarSubtipos = function (sel) {
        const sub = sel.closest('.seg-material-row').querySelector('.seg-mat-sub');
        const subs = CATALOGO[sel.value] || [];
        sub.innerHTML = '<option value="">— Subtipo —</option>' +
            subs.map(s => `<option value="${s}">${s}</option>`).join('');
    };

    document.getElementById('btn-add-material')?.addEventListener('click', function () {
        document.getElementById('tabla-materiales').appendChild(crearFilaMaterial());
    });

    // ================================================================
    // INTERVENCIONES MÚLTIPLES + CÁLCULO IA
    // ================================================================

    const CSRF_TOKEN  = '<?= e(csrfToken()) ?>';
    const CALCULAR_URL = '<?= APP_URL_BASE ?>seguimiento/calcular_materiales.php';
    const TIPOS_OPCIONES = <?= json_encode(array_keys(segTiposConstruccion()), JSON_UNESCAPED_UNICODE) ?>;

    /** Crea una nueva fila de intervención (tipo + metraje + unidad + botones). */
    function crearFilaIntervencion() {
        const div = document.createElement('div');
        div.className = 'interv-row';
        div.style.cssText = 'display:grid;grid-template-columns:1.5fr 1fr 80px auto auto;gap:6px;align-items:center;margin-bottom:6px;';
        div.innerHTML = `
            <select class="form-control form-control-sm interv-tipo">
                <option value="">— Tipo de construcción —</option>
                ${TIPOS_OPCIONES.map(t => `<option value="${t}">${t}</option>`).join('')}
            </select>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm interv-metraje" placeholder="Metraje">
            <select class="form-control form-control-sm interv-unidad">
                <option value="m²">m²</option><option value="ml">ml</option>
                <option value="m³">m³</option><option value="und">und</option>
            </select>
            <button type="button" class="btn btn-primary btn-sm btn-calcular-interv" title="Calcular materiales para esta intervención">
                <i class="bi bi-stars"></i> Calcular
            </button>
            <button type="button" class="btn btn-outline btn-sm btn-quitar-interv" title="Quitar esta fila">
                <i class="bi bi-x-lg"></i>
            </button>`;
        return div;
    }

    // Agregar nueva fila de intervención.
    document.getElementById('btn-add-intervencion')?.addEventListener('click', function () {
        const lista = document.getElementById('lista-intervenciones');
        const fila  = crearFilaIntervencion();
        lista.appendChild(fila);
        // Mostrar botón quitar en TODAS las filas cuando hay más de una.
        actualizarBotonesQuitar();
    });

    /** Muestra/oculta los botones "x" según cuántas filas haya. */
    function actualizarBotonesQuitar() {
        const filas = document.querySelectorAll('#lista-intervenciones .interv-row');
        filas.forEach(f => {
            const btn = f.querySelector('.btn-quitar-interv');
            if (btn) btn.style.display = filas.length > 1 ? '' : 'none';
        });
    }

    // Delegar eventos en la lista de intervenciones.
    document.getElementById('lista-intervenciones')?.addEventListener('click', async function (e) {
        const btn = e.target.closest('button');
        if (!btn) return;

        // ---- Quitar fila ----
        if (btn.classList.contains('btn-quitar-interv')) {
            btn.closest('.interv-row').remove();
            actualizarBotonesQuitar();
            sincronizarCamposPrincipales();
            return;
        }

        // ---- Calcular con IA ----
        if (!btn.classList.contains('btn-calcular-interv')) return;

        const fila    = btn.closest('.interv-row');
        const tipo    = fila.querySelector('.interv-tipo')?.value;
        const metraje = parseFloat(fila.querySelector('.interv-metraje')?.value);
        const unidad  = fila.querySelector('.interv-unidad')?.value || 'm²';
        const estado  = document.getElementById('ia-estado');

        if (!tipo) {
            mostrarEstado('error', 'Seleccione el tipo de construcción en esa fila.'); return;
        }
        if (!metraje || metraje <= 0) {
            mostrarEstado('error', 'Ingrese el metraje para esa intervención.'); return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .7s linear infinite;"></span>';
        mostrarEstado('info', `Calculando materiales para <strong>${tipo} (${metraje} ${unidad})</strong> con IA…`);

        try {
            const fd = new FormData();
            fd.append('csrf', CSRF_TOKEN);
            fd.append('tipo_construccion', tipo);
            fd.append('metraje', metraje);
            fd.append('unidad', unidad);
            const res  = await fetch(CALCULAR_URL, { method:'POST', body:fd, credentials:'same-origin' });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en el cálculo');

            // Acumular los materiales: si ya existe la misma categoría+subtipo, SUMAR la cantidad.
            acumularMateriales(data.materiales);

            const fuente = data.fuente === 'ia' ? '✦ IA (Claude)' : 'tabla COVENIN';
            mostrarEstado('ok',
                `<strong>${tipo} · ${metraje} ${unidad}</strong>: ${data.materiales.length} materiales añadidos (${fuente}).`
                + (data.nota ? `<br><span class="text-muted" style="font-size:11px;">${data.nota}</span>` : '')
            );
            // Actualizar campos ocultos principales.
            sincronizarCamposPrincipales();
        } catch(err) {
            mostrarEstado('error', `Error al calcular: ${err.message}`);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-stars"></i> Calcular';
        }
    });

    /**
     * Acumula materiales de la IA en la tabla:
     * - Si ya existe la misma categoría+subtipo → suma la cantidad.
     * - Si es nuevo → agrega una fila nueva.
     */
    function acumularMateriales(nuevos) {
        const tabla = document.getElementById('tabla-materiales');
        nuevos.forEach(m => {
            // Buscar fila existente con la misma cat+subtipo.
            let encontrado = false;
            tabla.querySelectorAll('.seg-material-row').forEach(fila => {
                const catSel = fila.querySelector('.seg-mat-cat');
                const subSel = fila.querySelector('.seg-mat-sub');
                const cantInp = fila.querySelector('input[name="mat_cantidad[]"]');
                if (!catSel || !cantInp) return;
                const mismacat = catSel.value === m.categoria;
                const mismosub = !m.subtipo || !subSel || subSel.value === m.subtipo || subSel.value === '';
                if (mismacat && mismosub && !encontrado) {
                    // Sumar cantidad.
                    const actual = parseFloat(cantInp.value) || 0;
                    cantInp.value = (actual + m.cantidad).toFixed(2).replace(/\.?0+$/, '');
                    encontrado = true;
                }
            });
            if (!encontrado) {
                // Agregar fila nueva.
                const fila = crearFilaMaterial();
                const selCat  = fila.querySelector('.seg-mat-cat');
                const selUni  = fila.querySelector('select[name="mat_unidad[]"]');
                const inpCant = fila.querySelector('input[name="mat_cantidad[]"]');
                if (selCat) { selCat.value = m.categoria; actualizarSubtipos(selCat); }
                if (inpCant) inpCant.value = m.cantidad;
                if (selUni && m.unidad) selUni.value = m.unidad;
                // Subtipo — después de que se poblen las opciones.
                if (m.subtipo) {
                    setTimeout(() => {
                        const sub = fila.querySelector('.seg-mat-sub');
                        if (sub) {
                            const opt = Array.from(sub.options).find(o => o.value === m.subtipo || o.value.startsWith(m.subtipo.split(' ')[0]));
                            if (opt) sub.value = opt.value;
                        }
                    }, 60);
                }
                tabla.appendChild(fila);
            }
        });
    }

    /** Sincroniza los campos hidden principales con la primera fila de intervenciones. */
    function sincronizarCamposPrincipales() {
        const primera = document.querySelector('#lista-intervenciones .interv-row');
        if (!primera) return;
        const t = document.getElementById('campo-tipo-principal');
        const m = document.getElementById('campo-metraje-principal');
        const u = document.getElementById('campo-unidad-principal');
        if (t) t.value = primera.querySelector('.interv-tipo')?.value || '';
        if (m) m.value = primera.querySelector('.interv-metraje')?.value || '';
        if (u) u.value = primera.querySelector('.interv-unidad')?.value || 'm²';
    }

    // Sincronizar al enviar el formulario.
    document.getElementById('form-plan-completo')?.addEventListener('submit', sincronizarCamposPrincipales);

    function mostrarEstado(tipo, html) {
        const el = document.getElementById('ia-estado');
        if (!el) return;
        el.style.display = '';
        const colores = { ok:'#1c6b3d', error:'#a61c1c', info:'#22366f' };
        const iconos  = { ok:'bi-check-circle-fill', error:'bi-exclamation-triangle', info:'bi-hourglass-split' };
        el.innerHTML = `<span style="color:${colores[tipo]};"><i class="bi ${iconos[tipo]}"></i> ${html}</span>`;
    }

    // CSS spinner.
    if (!document.getElementById('spinner-css')) {
        const s = document.createElement('style');
        s.id = 'spinner-css';
        s.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
        document.head.appendChild(s);
    }

    // Inicializar: mostrar la primera fila sin botón quitar.
    actualizarBotonesQuitar();

    // Inicializar subtipos de filas ya existentes.
    document.querySelectorAll('.seg-mat-cat').forEach(sel => {
        sel.addEventListener('change', function () { actualizarSubtipos(this); });
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
