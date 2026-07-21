<?php
/**
 * REPORTE EJECUTIVO.
 *
 * Vista de conjunto del programa de reconstrucción, pensada para
 * autoridades: sin mapa, solo cifras y gráficos directos.
 * Permite filtrar por parroquia, uso y clasificación.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$filtros = [
    'parroquia' => trim($_GET['parroquia'] ?? ''),
    'uso'       => trim($_GET['uso'] ?? ''),
    'color'     => trim($_GET['color'] ?? ''),
];

$rep = segReporteEjecutivo($filtros);
$t   = $rep['totales'];
$cat = catalogoDecisionFinal();

// Parroquias y usos disponibles, para los filtros.
$parroquias = [];
$usos = [];
try {
    $conds = []; $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');

    // La condición del campo se suma a las del alcance: así el WHERE
    // queda bien armado tanto si hay alcance como si no.
    $cp = $conds;
    $cp[] = "i.parroquia IS NOT NULL AND i.parroquia <> ''";
    $stP = db()->prepare('SELECT DISTINCT i.parroquia FROM inspecciones i WHERE '
                         . implode(' AND ', $cp) . ' ORDER BY i.parroquia');
    $stP->execute($params);
    $parroquias = $stP->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $cu = $conds;
    $cu[] = "i.uso_edificacion IS NOT NULL AND i.uso_edificacion <> ''";
    $stU = db()->prepare('SELECT DISTINCT i.uso_edificacion FROM inspecciones i WHERE '
                         . implode(' AND ', $cu) . ' ORDER BY i.uso_edificacion');
    $stU->execute($params);
    $usos = $stU->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

// Colores por decisión.
$COL = [
    'Edificación Insegura - Acceso No Permitido'   => ['ROJO', '#A61C1C'],
    'Acceso Restringido - Precaución al Entrar'    => ['AMARILLO', '#C9A227'],
    'Edificación Inspeccionada - Acceso Permitido' => ['VERDE', '#2E7D32'],
    'Derrumbado'                                   => ['DERRUMBADO', '#2B2B2B'],
];

$totalEdif  = (int)($t['edificaciones'] ?? 0);
$levantadas = (int)($t['levantamientos_cerrados'] ?? 0);
$pctLev     = $totalEdif > 0 ? round($levantadas / $totalEdif * 100) : 0;

$pageTitle    = 'Reporte ejecutivo';
$pageSubtitle = 'Estado del programa de reconstrucción';
$activeModule = 'reporte';
include __DIR__ . '/../includes/header.php';
?>
<style>
.rp-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.rp-tit { font-weight:700; color:#22366F; font-size:15px; margin-bottom:13px;
          display:flex; align-items:center; gap:8px; }
.rp-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:11px; }
.rp-k { text-align:center; padding:17px 12px; border-radius:11px; border:1px solid; }
.rp-k .n { font-size:clamp(24px,3.4vw,34px); font-weight:800; line-height:1; }
.rp-k .l { font-size:11px; text-transform:uppercase; color:#55617f;
           margin-top:6px; letter-spacing:.3px; line-height:1.3; }

.rp-barra-fila { display:flex; align-items:center; gap:11px; padding:7px 0; }
.rp-barra-fila .et { flex:0 0 150px; font-size:12.5px; color:#2a3140; }
.rp-barra-fila .nu { flex:0 0 52px; text-align:right; font-size:14px; font-weight:800; }
.rp-barra-fila .ba { flex:1; background:#f1f3f8; border-radius:20px; height:11px;
                     overflow:hidden; min-width:70px; }
.rp-barra-fila .pc { flex:0 0 42px; text-align:right; font-size:11.5px; color:#767c94; }

table.rp-tabla { width:100%; border-collapse:collapse; }
table.rp-tabla th { background:#f1f3f8; color:#22366F; font-size:10.5px; padding:8px 9px;
                    text-align:left; text-transform:uppercase; }
table.rp-tabla td { font-size:12.5px; padding:8px 9px; border-bottom:1px solid #f0f2f7; }
table.rp-tabla tr:nth-child(even) td { background:#fafbfe; }
.mini { display:inline-block; width:100%; background:#f1f3f8; border-radius:10px;
        height:8px; overflow:hidden; }

@media (max-width: 640px) {
    .rp-barra-fila .et { flex:0 0 110px; font-size:11.5px; }
    table.rp-tabla { font-size:11px; }
}

/* Pantallas angostas: la fila se parte en dos líneas.
   Con anchos fijos, la etiqueta + número + barra + porcentaje suman
   más que la pantalla y el porcentaje se salía del recuadro. */
@media (max-width: 420px) {
    .rp-barra-fila {
        display: grid;
        grid-template-columns: 14px 1fr auto;
        grid-template-areas:
            "punto etiqueta valor"
            ".     barra    pct";
        gap: 3px 8px;
        align-items: center;
        padding: 9px 0;
    }

    /* El cuadrito de color */
    .rp-barra-fila > span:first-child { grid-area: punto; }

    .rp-barra-fila .et {
        grid-area: etiqueta;
        flex: none;
        font-size: 12.5px;
        min-width: 0;
        word-break: break-word;
        line-height: 1.3;
    }

    /* El número, alineado a la derecha en la primera línea */
    .rp-barra-fila .nu {
        grid-area: valor;
        flex: none;
        font-size: 15px;
        white-space: nowrap;
    }

    /* La barra ocupa el ancho disponible en la segunda línea */
    .rp-barra-fila .ba {
        grid-area: barra;
        flex: none;
        min-width: 0;
        height: 9px;
    }

    .rp-barra-fila .pc {
        grid-area: pct;
        flex: none;
        font-size: 11.5px;
        white-space: nowrap;
        padding-left: 4px;
    }

    /* Las cifras grandes de arriba, dos por fila */
    .rp-kpis { grid-template-columns: repeat(2, 1fr) !important; }

    /* La tabla de parroquias con desplazamiento propio */
    .rp-card > div[style*="overflow-x"] { margin: 0 -12px; padding: 0 12px; }
}
@media print {
    .seg-filtros, .app-nav, .no-print { display:none !important; }
    .rp-card { box-shadow:none; border:1px solid #dbe0ec; page-break-inside:avoid; }
}
</style>

<!-- Filtros -->
<div class="rp-card no-print">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="margin:0;">
            <label class="text-sm">Parroquia</label>
            <select name="parroquia" class="form-control" style="width:190px;"
                    onchange="this.form.submit()">
                <option value="">Todas las parroquias</option>
                <?php foreach ($parroquias as $p): ?>
                <option value="<?= e($p) ?>" <?= $filtros['parroquia'] === $p ? 'selected' : '' ?>>
                    <?= e($p) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label class="text-sm">Uso</label>
            <select name="uso" class="form-control" style="width:170px;"
                    onchange="this.form.submit()">
                <option value="">Todos los usos</option>
                <?php foreach ($usos as $u): ?>
                <option value="<?= e($u) ?>" <?= $filtros['uso'] === $u ? 'selected' : '' ?>>
                    <?= e($u) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label class="text-sm">Clasificación</label>
            <select name="color" class="form-control" style="width:160px;"
                    onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="rojo"       <?= $filtros['color']==='rojo'?'selected':'' ?>>Rojo</option>
                <option value="amarillo"   <?= $filtros['color']==='amarillo'?'selected':'' ?>>Amarillo</option>
                <option value="verde"      <?= $filtros['color']==='verde'?'selected':'' ?>>Verde</option>
                <option value="derrumbado" <?= $filtros['color']==='derrumbado'?'selected':'' ?>>Derrumbado</option>
            </select>
        </div>
        <?php if ($filtros['parroquia'] || $filtros['uso'] || $filtros['color']): ?>
        <a href="<?= APP_URL_BASE ?>seguimiento/reporte.php" class="btn btn-outline">
            <i class="bi bi-x-circle"></i> Quitar filtros
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimir
        </button>
        <a href="<?= APP_URL_BASE ?>seguimiento/pdf_ejecutivo.php" target="_blank"
           class="btn btn-primary">
            <i class="bi bi-file-earmark-bar-graph-fill"></i> Resumen de una página
        </a>
    </form>
</div>

<!-- Cifras principales -->
<div class="rp-card">
    <div class="rp-tit"><i class="bi bi-bar-chart-fill"></i> Cifras generales</div>
    <div class="rp-kpis">
        <div class="rp-k" style="border-color:#22366F33;background:#22366F0a;">
            <div class="n" style="color:#22366F;"><?= number_format($totalEdif, 0, ',', '.') ?></div>
            <div class="l">Edificaciones</div>
        </div>
        <div class="rp-k" style="border-color:#2E7D3233;background:#2E7D320a;">
            <div class="n" style="color:#2E7D32;"><?= number_format($levantadas, 0, ',', '.') ?></div>
            <div class="l">Levantamientos hechos</div>
        </div>
        <div class="rp-k" style="border-color:#C9A22755;background:#C9A2270a;">
            <div class="n" style="color:#a8871f;">
                <?= number_format((int)($t['aptos_reparar'] ?? 0), 0, ',', '.') ?></div>
            <div class="l">Apartamentos a reparar</div>
        </div>
        <div class="rp-k" style="border-color:#2d448833;background:#2d44880a;">
            <div class="n" style="color:#2d4488;">
                <?= number_format($rep['familias'], 0, ',', '.') ?></div>
            <div class="l">Familias afectadas</div>
        </div>
        <div class="rp-k" style="border-color:#97a0b833;">
            <div class="n" style="color:#5b6478;">
                <?= number_format($rep['personas'], 0, ',', '.') ?></div>
            <div class="l">Personas</div>
        </div>
    </div>

    <!-- Avance del levantamiento -->
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef0f5;">
        <div style="display:flex;justify-content:space-between;font-size:13px;
                    color:#55617f;margin-bottom:5px;">
            <span>Avance del levantamiento técnico</span>
            <strong style="font-size:17px;color:<?= $pctLev >= 100 ? '#2E7D32' : '#a8871f' ?>;">
                <?= $pctLev ?>%
            </strong>
        </div>
        <div style="background:#eef0f6;border-radius:20px;height:20px;overflow:hidden;">
            <div style="width:<?= $pctLev ?>%;height:100%;
                        background:<?= $pctLev >= 100 ? '#2E7D32' : '#C9A227' ?>;"></div>
        </div>
        <div style="font-size:12px;color:#5b6478;margin-top:5px;">
            <?= number_format($levantadas, 0, ',', '.') ?> de
            <?= number_format($totalEdif, 0, ',', '.') ?> edificaciones con levantamiento cerrado
        </div>
    </div>
</div>

<!-- Clasificación -->
<?php if ($rep['por_color']): ?>
<div class="rp-card">
    <div class="rp-tit"><i class="bi bi-pie-chart-fill"></i> Clasificación de las edificaciones</div>
    <?php
    $totCol = array_sum(array_map(fn($c) => (int)$c['n'], $rep['por_color']));
    $porDec = [];
    foreach ($rep['por_color'] as $c) $porDec[$c['decision']] = $c;
    ?>
    <?php foreach ($COL as $dec => $meta):
        $d = $porDec[$dec] ?? null;
        $n = $d ? (int)$d['n'] : 0;
        $pct = $totCol > 0 ? round($n / $totCol * 100) : 0;
    ?>
    <div class="rp-barra-fila">
        <span style="width:11px;height:11px;border-radius:3px;background:<?= $meta[1] ?>;
                     flex-shrink:0;"></span>
        <span class="et"><?= $meta[0] ?></span>
        <span class="nu" style="color:<?= $n > 0 ? $meta[1] : '#c4c9d6' ?>;">
            <?= number_format($n, 0, ',', '.') ?>
        </span>
        <span class="ba"><span style="display:block;width:<?= $pct ?>%;height:100%;
              background:<?= $meta[1] ?>;border-radius:20px;"></span></span>
        <span class="pc"><?= $pct ?>%</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Por parroquia -->
<?php if (count($rep['por_parroquia']) > 1): ?>
<div class="rp-card">
    <div class="rp-tit"><i class="bi bi-geo-alt-fill"></i> Situación por parroquia</div>
    <div style="overflow-x:auto;">
    <table class="rp-tabla">
        <thead><tr>
            <th>Parroquia</th>
            <th style="width:70px;text-align:center;">Edif.</th>
            <th style="width:110px;">Levantadas</th>
            <th style="width:54px;text-align:center;color:#A61C1C;">Rojo</th>
            <th style="width:60px;text-align:center;color:#a8871f;">Amar.</th>
            <th style="width:54px;text-align:center;color:#2E7D32;">Verde</th>
            <th style="width:70px;text-align:center;">Familias</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rep['por_parroquia'] as $p):
            $tot = (int)$p['total'];
            $lev = (int)$p['levantadas'];
            $pc  = $tot > 0 ? round($lev / $tot * 100) : 0;
        ?>
        <tr>
            <td>
                <a href="?parroquia=<?= urlencode($p['parroquia']) ?>"
                   style="font-weight:600;color:#22366F;text-decoration:none;">
                    <?= e($p['parroquia'] ?: 'Sin parroquia') ?>
                </a>
            </td>
            <td style="text-align:center;font-weight:700;"><?= number_format($tot, 0, ',', '.') ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span class="mini"><span style="display:block;width:<?= $pc ?>%;height:100%;
                          background:<?= $pc >= 100 ? '#2E7D32' : '#C9A227' ?>;"></span></span>
                    <span style="font-size:11px;color:#5b6478;min-width:30px;"><?= $pc ?>%</span>
                </div>
            </td>
            <td style="text-align:center;color:#A61C1C;font-weight:600;"><?= (int)$p['rojos'] ?></td>
            <td style="text-align:center;color:#a8871f;font-weight:600;"><?= (int)$p['amarillos'] ?></td>
            <td style="text-align:center;color:#2E7D32;font-weight:600;"><?= (int)$p['verdes'] ?></td>
            <td style="text-align:center;"><?= number_format((int)$p['familias'], 0, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Por uso -->
<?php if (count($rep['por_uso']) > 1): ?>
<div class="rp-card">
    <div class="rp-tit"><i class="bi bi-building"></i> Tipo de edificación</div>
    <?php
    $totUso = array_sum(array_map(fn($u) => (int)$u['n'], $rep['por_uso']));
    $colores = ['#22366F', '#2d4488', '#C9A227', '#2E7D32', '#A61C1C', '#5b6478'];
    ?>
    <?php foreach (array_slice($rep['por_uso'], 0, 8) as $k => $u):
        $n = (int)$u['n'];
        $pct = $totUso > 0 ? round($n / $totUso * 100) : 0;
        $c = $colores[$k % count($colores)];
    ?>
    <div class="rp-barra-fila">
        <span style="width:11px;height:11px;border-radius:3px;background:<?= $c ?>;
                     flex-shrink:0;"></span>
        <span class="et"><?= e($u['uso']) ?></span>
        <span class="nu" style="color:<?= $c ?>;"><?= number_format($n, 0, ',', '.') ?></span>
        <span class="ba"><span style="display:block;width:<?= $pct ?>%;height:100%;
              background:<?= $c ?>;border-radius:20px;"></span></span>
        <span class="pc"><?= $pct ?>%</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Trabajos -->
<?php if ($rep['trabajos']): ?>
<div class="rp-card">
    <div class="rp-tit"><i class="bi bi-tools"></i> Trabajos por ejecutar</div>
    <?php
    $totM2 = array_sum(array_map(fn($x) => $x['m2'], $rep['trabajos']));
    ?>
    <div style="font-size:13px;color:#5b6478;margin-bottom:11px;">
        <strong style="font-size:19px;color:#22366F;">
            <?= number_format($totM2, 2, ',', '.') ?> m²
        </strong> en total
    </div>
    <?php foreach ($rep['trabajos'] as $k => $tr):
        $pct = $totM2 > 0 ? round($tr['m2'] / $totM2 * 100) : 0;
        $c = $colores[$k % count($colores)] ?? '#22366F';
    ?>
    <div class="rp-barra-fila">
        <span style="width:11px;height:11px;border-radius:3px;background:<?= $c ?>;
                     flex-shrink:0;"></span>
        <span class="et" style="flex:0 0 220px;"><?= e($tr['nombre']) ?></span>
        <span class="nu" style="flex:0 0 86px;color:<?= $c ?>;">
            <?= number_format($tr['m2'], 2, ',', '.') ?>
        </span>
        <span class="ba"><span style="display:block;width:<?= $pct ?>%;height:100%;
              background:<?= $c ?>;border-radius:20px;"></span></span>
        <span class="pc"><?= $pct ?>%</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Materiales -->
<?php if ($rep['materiales']): ?>
<div class="rp-card" style="border:3px solid #C9A227;padding:0;overflow:hidden;">
    <div style="background:#C9A227;color:#22366F;padding:13px 20px;">
        <div style="font-size:15px;font-weight:800;">
            <i class="bi bi-box-seam-fill"></i> MATERIAL QUE SE NECESITA
        </div>
        <div style="font-size:12.5px;font-weight:600;opacity:.85;">
            Para todo lo registrado hasta ahora
        </div>
    </div>
    <div style="padding:16px 20px;background:#fffdf5;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
            <?php foreach ($rep['materiales'] as $m): ?>
            <div style="background:#fff;border:1px solid #C9A22744;border-radius:10px;
                        padding:12px 15px;">
                <div style="font-size:20px;font-weight:800;color:#22366F;line-height:1;">
                    <?= number_format($m['cantidad'], 2, ',', '.') ?>
                </div>
                <div style="font-size:11.5px;color:#5b6478;margin-top:3px;">
                    <?= e($m['unidad']) ?> · <?= e($m['material']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11.5px;color:#8a6d1a;margin-top:11px;">
            <i class="bi bi-info-circle"></i>
            Incluye un 10% de holgura por desperdicio y roturas.
            Cálculo sobre los metros registrados en los levantamientos.
        </div>
    </div>
</div>
<?php endif; ?>

<div style="text-align:center;font-size:11.5px;color:#767c94;margin:20px 0 30px;">
    Generado el <?= date('d/m/Y') ?> a las <?= date('H:i') ?>
    <?php if ($filtros['parroquia'] || $filtros['uso'] || $filtros['color']): ?>
    <br>Filtros aplicados:
    <?= e(implode(' · ', array_filter([
        $filtros['parroquia'] ? 'Parroquia: ' . $filtros['parroquia'] : '',
        $filtros['uso'] ? 'Uso: ' . $filtros['uso'] : '',
        $filtros['color'] ? 'Clasificación: ' . mb_strtoupper($filtros['color'], 'UTF-8') : '',
    ]))) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
