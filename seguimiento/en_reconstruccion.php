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

// Separar por estado del levantamiento.
$enProceso  = [];
$completados = [];
foreach ($lista as $e) {
    if (!empty($e['lev_completado'])) $completados[] = $e;
    else                             $enProceso[] = $e;
}

// Agrupar por parroquia, que es como se reparte el trabajo.
$porParroquia = [];
foreach ($lista as $e) {
    $porParroquia[$e['parroquia'] ?: 'Sin parroquia'][] = $e;
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
@media (max-width: 700px) {
    .rc-fila { flex-wrap:wrap; }
    .rc-fila > div:nth-child(2) { flex:1 1 100%; }
    .rc-barra { flex:1 1 auto; }
}
</style>

<?php if (!$lista): ?>
<div class="rc-card" style="text-align:center;padding:40px 20px;">
    <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-hammer"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">No hay levantamientos iniciados</h3>
    <p class="text-muted" style="margin:0;">
        Aquí aparecerán las edificaciones apenas alguien empiece su levantamiento técnico.
    </p>
</div>

<?php else: ?>

<!-- Resumen -->
<div class="rc-card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="rc-k activo" data-f="todos" onclick="filtrarEstado('todos', this)"
                style="border-color:#22366F;">
            <div class="n" style="color:#22366F;"><?= count($lista) ?></div>
            <div class="l">Todas</div>
        </button>
        <button class="rc-k" data-f="proceso" onclick="filtrarEstado('proceso', this)"
                style="border-color:#C9A22755;">
            <div class="n" style="color:#a8871f;"><?= count($enProceso) ?></div>
            <div class="l">En proceso de carga</div>
        </button>
        <button class="rc-k" data-f="completado" onclick="filtrarEstado('completado', this)"
                style="border-color:#2E7D3255;">
            <div class="n" style="color:#2E7D32;"><?= count($completados) ?></div>
            <div class="l">Levantamiento completo</div>
        </button>
    </div>

    <?php if (count($enProceso) > 0): ?>
    <div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;
                padding:10px 13px;margin-top:12px;font-size:12.5px;color:#8a6d1a;">
        <i class="bi bi-hourglass-split"></i>
        <strong><?= count($enProceso) ?></strong> levantamiento(s) sin cerrar.
        Revise si el equipo los dejó a medias.
    </div>
    <?php endif; ?>
</div>

<!-- Listado -->
<div class="rc-card">
    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div style="font-weight:700;color:#22366F;">
            <i class="bi bi-list-ul"></i> Detalle
            <span id="rc-cont" style="background:#eef2fb;color:#22366F;border-radius:12px;
                  padding:2px 9px;font-size:12px;font-weight:700;"><?= count($lista) ?></span>
        </div>
        <input type="text" id="rc-buscar" class="form-control" style="width:210px;"
               placeholder="Buscar edificación…" oninput="filtrarLista()">
    </div>

    <div id="rc-lista">
    <?php foreach ($porParroquia as $parr => $items): ?>
        <div class="rc-parr" data-parr="<?= e($parr) ?>">
            <div style="background:#f7f9fd;padding:8px 13px;border-radius:8px;
                        font-weight:700;color:#22366F;font-size:13px;margin:12px 0 6px;">
                <?= e(mb_strtoupper($parr, 'UTF-8')) ?>
                <span style="float:right;font-weight:600;font-size:11.5px;color:#5b6478;">
                    <?= count($items) ?>
                </span>
            </div>

            <?php foreach ($items as $e):
                $comp = !empty($e['lev_completado']);
                $pctLev = (int)($e['lev_pct'] ?? 0);
                $sim = recSimboloDecision($e['decision_final'] ?? null);
                $col = $comp ? '#2E7D32' : '#C9A227';
            ?>
            <div class="rc-fila" data-estado="<?= $comp ? 'completado' : 'proceso' ?>"
                 data-txt="<?= e(mb_strtolower(($e['nombre_edificio'] ?? '') . ' ' .
                           ($e['codigo'] ?? '') . ' ' . ($e['ente_nombre'] ?? ''), 'UTF-8')) ?>">

                <span class="clas-letra" style="color:<?= $sim['color'] ?>;
                      border-color:<?= $sim['color'] ?>;" title="<?= e($sim['texto']) ?>">
                    <?= $sim['letra'] ?>
                </span>

                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#2a3140;font-size:14px;">
                        <?= e($e['nombre_edificio'] ?: 'Sin nombre') ?>
                    </div>
                    <div style="font-size:11.5px;color:#5b6478;">
                        <?= e($e['codigo']) ?>
                        <?php if (!empty($e['ente_nombre'])): ?>
                            · <?= e($e['ente_nombre']) ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:#767c94;">
                        <?= (int)$e['n_pisos'] ?> piso<?= (int)$e['n_pisos'] === 1 ? '' : 's' ?>
                        · <?= (int)$e['aptos_hechos'] ?> de <?= (int)$e['n_aptos'] ?> apartamentos
                    </div>
                </div>

                <!-- Estado del levantamiento -->
                <div style="min-width:150px;">
                    <div style="font-size:12px;font-weight:700;color:<?= $col ?>;
                                margin-bottom:3px;">
                        <i class="bi bi-<?= $comp ? 'check-circle-fill' : 'hourglass-split' ?>"></i>
                        <?= $comp ? 'Levantamiento completo' : 'En proceso' ?>
                    </div>
                    <?php if (!$comp): ?>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="flex:1;background:#eef0f6;border-radius:20px;height:9px;
                              overflow:hidden;min-width:60px;">
                            <span style="display:block;width:<?= $pctLev ?>%;height:100%;
                                  background:<?= $col ?>;"></span>
                        </span>
                        <span style="font-size:11px;color:#767c94;"><?= $pctLev ?>%</span>
                    </div>
                    <?php endif; ?>
                </div>

                <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$e['id'] ?>"
                   class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i></a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <p id="rc-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
        Ninguna coincide con el filtro.
    </p>
</div>

<script>
let _filtroEstado = 'todos';

function filtrarEstado(f, btn) {
    _filtroEstado = f;
    document.querySelectorAll('.rc-k').forEach(b => b.classList.remove('activo'));
    if (btn) btn.classList.add('activo');
    filtrarLista();
}

function filtrarLista() {
    const t = (document.getElementById('rc-buscar').value || '').toLowerCase().trim();
    let n = 0;

    document.querySelectorAll('.rc-fila').forEach(f => {
        const okEstado = _filtroEstado === 'todos' || f.dataset.estado === _filtroEstado;
        const okTxt = !t || (f.dataset.txt || '').includes(t);
        const ver = okEstado && okTxt;
        f.style.display = ver ? '' : 'none';
        if (ver) n++;
    });

    // Ocultar la parroquia si no le queda ninguna visible.
    document.querySelectorAll('.rc-parr').forEach(g => {
        const vis = Array.from(g.querySelectorAll('.rc-fila'))
                         .filter(x => x.style.display !== 'none').length;
        g.style.display = vis ? '' : 'none';
    });

    document.getElementById('rc-cont').textContent = n;
    document.getElementById('rc-vacio').style.display = n ? 'none' : '';
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
