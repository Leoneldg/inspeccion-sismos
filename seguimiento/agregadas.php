<?php
/**
 * EDIFICACIONES AGREGADAS EN CAMPO.
 *
 * Las que no estaban en el listado original y se registraron durante el
 * trabajo. Muestra su estado de etiqueta, clasificación y quién las
 * registró, con opción de informe en PDF.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$lista = segEdificacionesAgregadas();
$cat   = catalogoDecisionFinal();

// Resumen para las tarjetas de arriba.
$porColor = ['amarillo' => 0, 'rojo' => 0, 'verde' => 0, 'derrumbado' => 0];
$sinEtq = 0; $conFoto = 0; $porParroquia = [];
foreach ($lista as $e) {
    $sim = recSimboloDecision($e['decision_final'] ?? null);
    $k = mb_strtolower($sim['texto'], 'UTF-8');
    if (isset($porColor[$k])) $porColor[$k]++;
    if (!empty($e['sin_etiqueta'])) $sinEtq++;
    if ((int)($e['fotos_etiqueta'] ?? 0) > 0) $conFoto++;
    $p = $e['parroquia'] ?: 'Sin parroquia';
    $porParroquia[$p] = ($porParroquia[$p] ?? 0) + 1;
}
arsort($porParroquia);

$pageTitle    = 'Edificaciones agregadas en campo';
$pageSubtitle = count($lista) . ' registradas fuera del listado original';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.ag-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.ag-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.ag-kpis { display:flex; gap:9px; flex-wrap:wrap; }
.ag-k { flex:1; min-width:112px; text-align:center; padding:13px 8px; border-radius:10px; border:1px solid; }
.ag-k .n { font-size:26px; font-weight:800; line-height:1; }
.ag-k .l { font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; color:#55617f; margin-top:4px; }
.ag-fila { display:flex; align-items:center; gap:12px; padding:12px 8px; border-bottom:1px solid #f0f2f7; }
.ag-fila:last-child { border-bottom:0; }
.ag-fila:hover { background:#fafbfe; }
.clas-letra { display:inline-flex; align-items:center; justify-content:center;
              width:24px; height:24px; border:2px solid; border-radius:5px;
              font-weight:800; font-size:12px; flex-shrink:0; }
.ag-chip { font-size:10.5px; padding:2px 8px; border-radius:10px; white-space:nowrap; }
@media (max-width: 640px) {
    .ag-fila { flex-wrap:wrap; }
    .ag-fila > div:nth-child(2) { flex:1 1 100%; }
}
</style>

<?php if (!$lista): ?>
<?php
// Diagnóstico: si hay registros en la bitácora pero la lista sale vacía,
// es porque el alcance del usuario los filtra o la inspección se borró.
$enBitacora = 0; $sinInspeccion = 0;
try {
    $enBitacora = (int)db()->query(
        "SELECT COUNT(*) FROM rec_auditoria WHERE accion = 'edificacion_agregada'"
    )->fetchColumn();
    $sinInspeccion = (int)db()->query(
        "SELECT COUNT(*) FROM rec_auditoria a
           LEFT JOIN inspecciones i ON i.id = a.inspeccion_id
          WHERE a.accion = 'edificacion_agregada' AND i.id IS NULL"
    )->fetchColumn();
} catch (Throwable $e) {}
?>
<div class="ag-card" style="text-align:center;padding:38px 20px;">
    <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-inbox"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">No hay edificaciones agregadas que mostrar</h3>

    <?php if ($enBitacora === 0): ?>
    <p class="text-muted" style="margin:0 0 16px;">
        Aquí aparecerán las que se registren en campo y no estaban en el listado original.
    </p>
    <?php else: ?>
    <div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;
                padding:12px 15px;margin:12px auto 16px;max-width:520px;text-align:left;
                font-size:13px;color:#8a6d1a;">
        <strong><i class="bi bi-info-circle-fill"></i> Hay <?= $enBitacora ?> registro(s) en la bitácora,
        pero no se muestran aquí.</strong>
        <div style="margin-top:6px;">
            <?php if ($sinInspeccion > 0): ?>
                <?= $sinInspeccion ?> corresponden a edificaciones que fueron eliminadas.
            <?php endif; ?>
            <?php if (usuarioLimitadoAParroquia()): ?>
                Su usuario solo ve las parroquias que tiene asignadas
                (<?= e(implode(', ', parroquiasDelUsuario())) ?>).
                Puede que las agregadas estén en otra parroquia.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <a href="<?= APP_URL_BASE ?>seguimiento/nueva_edificacion.php" class="btn btn-primary">
        <i class="bi bi-plus-circle-fill"></i> Agregar edificación
    </a>
</div>

<?php else: ?>

<!-- Resumen -->
<div class="ag-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div class="ag-tit" style="margin:0;">
            <i class="bi bi-plus-circle-fill" style="color:#2E7D32;"></i>
            <?= count($lista) ?> edificaciones agregadas en campo
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= APP_URL_BASE ?>seguimiento/pdf_agregadas.php" target="_blank" class="btn btn-primary btn-sm">
                <i class="bi bi-file-earmark-pdf-fill"></i> Informe PDF
            </a>
            <a href="<?= APP_URL_BASE ?>seguimiento/nueva_edificacion.php" class="btn btn-outline btn-sm">
                <i class="bi bi-plus-circle"></i> Agregar otra
            </a>
        </div>
    </div>

    <div class="ag-kpis">
        <div class="ag-k" style="border-color:#C9A22755;background:#C9A2270d;">
            <div class="n" style="color:#a8871f;"><?= $porColor['amarillo'] ?></div><div class="l">A · Amarillo</div>
        </div>
        <div class="ag-k" style="border-color:#A61C1C55;background:#A61C1C0d;">
            <div class="n" style="color:#A61C1C;"><?= $porColor['rojo'] ?></div><div class="l">R · Rojo</div>
        </div>
        <div class="ag-k" style="border-color:#2E7D3255;background:#2E7D320d;">
            <div class="n" style="color:#2E7D32;"><?= $porColor['verde'] ?></div><div class="l">V · Verde</div>
        </div>
        <div class="ag-k" style="border-color:#2B2B2B55;background:#2B2B2B0d;">
            <div class="n" style="color:#2B2B2B;"><?= $porColor['derrumbado'] ?></div><div class="l">D · Derrumbado</div>
        </div>
    </div>

    <div class="ag-kpis" style="margin-top:9px;">
        <div class="ag-k" style="border-color:#2E7D3255;">
            <div class="n" style="color:#2E7D32;"><?= $conFoto ?></div><div class="l">Con foto de etiqueta</div>
        </div>
        <div class="ag-k" style="border-color:#C9A22755;">
            <div class="n" style="color:#a8871f;"><?= $sinEtq ?></div><div class="l">Sin etiqueta</div>
        </div>
        <div class="ag-k" style="border-color:#97a0b855;">
            <div class="n" style="color:#5b6478;"><?= count($lista) - $conFoto - $sinEtq ?></div>
            <div class="l">Etiqueta pendiente</div>
        </div>
        <div class="ag-k" style="border-color:#2d448855;">
            <div class="n" style="color:#2d4488;"><?= count($porParroquia) ?></div><div class="l">Parroquias</div>
        </div>
    </div>
</div>

<!-- Por parroquia -->
<?php if (count($porParroquia) > 1): ?>
<div class="ag-card">
    <div class="ag-tit"><i class="bi bi-geo-alt-fill"></i> Por parroquia</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($porParroquia as $parr => $n): ?>
        <span style="background:#eef2fb;color:#22366F;border-radius:20px;padding:6px 14px;
                     font-size:13px;font-weight:600;">
            <?= e($parr) ?> <strong><?= $n ?></strong>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Listado -->
<div class="ag-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
        <div class="ag-tit" style="margin:0;"><i class="bi bi-list-ul"></i> Detalle</div>
        <input type="text" id="ag-buscar" class="form-control" style="width:230px;"
               placeholder="Buscar…" oninput="filtrarAgregadas()">
    </div>

    <div id="ag-lista">
    <?php foreach ($lista as $e):
        $sim = recSimboloDecision($e['decision_final'] ?? null);
        $tieneFoto = (int)($e['fotos_etiqueta'] ?? 0) > 0;
        $sinEtiqueta = !empty($e['sin_etiqueta']);
    ?>
    <div class="ag-fila" data-txt="<?= e(mb_strtolower(
            ($e['nombre_edificio'] ?? '') . ' ' . ($e['codigo'] ?? '') . ' ' .
            ($e['parroquia'] ?? '') . ' ' . ($e['registrada_por'] ?? ''), 'UTF-8')) ?>">

        <span class="clas-letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"
              title="<?= e($sim['texto']) ?>"><?= $sim['letra'] ?></span>

        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#2a3140;font-size:14px;">
                <?= e($e['nombre_edificio'] ?: 'Sin nombre') ?>
            </div>
            <div style="font-size:11.5px;color:#5b6478;">
                <?= e($e['codigo']) ?> · <?= e($e['parroquia'] ?: '—') ?>
                <?php if (!empty($e['direccion'])): ?>
                    · <?= e(mb_strimwidth($e['direccion'], 0, 46, '…', 'UTF-8')) ?>
                <?php endif; ?>
            </div>
            <div style="font-size:11px;color:#767c94;margin-top:2px;">
                <i class="bi bi-person"></i> <?= e($e['registrada_por'] ?: 'Sin registro') ?>
                <?php if (!empty($e['registrada_en'])): ?>
                    · <?= date('d/m/Y H:i', strtotime($e['registrada_en'])) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estado de la etiqueta -->
        <?php if ($tieneFoto): ?>
            <span class="ag-chip" style="background:#2E7D3218;color:#2E7D32;">
                <i class="bi bi-camera-fill"></i> Con etiqueta
            </span>
        <?php elseif ($sinEtiqueta): ?>
            <span class="ag-chip" style="background:#C9A22722;color:#8a6d1a;"
                  title="<?= e($e['etiqueta_motivo'] ?? '') ?>">
                <i class="bi bi-tag"></i> Sin etiqueta
            </span>
        <?php else: ?>
            <span class="ag-chip" style="background:#f1f2f6;color:#5b6478;">
                <i class="bi bi-hourglass"></i> Pendiente
            </span>
        <?php endif; ?>

        <!-- Estado del levantamiento -->
        <?php if (!empty($e['completado'])): ?>
            <span class="ag-chip" style="background:#2E7D3218;color:#2E7D32;">Levantamiento listo</span>
        <?php else: ?>
            <span class="ag-chip" style="background:#f1f2f6;color:#5b6478;">Sin levantamiento</span>
        <?php endif; ?>

        <a href="<?= APP_URL_BASE ?>seguimiento/index.php?abrir=<?= (int)$e['id'] ?>"
           class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
    </div>
    <p id="ag-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
        Ninguna coincide con la búsqueda.
    </p>
</div>

<script>
function filtrarAgregadas() {
    const t = (document.getElementById('ag-buscar').value || '').toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('.ag-fila').forEach(f => {
        const ver = !t || (f.dataset.txt || '').includes(t);
        f.style.display = ver ? '' : 'none';
        if (ver) n++;
    });
    document.getElementById('ag-vacio').style.display = n ? 'none' : '';
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
