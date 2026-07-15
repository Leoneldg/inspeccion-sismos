<?php
/**
 * Limpieza de inspecciones: filtra por fecha, permite seleccionar varias
 * y borrarlas. Pensado para eliminar datos de prueba.
 * Solo accesible para el superadministrador (master) con permiso eliminar.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('import_export', 'ver');

$puedeBorrar = usuarioEsMaster() && puede('import_export', 'eliminar');

// --- Filtros de fecha ---
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');
$campo = ($_GET['campo'] ?? 'fecha_inspeccion') === 'creado_en' ? 'creado_en' : 'fecha_inspeccion';

$condiciones = [];
$params = [];
if ($desde !== '') {
    $condiciones[] = ($campo === 'creado_en' ? 'DATE(i.creado_en)' : 'i.fecha_inspeccion') . ' >= :desde';
    $params['desde'] = $desde;
}
if ($hasta !== '') {
    $condiciones[] = ($campo === 'creado_en' ? 'DATE(i.creado_en)' : 'i.fecha_inspeccion') . ' <= :hasta';
    $params['hasta'] = $hasta;
}
$whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

// Solo se consultan resultados si el usuario aplicó algún filtro (evita cargar
// toda la tabla de golpe por accidente).
$hayFiltro = ($desde !== '' || $hasta !== '');
$filas = [];
if ($hayFiltro) {
    $stmt = db()->prepare("
        SELECT i.id, i.codigo, i.nombre_edificio, i.estado, i.municipio, i.parroquia,
               i.fecha_inspeccion, i.creado_en, i.decision_final,
               (SELECT COUNT(*) FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id) AS fotos
        FROM inspecciones i
        $whereSql
        ORDER BY i.creado_en DESC, i.id DESC
    ");
    $stmt->execute($params);
    $filas = $stmt->fetchAll();
}

$catalogo = catalogoDecisionFinal();

$pageTitle = 'Limpiar inspecciones';
$pageSubtitle = 'Filtrar por fecha y eliminar inspecciones (datos de prueba)';
$activeModule = 'import_export';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .li-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    .li-tabla { width:100%; border-collapse:collapse; margin-top:6px; }
    .li-tabla th, .li-tabla td { padding:7px 9px; font-size:12.5px; border-bottom:1px solid #eef0f5; text-align:left; }
    .li-tabla th { background:#f6f7fb; color:#55617f; font-size:11px; text-transform:uppercase; letter-spacing:.4px; }
    .li-tabla tr:hover { background:#fafbff; }
    .li-badge { display:inline-block; padding:2px 8px; border-radius:12px; color:#fff; font-size:10.5px; font-weight:bold; }
    .li-selbar { position:sticky; bottom:0; background:#fff; border-top:2px solid #e2e6ef; padding:12px 4px;
                 display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:8px; }
    .li-danger { background:#A61C1C; }
    .li-count { font-weight:bold; color:#22366F; }
    .li-warn { background:#fdeaea; border:1px solid #f3c2c2; color:#8a1c1c; padding:10px 12px; border-radius:8px; font-size:13px; }
    code.mono { font-family:var(--font-mono, monospace); font-size:11px; color:#767c94; }
</style>

<?php if (!$puedeBorrar): ?>
    <div class="li-warn" style="margin-bottom:14px;">
        <i class="bi bi-shield-lock"></i> Puede consultar, pero solo el <strong>superadministrador</strong> con permiso de eliminación puede borrar inspecciones.
    </div>
<?php endif; ?>

<!-- Filtro de fecha -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-body">
        <form method="get" class="li-toolbar">
            <div class="field" style="margin:0;">
                <label class="text-sm">Filtrar por</label>
                <select name="campo" class="form-control" style="width:190px;">
                    <option value="fecha_inspeccion" <?= $campo === 'fecha_inspeccion' ? 'selected' : '' ?>>Fecha de inspección</option>
                    <option value="creado_en" <?= $campo === 'creado_en' ? 'selected' : '' ?>>Fecha de carga al sistema</option>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?= e($desde) ?>" style="width:170px;">
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?= e($hasta) ?>" style="width:170px;">
            </div>
            <button class="btn btn-primary"><i class="bi bi-search"></i> Buscar</button>
            <a href="<?= APP_URL_BASE ?>dashboard/limpiar_inspecciones.php" class="btn btn-outline">Limpiar filtro</a>
        </form>
        <p class="text-muted" style="margin:8px 0 0;font-size:12px;">
            <i class="bi bi-info-circle"></i> Tip: para borrar lo que cargó hoy, filtre por «Fecha de carga al sistema» con Desde y Hasta = hoy.
        </p>
    </div>
</div>

<?php if (!$hayFiltro): ?>
    <div class="empty-state" style="text-align:center;color:#8a93a8;padding:40px;">
        <i class="bi bi-calendar-range" style="font-size:34px;"></i>
        <p>Seleccione un rango de fechas y pulse «Buscar» para ver las inspecciones.</p>
    </div>
<?php else: ?>

    <form method="post" action="<?= APP_URL_BASE ?>dashboard/borrar_inspecciones.php" id="form-borrar"
          onsubmit="return confirmarBorrado();">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0;"><i class="bi bi-list-check"></i> Resultados (<?= count($filas) ?>)</h2>
                <?php if ($filas && $puedeBorrar): ?>
                    <label class="text-sm" style="cursor:pointer;">
                        <input type="checkbox" id="chk-todos"> Seleccionar todas
                    </label>
                <?php endif; ?>
            </div>
            <div class="card-body" style="overflow-x:auto;">
                <?php if (!$filas): ?>
                    <div class="empty-state" style="text-align:center;color:#8a93a8;padding:24px;">
                        No hay inspecciones en ese rango de fechas.
                    </div>
                <?php else: ?>
                    <table class="li-tabla">
                        <thead>
                            <tr>
                                <?php if ($puedeBorrar): ?><th style="width:34px;"></th><?php endif; ?>
                                <th>Código</th>
                                <th>Edificio</th>
                                <th>Ubicación</th>
                                <th>Decisión</th>
                                <th>F. inspección</th>
                                <th>Cargado</th>
                                <th>Fotos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas as $f):
                                $meta = $catalogo[$f['decision_final']] ?? ['color' => '#767c94', 'corto' => '—'];
                                $ubic = array_filter([$f['parroquia'], $f['municipio'], $f['estado']]);
                            ?>
                                <tr>
                                    <?php if ($puedeBorrar): ?>
                                        <td><input type="checkbox" class="chk-fila" name="ids[]" value="<?= (int)$f['id'] ?>"></td>
                                    <?php endif; ?>
                                    <td><code class="mono"><?= e($f['codigo']) ?></code></td>
                                    <td><?= e($f['nombre_edificio']) ?></td>
                                    <td style="font-size:11.5px;color:#667;"><?= e($ubic ? implode(', ', $ubic) : '—') ?></td>
                                    <td><span class="li-badge" style="background:<?= $meta['color'] ?>;"><?= e($meta['corto']) ?></span></td>
                                    <td><?= e($f['fecha_inspeccion'] ?: '—') ?></td>
                                    <td style="font-size:11.5px;color:#667;"><?= e($f['creado_en'] ?: '—') ?></td>
                                    <td style="text-align:center;"><?= (int)$f['fotos'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($filas && $puedeBorrar): ?>
            <div class="li-selbar">
                <span class="li-count"><span id="n-sel">0</span> seleccionada(s)</span>
                <div style="flex:1;"></div>
                <label class="text-sm" style="display:flex;align-items:center;gap:6px;">
                    Escriba <strong>BORRAR</strong> para confirmar:
                    <input type="text" name="confirmacion" id="txt-confirmar" class="form-control"
                           style="width:130px;" placeholder="BORRAR" autocomplete="off">
                </label>
                <button type="submit" class="btn li-danger" id="btn-borrar" disabled>
                    <i class="bi bi-trash3"></i> Borrar seleccionadas
                </button>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($puedeBorrar): ?>
    <script>
        const chkTodos = document.getElementById('chk-todos');
        const filas = Array.from(document.querySelectorAll('.chk-fila'));
        const nSel = document.getElementById('n-sel');
        const btn = document.getElementById('btn-borrar');
        const txt = document.getElementById('txt-confirmar');

        function refrescar() {
            const n = filas.filter(c => c.checked).length;
            nSel.textContent = n;
            // El botón se habilita solo si hay selección Y se escribió BORRAR.
            btn.disabled = !(n > 0 && txt.value.trim().toUpperCase() === 'BORRAR');
            if (chkTodos) chkTodos.checked = n > 0 && n === filas.length;
        }

        filas.forEach(c => c.addEventListener('change', refrescar));
        if (chkTodos) chkTodos.addEventListener('change', () => {
            filas.forEach(c => c.checked = chkTodos.checked);
            refrescar();
        });
        txt.addEventListener('input', refrescar);

        function confirmarBorrado() {
            const n = filas.filter(c => c.checked).length;
            if (n === 0) { alert('No seleccionó ninguna inspección.'); return false; }
            return confirm('¿Borrar ' + n + ' inspección(es)? Esta acción NO se puede deshacer.\n\n'
                         + 'Se eliminarán también sus fotos y datos de seguimiento.');
        }
        refrescar();
    </script>
    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
