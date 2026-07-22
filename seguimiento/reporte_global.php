<?php
/**
 * REPORTE GLOBAL.
 *
 * Consolidado del programa por responsable de parroquia: cuántas
 * edificaciones lleva cada quien, cuántas levantó, apartamentos a
 * reparar y qué material necesita. Al final, los totales.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$c = segConsolidadoResponsables();
$resp = $c['responsables'];
$sinResp = $c['sin_responsable'];
$tot = $c['totales'];
$cif = $tot['cifras'] ?? [];

$pageTitle    = 'Reporte global';
$pageSubtitle = 'Consolidado por responsable de parroquia';
$activeModule = 'reporte_global';
include __DIR__ . '/../includes/header.php';
?>
<style>
.gl-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           margin-bottom:16px; overflow:hidden; }
.gl-cab { padding:15px 20px; border-bottom:1px solid #eef0f5; }
.gl-body { padding:16px 20px; }

.gl-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; }
.gl-k { text-align:center; padding:14px 10px; border-radius:10px; border:1px solid; }
.gl-k .n { font-size:clamp(21px,2.8vw,29px); font-weight:800; line-height:1; }
.gl-k .l { font-size:10.5px; text-transform:uppercase; color:#55617f;
           margin-top:5px; line-height:1.3; }

.gl-resp { border:1px solid #e5e8f0; border-radius:11px; margin-bottom:13px;
           overflow:hidden; }
.gl-resp-cab { background:#f7f9fd; padding:12px 16px; display:flex;
               align-items:center; gap:12px; flex-wrap:wrap; }
.gl-avatar { width:40px; height:40px; border-radius:11px; background:#22366F;
             color:#fff; display:flex; align-items:center; justify-content:center;
             font-weight:800; font-size:16px; flex-shrink:0; }
.gl-cifras { display:flex; gap:16px; flex-wrap:wrap; padding:12px 16px;
             border-top:1px solid #eef0f5; }
.gl-cifra .v { font-size:19px; font-weight:800; color:#22366F; line-height:1; }
.gl-cifra .e { font-size:10.5px; color:#5b6478; text-transform:uppercase; }

.gl-parr { display:inline-block; background:#eef2fb; color:#22366F;
           border-radius:14px; padding:3px 11px; font-size:11.5px;
           font-weight:600; margin:2px 3px 2px 0; }
.gl-mat { display:flex; gap:7px; flex-wrap:wrap; }
.gl-mat span { background:#fff; border:1px solid #e5e8f0; border-radius:8px;
               padding:6px 11px; font-size:11.5px; }

@media print {
    .app-nav, .no-print { display:none !important; }
    .gl-card { box-shadow:none; border:1px solid #dbe0ec; page-break-inside:avoid; }
}
</style>

<!-- Totales del programa -->
<div class="gl-card">
    <div class="gl-cab" style="background:#22366F;color:#fff;border:0;">
        <div style="font-size:16px;font-weight:800;">
            <i class="bi bi-globe-americas"></i> TOTAL DEL PROGRAMA
        </div>
        <div style="font-size:12.5px;opacity:.9;">
            <?= (int)($tot['parroquias'] ?? 0) ?> parroquias ·
            <?= count($resp) ?> responsable(s)
        </div>
    </div>
    <div class="gl-body">
        <div class="gl-kpis">
            <div class="gl-k" style="border-color:#22366F33;background:#22366F0a;">
                <div class="n" style="color:#22366F;">
                    <?= number_format((int)($cif['edificaciones'] ?? 0), 0, ',', '.') ?></div>
                <div class="l">Edificaciones</div>
            </div>
            <div class="gl-k" style="border-color:#2E7D3233;background:#2E7D320a;">
                <div class="n" style="color:#2E7D32;">
                    <?= number_format((int)($cif['levantadas'] ?? 0), 0, ',', '.') ?></div>
                <div class="l">Levantadas</div>
            </div>
            <div class="gl-k" style="border-color:#2d448833;background:#2d44880a;">
                <div class="n" style="color:#2d4488;">
                    <?= number_format((int)($cif['aptos'] ?? 0), 0, ',', '.') ?></div>
                <div class="l">Apartamentos</div>
            </div>
            <div class="gl-k" style="border-color:#C9A22755;background:#C9A2270a;">
                <div class="n" style="color:#a8871f;">
                    <?= number_format((int)($cif['aptos_reparar'] ?? 0), 0, ',', '.') ?></div>
                <div class="l">A reparar</div>
            </div>
            <div class="gl-k" style="border-color:#97a0b833;">
                <div class="n" style="color:#5b6478;">
                    <?= number_format((int)($cif['familias'] ?? 0), 0, ',', '.') ?></div>
                <div class="l">Familias</div>
            </div>
        </div>

        <?php if (!empty($tot['trabajos'])): ?>
        <div style="margin-top:15px;padding-top:13px;border-top:1px solid #eef0f5;">
            <div style="font-size:11.5px;text-transform:uppercase;color:#55617f;
                        font-weight:700;margin-bottom:8px;">
                Trabajos por ejecutar ·
                <?= number_format($tot['m2_total'] ?? 0, 2, ',', '.') ?> m²
            </div>
            <div class="gl-mat">
                <?php foreach ($tot['trabajos'] as $t): ?>
                <span><strong style="color:#22366F;">
                    <?= number_format($t['m2'], 2, ',', '.') ?> m²</strong> ·
                    <?= e($t['nombre']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($tot['materiales'])): ?>
    <div style="background:#C9A227;color:#22366F;padding:11px 20px;font-weight:800;
                font-size:14px;">
        <i class="bi bi-box-seam-fill"></i> MATERIAL TOTAL QUE SE NECESITA
    </div>
    <div style="padding:15px 20px;background:#fffdf5;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
            <?php foreach ($tot['materiales'] as $m): ?>
            <div style="background:#fff;border:1px solid #C9A22744;border-radius:9px;
                        padding:11px 14px;">
                <div style="font-size:19px;font-weight:800;color:#22366F;line-height:1;">
                    <?= number_format($m['cantidad'], 2, ',', '.') ?>
                </div>
                <?= badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#8a6d1a', '10px') ?>
                <div style="font-size:11px;color:#5b6478;margin-top:3px;">
                    <?= e($m['unidad']) ?> · <?= e($m['material']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Por responsable -->
<div class="gl-card">
    <div class="gl-cab">
        <div style="font-weight:700;color:#22366F;font-size:15px;">
            <i class="bi bi-people-fill"></i> Por responsable de parroquia
        </div>
        <div style="font-size:12.5px;color:#5b6478;">
            Lo que lleva cada quien y el material que le corresponde
        </div>
    </div>
    <div class="gl-body">

    <?php if (!$resp): ?>
        <p class="text-muted" style="margin:0;">
            Todavía no hay responsables con parroquias asignadas.
            Se asignan desde Administración &gt; Usuarios.
        </p>
    <?php endif; ?>

    <?php foreach ($resp as $r):
        $rc = $r['cifras'];
        $ini = mb_strtoupper(mb_substr($r['nombre'], 0, 1, 'UTF-8'), 'UTF-8');
        $pctLev = $rc['edificaciones'] > 0
                  ? round($rc['levantadas'] / $rc['edificaciones'] * 100) : 0;
    ?>
    <div class="gl-resp">
        <div class="gl-resp-cab">
            <div class="gl-avatar"><?= e($ini) ?></div>
            <div style="flex:1;min-width:170px;">
                <div style="font-weight:700;color:#22366F;font-size:15px;">
                    <?= e($r['nombre']) ?>
                </div>
                <div style="font-size:11.5px;color:#5b6478;">
                    <?= e($r['rol']) ?> ·
                    <?= $r['n_parroquias'] ?> parroquia<?= $r['n_parroquias'] === 1 ? '' : 's' ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:17px;font-weight:800;
                            color:<?= $pctLev >= 100 ? '#2E7D32' : '#a8871f' ?>;">
                    <?= $pctLev ?>%
                </div>
                <div style="font-size:10.5px;color:#5b6478;">levantado</div>
            </div>
        </div>

        <!-- Sus parroquias -->
        <div style="padding:10px 16px;border-top:1px solid #eef0f5;">
            <?php foreach ($r['parroquias'] as $p): ?>
            <span class="gl-parr">
                <?= e($p['nombre']) ?>
                <span style="opacity:.7;">(<?= (int)$p['edificaciones'] ?>)</span>
            </span>
            <?php endforeach; ?>
        </div>

        <!-- Sus cifras -->
        <div class="gl-cifras">
            <div class="gl-cifra">
                <div class="v"><?= number_format((int)$rc['edificaciones'], 0, ',', '.') ?></div>
                <div class="e">Edificaciones</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#2E7D32;">
                    <?= number_format((int)$rc['levantadas'], 0, ',', '.') ?></div>
                <div class="e">Levantadas</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#2d4488;">
                    <?= number_format((int)$rc['aptos'], 0, ',', '.') ?></div>
                <div class="e">Apartamentos</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#a8871f;">
                    <?= number_format((int)$rc['aptos_reparar'], 0, ',', '.') ?></div>
                <div class="e">A reparar</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#5b6478;">
                    <?= number_format((int)$rc['familias'], 0, ',', '.') ?></div>
                <div class="e">Familias</div>
            </div>
            <?php if ($r['m2_total'] > 0): ?>
            <div class="gl-cifra">
                <div class="v" style="color:#22366F;">
                    <?= number_format($r['m2_total'], 2, ',', '.') ?></div>
                <div class="e">m² a reparar</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Su material -->
        <?php if (!empty($r['materiales'])): ?>
        <div style="padding:11px 16px;background:#fffdf5;border-top:1px solid #C9A22733;">
            <div style="font-size:11px;text-transform:uppercase;color:#8a6d1a;
                        font-weight:700;margin-bottom:7px;">
                <i class="bi bi-box-seam"></i> Material que necesita
            </div>
            <div class="gl-mat">
                <?php foreach ($r['materiales'] as $m): ?>
                <span><strong style="color:#22366F;">
                    <?= number_format($m['cantidad'], 2, ',', '.') ?></strong>
                    <?= e($m['unidad']) ?> · <?= e($m['material']) ?>
                    <?php $bs = badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#8a6d1a', '10px'); ?>
                    <?php if ($bs !== ''): ?><br><?= $bs ?><?php endif; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Parroquias sin responsable -->
    <?php if ($sinResp): $sc = $sinResp['cifras']; ?>
    <div class="gl-resp" style="border-color:#C9A22755;">
        <div class="gl-resp-cab" style="background:#fffbf0;">
            <div class="gl-avatar" style="background:#C9A227;color:#22366F;">
                <i class="bi bi-question-lg"></i>
            </div>
            <div style="flex:1;min-width:170px;">
                <div style="font-weight:700;color:#a8871f;font-size:15px;">
                    Sin responsable asignado
                </div>
                <div style="font-size:11.5px;color:#8a6d1a;">
                    <?= $sinResp['n_parroquias'] ?> parroquia<?= $sinResp['n_parroquias'] === 1 ? '' : 's' ?>
                    sin nadie a cargo
                </div>
            </div>
        </div>
        <div style="padding:10px 16px;border-top:1px solid #eef0f5;">
            <?php foreach ($sinResp['parroquias'] as $p): ?>
            <span class="gl-parr" style="background:#fdf6e3;color:#8a6d1a;">
                <?= e($p['nombre']) ?>
                <span style="opacity:.7;">(<?= (int)$p['edificaciones'] ?>)</span>
            </span>
            <?php endforeach; ?>
        </div>
        <div class="gl-cifras">
            <div class="gl-cifra">
                <div class="v"><?= number_format((int)$sc['edificaciones'], 0, ',', '.') ?></div>
                <div class="e">Edificaciones</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#2E7D32;">
                    <?= number_format((int)$sc['levantadas'], 0, ',', '.') ?></div>
                <div class="e">Levantadas</div>
            </div>
            <div class="gl-cifra">
                <div class="v" style="color:#a8871f;">
                    <?= number_format((int)$sc['aptos_reparar'], 0, ',', '.') ?></div>
                <div class="e">A reparar</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    </div>
</div>

<div style="text-align:center;margin:18px 0 30px;" class="no-print">
    <button class="btn btn-outline" onclick="window.print()">
        <i class="bi bi-printer"></i> Imprimir reporte
    </button>
</div>

<div style="text-align:center;font-size:11.5px;color:#767c94;margin-bottom:26px;">
    Generado el <?= date('d/m/Y') ?> a las <?= date('H:i') ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
