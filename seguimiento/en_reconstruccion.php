<?php
/**
 * EN RECONSTRUCCIÓN — buscador rápido.
 *
 * Las edificaciones con levantamiento cerrado y avance menor a 100%.
 * Ordenadas por fecha de entrega: primero las que vencen antes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$lista = segEnReconstruccion();
$cat   = catalogoDecisionFinal();

// Resumen por estado del plazo.
$res = ['vencida' => 0, 'hoy' => 0, 'urgente' => 0, 'a_tiempo' => 0, 'sin_fecha' => 0];
$atrasadas = 0;
$porParroquia = [];
foreach ($lista as $e) {
    $p = $e['plazo'];
    if (!$p) $res['sin_fecha']++;
    else {
        $res[$p['estado']] = ($res[$p['estado']] ?? 0) + 1;
        if (!empty($p['atrasada'])) $atrasadas++;
    }
    $parr = $e['parroquia'] ?: 'Sin parroquia';
    $porParroquia[$parr] = ($porParroquia[$parr] ?? 0) + 1;
}
ksort($porParroquia);

$pageTitle    = 'En reconstrucción';
$pageSubtitle = count($lista) . ' edificaciones en obra';
$activeModule = 'reconstruccion';
include __DIR__ . '/../includes/header.php';
?>
<style>
.rc-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.rc-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.rc-kpis { display:flex; gap:9px; flex-wrap:wrap; }
.rc-k { flex:1; min-width:110px; text-align:center; padding:13px 8px; border-radius:10px;
        border:2px solid; cursor:pointer; background:#fff; transition:all .15s; }
.rc-k:hover { transform:translateY(-1px); }
.rc-k.activo { box-shadow:0 0 0 3px #22366F22; }
.rc-k .n { font-size:26px; font-weight:800; line-height:1; }
.rc-k .l { font-size:10.5px; text-transform:uppercase; color:#55617f; margin-top:4px; }
.rc-fila { display:flex; align-items:center; gap:12px; padding:13px 8px;
           border-bottom:1px solid #f0f2f7; }
.rc-fila:last-child { border-bottom:0; }
.rc-fila:hover { background:#fafbfe; }
.clas-letra { display:inline-flex; align-items:center; justify-content:center;
              width:26px; height:26px; border:2px solid; border-radius:6px;
              font-weight:800; font-size:13px; flex-shrink:0; }
.rc-barra { flex:0 0 100px; background:#eef0f6; border-radius:20px; height:14px; overflow:hidden; }
.rc-plazo { min-width:132px; font-size:12px; font-weight:600; }
.rc-plazo .fecha { font-size:10.5px; color:#767c94; font-weight:400; display:block; }
@media (max-width: 700px) {
    .rc-fila { flex-wrap:wrap; }
    .rc-fila > div:nth-child(2) { flex:1 1 100%; }
    .rc-barra { flex:1 1 auto; }
    .rc-plazo { flex:1 1 100%; }
}
</style>

<?php if (!$lista): ?>
<div class="rc-card" style="text-align:center;padding:40px 20px;">
    <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-hammer"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">No hay edificaciones en reconstrucción</h3>
    <p class="text-muted" style="margin:0;">
        Aquí aparecerán las que tengan el levantamiento técnico cerrado
        y todavía no lleguen al 100%.
    </p>
</div>

<?php else: ?>

<!-- Resumen por plazo -->
<div class="rc-card">
    <div class="rc-tit"><i class="bi bi-hammer"></i> <?= count($lista) ?> edificaciones en obra</div>
    <p class="text-sm text-muted" style="margin:-6px 0 12px;">
        Toque un recuadro para filtrar.
    </p>

    <div class="rc-kpis">
        <button class="rc-k activo" data-f="todos" onclick="filtrarPlazo('todos', this)"
                style="border-color:#22366F;">
            <div class="n" style="color:#22366F;"><?= count($lista) ?></div><div class="l">Todas</div>
        </button>
        <?php if ($res['vencida'] > 0): ?>
        <button class="rc-k" data-f="vencida" onclick="filtrarPlazo('vencida', this)"
                style="border-color:#A61C1C55;">
            <div class="n" style="color:#A61C1C;"><?= $res['vencida'] ?></div><div class="l">Vencidas</div>
        </button>
        <?php endif; ?>
        <?php if ($res['hoy'] > 0): ?>
        <button class="rc-k" data-f="hoy" onclick="filtrarPlazo('hoy', this)"
                style="border-color:#A61C1C55;">
            <div class="n" style="color:#A61C1C;"><?= $res['hoy'] ?></div><div class="l">Vencen hoy</div>
        </button>
        <?php endif; ?>
        <button class="rc-k" data-f="urgente" onclick="filtrarPlazo('urgente', this)"
                style="border-color:#C9A22755;">
            <div class="n" style="color:#a8871f;"><?= $res['urgente'] ?></div><div class="l">Esta semana</div>
        </button>
        <button class="rc-k" data-f="a_tiempo" onclick="filtrarPlazo('a_tiempo', this)"
                style="border-color:#2d448855;">
            <div class="n" style="color:#2d4488;"><?= $res['a_tiempo'] ?></div><div class="l">A tiempo</div>
        </button>
        <button class="rc-k" data-f="sin_fecha" onclick="filtrarPlazo('sin_fecha', this)"
                style="border-color:#97a0b855;">
            <div class="n" style="color:#5b6478;"><?= $res['sin_fecha'] ?></div><div class="l">Sin fecha</div>
        </button>
    </div>

    <?php if ($atrasadas > 0): ?>
    <div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;
                padding:10px 13px;margin-top:12px;font-size:12.5px;color:#8a6d1a;">
        <i class="bi bi-graph-down-arrow"></i>
        <strong><?= $atrasadas ?></strong> edificación(es) van con el avance por detrás
        de lo esperado para la fecha.
    </div>
    <?php endif; ?>
</div>

<!-- Listado -->
<div class="rc-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div class="rc-tit" style="margin:0;">
            <i class="bi bi-list-ul"></i> Detalle
            <span id="rc-cont" style="background:#eef2fb;color:#22366F;border-radius:12px;
                  padding:2px 9px;font-size:12px;font-weight:700;"><?= count($lista) ?></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="text" id="rc-buscar" class="form-control" style="width:200px;"
                   placeholder="Buscar edificación…" oninput="filtrarLista()">
            <?php if (count($porParroquia) > 1): ?>
            <select id="rc-parroquia" class="form-control" style="width:175px;" onchange="filtrarLista()">
                <option value="">Todas las parroquias</option>
                <?php foreach ($porParroquia as $p => $n): ?>
                <option value="<?= e($p) ?>"><?= e($p) ?> (<?= $n ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </div>

    <div id="rc-lista">
    <?php foreach ($lista as $e):
        $av = (int)$e['avance'];
        $col = $av >= 75 ? '#5a9e3f' : ($av > 0 ? '#a8871f' : '#97a0b8');
        $sim = recSimboloDecision($e['decision_final'] ?? null);
        $pz = $e['plazo'];
        $estadoPz = $pz ? $pz['estado'] : 'sin_fecha';
    ?>
    <div class="rc-fila" data-plazo="<?= $estadoPz ?>"
         data-parroquia="<?= e($e['parroquia'] ?? '') ?>"
         data-txt="<?= e(mb_strtolower(($e['nombre_edificio'] ?? '') . ' ' .
                   ($e['codigo'] ?? '') . ' ' . ($e['ente_nombre'] ?? ''), 'UTF-8')) ?>">

        <span class="clas-letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"
              title="<?= e($sim['texto']) ?>"><?= $sim['letra'] ?></span>

        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#2a3140;font-size:14.5px;">
                <?= e($e['nombre_edificio'] ?: 'Sin nombre') ?>
            </div>
            <div style="font-size:11.5px;color:#5b6478;">
                <?= e($e['codigo']) ?> · <?= e($e['parroquia'] ?: '—') ?>
                <?php if (!empty($e['ente_nombre'])): ?> · <?= e($e['ente_nombre']) ?><?php endif; ?>
            </div>
        </div>

        <!-- Plazo -->
        <div class="rc-plazo">
            <?php if ($pz): ?>
                <span style="color:<?= $pz['color'] ?>;">
                    <i class="bi <?= $pz['icono'] ?>"></i> <?= e($pz['texto']) ?>
                </span>
                <span class="fecha">
                    Entrega: <?= date('d/m/Y', strtotime($pz['fecha_fin'])) ?>
                </span>
                <?php if (!empty($pz['atrasada'])): ?>
                <span style="font-size:10px;color:#8a6d1a;">
                    <i class="bi bi-graph-down-arrow"></i>
                    esperado <?= (int)$pz['avance_esperado'] ?>%
                </span>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:#97a0b8;font-weight:400;">
                    <i class="bi bi-calendar-x"></i> Sin fecha de entrega
                </span>
            <?php endif; ?>
        </div>

        <div class="rc-barra">
            <div style="width:<?= $av ?>%;height:100%;background:<?= $col ?>;"></div>
        </div>
        <span style="font-weight:800;color:<?= $col ?>;min-width:44px;text-align:right;"><?= $av ?>%</span>

        <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$e['id'] ?>"
           class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
    </div>
    <p id="rc-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
        Ninguna coincide con el filtro.
    </p>
</div>

<script>
let _filtroPlazo = 'todos';

function filtrarPlazo(f, btn) {
    _filtroPlazo = f;
    document.querySelectorAll('.rc-k').forEach(b => b.classList.remove('activo'));
    if (btn) btn.classList.add('activo');
    filtrarLista();
}

function filtrarLista() {
    const t = (document.getElementById('rc-buscar').value || '').toLowerCase().trim();
    const selP = document.getElementById('rc-parroquia');
    const parr = selP ? selP.value : '';
    let n = 0;

    document.querySelectorAll('.rc-fila').forEach(f => {
        const okPlazo = _filtroPlazo === 'todos' || f.dataset.plazo === _filtroPlazo;
        const okTxt   = !t || (f.dataset.txt || '').includes(t);
        const okParr  = !parr || f.dataset.parroquia === parr;
        const ver = okPlazo && okTxt && okParr;
        f.style.display = ver ? '' : 'none';
        if (ver) n++;
    });

    document.getElementById('rc-cont').textContent = n;
    document.getElementById('rc-vacio').style.display = n ? 'none' : '';
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
