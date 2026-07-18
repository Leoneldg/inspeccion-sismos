<?php
/**
 * MI PARROQUIA — Panel del Responsable de Parroquia.
 * Muestra de forma directa:
 *   · Avance general de su(s) parroquia(s).
 *   · Las edificaciones EN RECONSTRUCCIÓN con su % arriba.
 *   · Su equipo: responsable + frentes de trabajo.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$misParroquias = parroquiasDelUsuario();
$estadoUsr = estadoDelUsuario() ?: 'Distrito Capital';

// Si no tiene parroquias asignadas, se le manda al mapa general.
if (!$misParroquias) {
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$cat = catalogoDecisionFinal();

// Datos de cada parroquia asignada.
$datos = [];
foreach ($misParroquias as $parr) {
    $panel = recPanelParroquia($estadoUsr, $parr);
    $datos[$parr] = $panel;
}

$pageTitle    = count($misParroquias) === 1 ? 'Parroquia ' . $misParroquias[0] : 'Mis parroquias';
$pageSubtitle = 'Seguimiento de la reconstrucción';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.mp-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07); padding:18px 20px; margin-bottom:16px; }
.mp-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.mp-kpis { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.mp-kpi { flex:1; min-width:120px; text-align:center; padding:14px 10px; border-radius:10px; }
.mp-kpi .n { font-size:30px; font-weight:800; line-height:1; }
.mp-kpi .l { font-size:11px; text-transform:uppercase; letter-spacing:.4px; margin-top:4px; color:#55617f; }
.mp-edif { display:flex; align-items:center; gap:12px; padding:12px 10px; border-bottom:1px solid #f0f2f7; }
.mp-edif:last-child { border-bottom:0; }
.mp-edif:hover { background:#f7f9fd; }
.mp-barra { flex:0 0 130px; background:#eef0f6; border-radius:20px; height:16px; overflow:hidden; }
.mp-pct { font-weight:800; min-width:48px; text-align:right; font-size:15px; }
.mp-frente { display:flex; gap:9px; align-items:flex-start; padding:8px 0; border-bottom:1px solid #f4f6fa; }
.mp-frente:last-child { border-bottom:0; }
@media (max-width: 640px) {
    .mp-edif { flex-wrap:wrap; }
    .mp-edif > div:first-child { flex:1 1 100%; }
    .mp-barra { flex:1 1 auto; }
}
</style>

<?php foreach ($datos as $parr => $d):
    $pc = $d['por_color'] ?? [];
    $edifs = $d['edificaciones'] ?? [];
    // Separar por fase para que lo urgente salga primero.
    $enRecon = array_filter($edifs, fn($e) => ($e['avance'] ?? 0) < 100);
    $culminadas = array_filter($edifs, fn($e) => ($e['avance'] ?? 0) >= 100);
    $avgParr = $edifs ? (int)round(array_sum(array_column($edifs, 'avance')) / count($edifs)) : 0;
?>

<!-- Encabezado de la parroquia -->
<div class="mp-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div class="mp-tit" style="margin:0;font-size:19px;">
            <i class="bi bi-geo-alt-fill"></i> <?= e(mb_strtoupper($parr, 'UTF-8')) ?>
        </div>
        <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm">
            <i class="bi bi-map"></i> Ver en el mapa
        </a>
    </div>

    <!-- Avance general de la parroquia, bien visible -->
    <div style="margin:14px 0 6px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#55617f;margin-bottom:4px;">
            <span>Avance general de la parroquia</span>
            <strong style="font-size:16px;color:<?= $avgParr>=100?'#2E7D32':($avgParr>0?'#C9A227':'#97a0b8') ?>;"><?= $avgParr ?>%</strong>
        </div>
        <div style="background:#eef0f6;border-radius:20px;height:22px;overflow:hidden;">
            <div style="width:<?= $avgParr ?>%;height:100%;background:<?= $avgParr>=100?'#2E7D32':'#C9A227' ?>;transition:width .4s;"></div>
        </div>
    </div>

    <div class="mp-kpis" style="margin-top:14px;">
        <div class="mp-kpi" style="background:#A61C1C14;border:1px solid #A61C1C33;">
            <div class="n" style="color:#A61C1C;"><?= (int)($pc['rojo'] ?? 0) ?></div><div class="l">Rojo</div>
        </div>
        <div class="mp-kpi" style="background:#C9A22714;border:1px solid #C9A22733;">
            <div class="n" style="color:#C9A227;"><?= (int)($pc['amarillo'] ?? 0) ?></div><div class="l">Amarillo</div>
        </div>
        <div class="mp-kpi" style="background:#2E7D3214;border:1px solid #2E7D3233;">
            <div class="n" style="color:#2E7D32;"><?= (int)($pc['verde'] ?? 0) ?></div><div class="l">Verde</div>
        </div>
        <div class="mp-kpi" style="background:#2B2B2B10;border:1px solid #2B2B2B33;">
            <div class="n" style="color:#2B2B2B;"><?= (int)($pc['derrumbado'] ?? 0) ?></div><div class="l">Derrumbado</div>
        </div>
    </div>
</div>

<!-- EN RECONSTRUCCIÓN: lo que importa cada día -->
<div class="mp-card">
    <div class="mp-tit">
        <i class="bi bi-hammer"></i> En reconstrucción
        <span style="background:#C9A22722;color:#8a6d1a;font-size:12px;padding:2px 9px;border-radius:12px;font-weight:700;">
            <?= count($enRecon) ?>
        </span>
    </div>
    <?php if (!$enRecon): ?>
        <p class="text-muted" style="margin:0;">Ninguna edificación en reconstrucción en este momento.</p>
    <?php else: ?>
        <?php
        // Las de mayor avance primero: se ve el progreso real.
        usort($enRecon, fn($a, $b) => ($b['avance'] ?? 0) <=> ($a['avance'] ?? 0));
        foreach ($enRecon as $ed):
            $av = (int)($ed['avance'] ?? 0);
            $col = $av >= 100 ? '#2E7D32' : ($av > 0 ? '#C9A227' : '#97a0b8');
            $colorDec = $cat[$ed['decision_final'] ?? '']['color'] ?? '#767c94';
        ?>
        <div class="mp-edif">
            <span style="width:11px;height:11px;border-radius:50%;background:<?= $colorDec ?>;flex-shrink:0;"></span>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:14px;"><?= e($ed['nombre'] ?? 'Sin nombre') ?></div>
                <div style="font-size:11px;color:#767c94;">
                    <?= e($ed['codigo'] ?? '') ?>
                    <?php if (!empty($ed['ente'])): ?> · <?= e($ed['ente']) ?><?php endif; ?>
                </div>
            </div>
            <div class="mp-barra">
                <div style="width:<?= $av ?>%;height:100%;background:<?= $col ?>;"></div>
            </div>
            <span class="mp-pct" style="color:<?= $col ?>;"><?= $av ?>%</span>
            <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$ed['id'] ?>"
               class="btn btn-primary btn-sm"><i class="bi bi-arrow-right"></i> Abrir</a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Culminadas -->
<?php if ($culminadas): ?>
<div class="mp-card">
    <div class="mp-tit">
        <i class="bi bi-check2-circle" style="color:#2E7D32;"></i> Culminadas
        <span style="background:#2E7D3222;color:#2E7D32;font-size:12px;padding:2px 9px;border-radius:12px;font-weight:700;">
            <?= count($culminadas) ?>
        </span>
    </div>
    <?php foreach ($culminadas as $ed): ?>
    <div class="mp-edif">
        <i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#2a3140;font-size:14px;"><?= e($ed['nombre'] ?? 'Sin nombre') ?></div>
            <div style="font-size:11px;color:#767c94;"><?= e($ed['codigo'] ?? '') ?></div>
        </div>
        <span class="mp-pct" style="color:#2E7D32;">100%</span>
        <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$ed['id'] ?>"
           class="btn btn-outline btn-sm">Ver</a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Equipo desplegado: responsable + frentes -->
<div class="mp-card">
    <div class="mp-tit"><i class="bi bi-people-fill"></i> Equipo desplegado</div>

    <?php $encs = $d['encargados'] ?? []; ?>
    <?php if ($encs): ?>
        <?php foreach ($encs as $r): ?>
        <div style="background:#eef2fb;border-radius:9px;padding:11px 13px;margin-bottom:8px;">
            <div style="font-size:10px;text-transform:uppercase;color:#5a6785;letter-spacing:.4px;">Responsable de parroquia</div>
            <div style="font-weight:700;color:#22366F;font-size:15px;"><?= e($r['nombre']) ?></div>
            <?php if (!empty($r['telefono'])): ?>
            <div style="font-size:12px;color:#55617f;"><i class="bi bi-telephone"></i> <?= e($r['telefono']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted" style="margin:0 0 10px;">Sin responsable registrado.</p>
    <?php endif; ?>

    <?php
    $frentes = $d['frentes'] ?? [];
    $tipos = $d['frente_tipos'] ?? [];
    $iconos = ['gdc'=>'bi-people-fill','sistematizador'=>'bi-clipboard-data',
               'corporacion'=>'bi-tools','movilizaciones'=>'bi-megaphone'];
    ?>
    <?php if ($frentes): ?>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#55617f;letter-spacing:.4px;margin:14px 0 4px;">
        Frentes de trabajo
    </div>
        <?php foreach ($frentes as $f): ?>
        <div class="mp-frente">
            <i class="bi <?= $iconos[$f['tipo']] ?? 'bi-dot' ?>" style="color:#2d4488;margin-top:2px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:10px;text-transform:uppercase;color:#97a0b8;letter-spacing:.3px;">
                    <?= e($tipos[$f['tipo']] ?? $f['tipo']) ?>
                </div>
                <div style="font-size:13px;color:#2a3140;font-weight:600;">
                    <?= e($f['nombre']) ?>
                    <?php if (!empty($f['sector'])): ?>
                    <span style="background:#C9A22722;color:#8a6d1a;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:6px;"><?= e($f['sector']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($f['telefono'])): ?>
                <div style="font-size:11px;color:#767c94;"><?= e($f['telefono']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted" style="margin:0;">Sin frentes de trabajo registrados para esta parroquia.</p>
    <?php endif; ?>
</div>

<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
