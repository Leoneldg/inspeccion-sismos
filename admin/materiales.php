<?php
/**
 * MATERIALES Y RENDIMIENTOS.
 *
 * Permite revisar y ajustar cuánto material consume cada tipo de trabajo.
 * Los rendimientos son referenciales: un ingeniero puede corregirlos
 * según la experiencia real de la obra, sin tocar el código.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('configuracion', 'ver');
$puedeEditar = puede('configuracion', 'editar');

recAsegurarTablasTrabajo();

$mensaje = null;

// --- Guardar cambios ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar) {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Intente de nuevo.');
        header('Location: ' . APP_URL_BASE . 'admin/materiales.php');
        exit;
    }
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'actualizar') {
            $cantidades = $_POST['cantidad'] ?? [];
            $etapas     = $_POST['etapa'] ?? [];
            $etapasValidas = ['demolicion', 'construccion', 'revestimiento'];
            $n = 0;
            $st = db()->prepare('UPDATE rec_receta_trabajo SET cantidad = :c, etapa = :e WHERE id = :id');
            foreach ($cantidades as $id => $val) {
                $val = (float)str_replace(',', '.', $val);
                if ($val < 0) continue;
                $et = $etapas[$id] ?? '';
                $et = in_array($et, $etapasValidas, true) ? $et : null;
                $st->execute(['c' => $val, 'e' => $et, 'id' => (int)$id]);
                $n++;
            }
            $mensaje = $n . ' rendimiento(s) actualizado(s).';
            registrarLog($_SESSION['user_id'] ?? null, 'recetas_actualizadas', $n . ' materiales');
        }

        if ($accion === 'agregar') {
            $trabajo = trim($_POST['nuevo_trabajo'] ?? '');
            $mat     = trim($_POST['nuevo_material'] ?? '');
            $uni     = trim($_POST['nueva_unidad'] ?? '');
            $cant    = (float)str_replace(',', '.', $_POST['nueva_cantidad'] ?? '0');
            $etapasValidas = ['demolicion', 'construccion', 'revestimiento'];
            $eta     = trim($_POST['nueva_etapa'] ?? '');
            $eta     = in_array($eta, $etapasValidas, true) ? $eta : null;
            if ($trabajo && $mat && $uni && $cant > 0) {
                db()->prepare(
                    'INSERT INTO rec_receta_trabajo (tipo_trabajo, material, unidad, cantidad, nota, etapa)
                     VALUES (:t, :m, :u, :c, :n, :e)'
                )->execute([
                    't' => $trabajo, 'm' => $mat, 'u' => $uni, 'c' => $cant,
                    'n' => trim($_POST['nueva_nota'] ?? '') ?: null,
                    'e' => $eta,
                ]);
                $mensaje = 'Material agregado.';
            } else {
                $mensaje = 'Complete todos los campos para agregar el material.';
            }
        }

        if ($accion === 'quitar') {
            $id = (int)($_POST['receta_id'] ?? 0);
            if ($id > 0) {
                db()->prepare('UPDATE rec_receta_trabajo SET activo = 0 WHERE id = :id')
                    ->execute(['id' => $id]);
                $mensaje = 'Material retirado de la receta.';
            }
        }
    } catch (Throwable $e) {
        $mensaje = APP_DEBUG ? $e->getMessage() : 'No se pudo guardar el cambio.';
    }
}

$tipos   = recTiposTrabajo();
$recetas = recRecetasTrabajo();

// Unidades disponibles, para el selector.
$unidades = ['saco', 'm3', 'm2', 'kg', 'litro', 'unidad', 'pieza', 'pliego'];

$pageTitle    = 'Materiales y rendimientos';
$pageSubtitle = count($tipos) . ' tipos de trabajo';
$activeModule = 'configuracion';
include __DIR__ . '/../includes/header.php';
?>
<style>
.mt-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           margin-bottom:16px; overflow:hidden; }
.mt-cab { background:#f7f9fd; padding:13px 18px; border-bottom:1px solid #eef0f5; }
.mt-tit { font-weight:700; color:#22366F; font-size:15px; }
.mt-desc { font-size:12.5px; color:#5b6478; margin-top:3px; }
.mt-body { padding:14px 18px; }
.mt-fila { display:flex; align-items:center; gap:10px; padding:9px 4px;
           border-bottom:1px solid #f4f6fa; flex-wrap:wrap; }
.mt-fila:last-child { border-bottom:0; }
.mt-mat { flex:1; min-width:160px; font-size:13.5px; color:#2a3140; font-weight:600; }
.mt-nota { font-size:11px; color:#767c94; font-weight:400; display:block; }
.mt-cant { width:110px; text-align:right; }
.mt-uni { width:70px; font-size:12.5px; color:#5b6478; }
.mt-unidad-trabajo { background:#eef2fb; color:#22366F; border-radius:7px;
                     padding:3px 9px; font-size:11.5px; font-weight:700; }
@media (max-width: 640px) {
    .mt-fila { gap:7px; }
    .mt-mat { flex:1 1 100%; }
}
</style>

<?php if ($mensaje): ?>
<div class="alert alert-success" style="margin-bottom:14px;">
    <i class="bi bi-check-circle-fill"></i><div><?= e($mensaje) ?></div>
</div>
<?php endif; ?>

<div class="mt-card">
    <div class="mt-body" style="background:#eef2fb;">
        <strong style="color:#22366F;"><i class="bi bi-info-circle-fill"></i> Cómo funciona</strong>
        <div style="font-size:12.5px;color:#55617f;margin-top:5px;line-height:1.7;">
            Cada cantidad es <strong>por unidad de trabajo</strong>: por m² en la mayoría,
            por m³ en vaciados y demoliciones estructurales.<br>
            Ejemplo: si el friso completo consume <code>0,155</code> sacos de cemento por m²,
            entonces 100 m² de friso necesitan 15,5 sacos.<br>
            Los rendimientos vienen de valores usuales de obra. Ajústelos según la
            experiencia real del equipo.<br>
            La <strong>etapa</strong> (demolición/construcción/revestimiento) es la que
            usa el Resumen Ejecutivo en PDF para agrupar el material por caja: un
            material "Sin clasificar" no aparece sumado en ese resumen aunque sí
            aparezca en la ficha del edificio, así que clasifique todos los renglones.
        </div>
    </div>
</div>

<?php if ($puedeEditar): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
<input type="hidden" name="accion" value="actualizar">
<?php endif; ?>

<?php foreach ($tipos as $t):
    $lista = $recetas[$t['clave']] ?? [];
?>
<div class="mt-card">
    <div class="mt-cab">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div class="mt-tit"><?= e($t['nombre']) ?></div>
                <?php if (!empty($t['descripcion'])): ?>
                <div class="mt-desc"><?= e($t['descripcion']) ?></div>
                <?php endif; ?>
            </div>
            <span class="mt-unidad-trabajo">
                por <?= $t['unidad'] === 'm3' ? 'm³' : 'm²' ?>
            </span>
        </div>
    </div>

    <div class="mt-body">
        <?php if (!$lista): ?>
        <p class="text-muted" style="margin:0;font-size:13px;">
            Sin materiales definidos. Este trabajo no generará cálculo.
        </p>
        <?php else: ?>
        <?php foreach ($lista as $r): ?>
        <div class="mt-fila">
            <div class="mt-mat">
                <?= e($r['material']) ?>
                <?php if (!empty($r['nota'])): ?>
                <span class="mt-nota"><?= e($r['nota']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($puedeEditar): ?>
            <input type="number" step="0.0001" min="0" class="form-control mt-cant"
                   name="cantidad[<?= (int)$r['id'] ?>]" value="<?= (float)$r['cantidad'] ?>">
            <?php else: ?>
            <span class="mt-cant" style="font-weight:700;color:#22366F;"><?= (float)$r['cantidad'] ?></span>
            <?php endif; ?>
            <span class="mt-uni"><?= e($r['unidad']) ?></span>
            <?php if ($puedeEditar): ?>
            <select name="etapa[<?= (int)$r['id'] ?>]" class="form-control"
                    style="width:135px;<?= empty($r['etapa']) ? 'border-color:#C9A227;' : '' ?>">
                <option value="">Sin clasificar</option>
                <option value="demolicion"    <?= $r['etapa'] === 'demolicion'    ? 'selected' : '' ?>>Demolición</option>
                <option value="construccion"  <?= $r['etapa'] === 'construccion'  ? 'selected' : '' ?>>Construcción</option>
                <option value="revestimiento" <?= $r['etapa'] === 'revestimiento' ? 'selected' : '' ?>>Revestimiento</option>
            </select>
            <?php else: ?>
            <span class="mt-uni">
                <?= $r['etapa'] ? e(ucfirst($r['etapa'])) : '— sin clasificar' ?>
            </span>
            <?php endif; ?>
            <?php if ($puedeEditar): ?>
            <button type="submit" form="quitar-<?= (int)$r['id'] ?>"
                    title="Quitar este material"
                    style="background:transparent;border:0;color:#c4c9d6;cursor:pointer;font-size:15px;">
                <i class="bi bi-x-circle"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <details style="margin-top:11px;">
            <summary style="font-size:12.5px;color:#2d4488;cursor:pointer;">
                <i class="bi bi-plus-circle"></i> Agregar un material a este trabajo
            </summary>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:9px;
                        background:#f7f9fd;border-radius:9px;padding:11px;">
                <input type="text" name="nuevo_material" form="agregar-<?= e($t['clave']) ?>"
                       class="form-control" style="flex:2;min-width:150px;" placeholder="Material">
                <select name="nueva_unidad" form="agregar-<?= e($t['clave']) ?>"
                        class="form-control" style="width:100px;">
                    <?php foreach ($unidades as $u): ?>
                    <option value="<?= $u ?>"><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" step="0.0001" min="0" name="nueva_cantidad"
                       form="agregar-<?= e($t['clave']) ?>"
                       class="form-control" style="width:110px;" placeholder="Cantidad">
                <select name="nueva_etapa" form="agregar-<?= e($t['clave']) ?>"
                        class="form-control" style="width:135px;">
                    <option value="">Sin clasificar</option>
                    <option value="demolicion">Demolición</option>
                    <option value="construccion">Construcción</option>
                    <option value="revestimiento">Revestimiento</option>
                </select>
                <input type="text" name="nueva_nota" form="agregar-<?= e($t['clave']) ?>"
                       class="form-control" style="flex:1;min-width:130px;" placeholder="Nota (opcional)">
                <button type="submit" form="agregar-<?= e($t['clave']) ?>" class="btn btn-outline btn-sm">
                    <i class="bi bi-plus-lg"></i> Agregar
                </button>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if ($puedeEditar): ?>
<div style="position:sticky;bottom:0;background:#fff;padding:14px 18px;border-radius:12px;
            box-shadow:0 -2px 12px rgba(20,30,60,.10);margin-bottom:20px;
            display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div style="flex:1;min-width:180px;font-size:12.5px;color:#5b6478;">
        Los cambios afectan a los cálculos futuros. Las fichas ya generadas
        se recalculan al abrirlas.
    </div>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Guardar rendimientos
    </button>
</div>
</form>

<!-- Formularios auxiliares -->
<?php foreach ($tipos as $t): ?>
<form method="post" id="agregar-<?= e($t['clave']) ?>" style="display:none;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="agregar">
    <input type="hidden" name="nuevo_trabajo" value="<?= e($t['clave']) ?>">
</form>
<?php endforeach; ?>

<?php foreach ($recetas as $lista): foreach ($lista as $r): ?>
<form method="post" id="quitar-<?= (int)$r['id'] ?>" style="display:none;"
      onsubmit="return confirm('¿Quitar <?= e(addslashes($r['material'])) ?> de esta receta?');">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="quitar">
    <input type="hidden" name="receta_id" value="<?= (int)$r['id'] ?>">
</form>
<?php endforeach; endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
