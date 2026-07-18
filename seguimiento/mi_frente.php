<?php
/**
 * MI FRENTE — panel del rol Frente de Trabajo.
 *
 * Muestra solo las edificaciones asignadas a su frente y permite
 * repartirlas entre sus cuadrillas. Una obra puede tener varias
 * cuadrillas trabajando a la vez (albañilería, plomería, etc.).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$frenteId = frenteDelUsuario();

// Un master o supervisor puede consultar cualquier frente.
if (usuarioEsMaster() && !empty($_GET['frente'])) {
    $frenteId = (int)$_GET['frente'];
}

if ($frenteId <= 0) {
    $pageTitle = 'Mi frente';
    include __DIR__ . '/../includes/header.php';
    ?>
    <div style="background:#fff;border-radius:12px;padding:38px 24px;text-align:center;
                box-shadow:0 2px 10px rgba(20,30,60,.07);">
        <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-diagram-3"></i></div>
        <h3 style="color:#22366F;margin:10px 0 6px;">Su usuario no tiene frente asignado</h3>
        <p class="text-muted" style="margin:0 0 16px;">
            Pida al administrador que lo vincule a un frente de trabajo
            desde <strong>Usuarios</strong>.
        </p>
        <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline">Ir al mapa</a>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Datos del frente.
$st = db()->prepare('SELECT * FROM frente WHERE id = :f');
$st->execute(['f' => $frenteId]);
$frente = $st->fetch();
if (!$frente) {
    flash('error', 'El frente no existe.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$obras     = obrasDeFrente($frenteId);
$cuadrillas = cargaDeCuadrillas($frenteId);
$cat       = catalogoDecisionFinal();

// Resumen
$total = count($obras);
$culminadas = 0; $enObra = 0; $sinIniciar = 0; $suma = 0;
$sinCuadrilla = 0;
foreach ($obras as $o) {
    $a = (int)$o['avance'];
    $suma += $a;
    if ($a >= 100) $culminadas++;
    elseif ($a > 0) $enObra++;
    else $sinIniciar++;
    if (!$o['cuadrillas']) $sinCuadrilla++;
}
$avance = $total ? (int)round($suma / $total) : 0;

$pageTitle    = 'Frente de Trabajo ' . (int)$frente['numero'];
$pageSubtitle = $total . ' edificaciones asignadas';
$activeModule = 'mi_frente';
include __DIR__ . '/../includes/header.php';
?>
<style>
.mf-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.mf-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.mf-kpis { display:flex; gap:9px; flex-wrap:wrap; }
.mf-k { flex:1; min-width:110px; text-align:center; padding:13px 8px; border-radius:10px; border:1px solid; }
.mf-k .n { font-size:26px; font-weight:800; line-height:1; }
.mf-k .l { font-size:10.5px; text-transform:uppercase; color:#55617f; margin-top:4px; }
.mf-obra { border:1px solid #e5e8f0; border-radius:11px; padding:13px 15px; margin-bottom:10px; }
.mf-obra:hover { background:#fafbfe; }
.mf-obra-cab { display:flex; align-items:center; gap:11px; flex-wrap:wrap; }
.clas-letra { display:inline-flex; align-items:center; justify-content:center;
              width:26px; height:26px; border:2px solid; border-radius:6px;
              font-weight:800; font-size:13px; flex-shrink:0; }
.mf-cuad { display:inline-flex; align-items:center; gap:6px; background:#eef2fb; color:#22366F;
           border-radius:20px; padding:4px 11px; font-size:12px; font-weight:600; margin:3px 3px 0 0; }
.mf-barra { flex:0 0 110px; background:#eef0f6; border-radius:20px; height:14px; overflow:hidden; }
.mf-sin { background:#fffbf0; border:1px solid #C9A22755; color:#8a6d1a;
          border-radius:8px; padding:7px 11px; font-size:12.5px; margin-top:8px; }
@media (max-width: 640px) {
    .mf-obra-cab { gap:8px; }
    .mf-obra-cab > div:nth-child(2) { flex:1 1 100%; }
    .mf-barra { flex:1 1 auto; }
}
</style>

<!-- Resumen del frente -->
<div class="mf-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div>
            <div style="font-weight:800;color:#22366F;font-size:19px;">
                Frente de Trabajo <?= (int)$frente['numero'] ?>
                <?php if (!empty($frente['nombre'])): ?>
                <span style="font-weight:500;color:#5b6478;">· <?= e($frente['nombre']) ?></span>
                <?php endif; ?>
            </div>
            <div class="text-sm text-muted"><?= count($cuadrillas) ?> brigada(s) disponibles</div>
        </div>
        <a href="<?= APP_URL_BASE ?>seguimiento/pdf_mi_frente.php?frente=<?= $frenteId ?>"
           target="_blank" class="btn btn-primary btn-sm">
            <i class="bi bi-file-earmark-pdf-fill"></i> Informe PDF
        </a>
    </div>

    <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:12.5px;color:#55617f;margin-bottom:4px;">
            <span>Avance general del frente</span>
            <strong style="font-size:16px;color:<?= $avance>=100?'#2E7D32':($avance>0?'#a8871f':'#97a0b8') ?>;">
                <?= $avance ?>%
            </strong>
        </div>
        <div style="background:#eef0f6;border-radius:20px;height:20px;overflow:hidden;">
            <div style="width:<?= $avance ?>%;height:100%;background:<?= $avance>=100?'#2E7D32':'#C9A227' ?>;
                        transition:width .4s;"></div>
        </div>
    </div>

    <div class="mf-kpis">
        <div class="mf-k" style="border-color:#2d448833;background:#2d44880d;">
            <div class="n" style="color:#2d4488;"><?= $total ?></div><div class="l">Edificaciones</div>
        </div>
        <div class="mf-k" style="border-color:#2E7D3233;background:#2E7D320d;">
            <div class="n" style="color:#2E7D32;"><?= $culminadas ?></div><div class="l">Culminadas</div>
        </div>
        <div class="mf-k" style="border-color:#C9A22733;background:#C9A2270d;">
            <div class="n" style="color:#a8871f;"><?= $enObra ?></div><div class="l">En obra</div>
        </div>
        <div class="mf-k" style="border-color:#97a0b833;">
            <div class="n" style="color:#5b6478;"><?= $sinIniciar ?></div><div class="l">Sin iniciar</div>
        </div>
    </div>

    <?php if ($sinCuadrilla > 0): ?>
    <div class="mf-sin">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong><?= $sinCuadrilla ?></strong> edificación(es) todavía sin brigada asignada.
    </div>
    <?php endif; ?>
</div>

<!-- Cuadrillas del frente -->
<?php if ($cuadrillas): ?>
<div class="mf-card">
    <div class="mf-tit"><i class="bi bi-people-fill"></i> Mis brigadas</div>
    <div style="display:flex;gap:9px;flex-wrap:wrap;">
        <?php foreach ($cuadrillas as $c): ?>
        <div style="flex:1;min-width:150px;border:1px solid #e5e8f0;border-radius:10px;padding:11px 13px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="background:#2d4488;color:#fff;width:24px;height:24px;border-radius:6px;
                             display:flex;align-items:center;justify-content:center;font-weight:800;
                             font-size:12px;"><?= (int)$c['numero'] ?></span>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#2a3140;font-size:13px;">
                        Brigada <?= (int)$c['numero'] ?>
                    </div>

                </div>
            </div>
            <div style="font-size:11.5px;color:#5b6478;margin-top:7px;">
                <strong style="color:#22366F;"><?= (int)$c['obras'] ?></strong> obra(s) ·
                <?= (int)$c['personas'] ?> persona(s)
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="mf-card">
    <div class="mf-sin" style="margin:0;">
        <i class="bi bi-info-circle-fill"></i>
        Este frente todavía no tiene brigadas.
        Pida al administrador que las cree desde <strong>Frentes de trabajo</strong>.
    </div>
</div>
<?php endif; ?>

<!-- Edificaciones -->
<div class="mf-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div class="mf-tit" style="margin:0;">
            <i class="bi bi-buildings-fill"></i> Mis edificaciones
            <span id="mf-cont" style="background:#eef2fb;color:#22366F;border-radius:12px;
                  padding:2px 9px;font-size:12px;font-weight:700;"><?= $total ?></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="text" id="mf-buscar" class="form-control" style="width:190px;"
                   placeholder="Buscar…" oninput="filtrarObras()">
            <select id="mf-filtro" class="form-control" style="width:165px;" onchange="filtrarObras()">
                <option value="">Todas</option>
                <option value="sin">Sin brigada</option>
                <option value="proceso">En obra</option>
                <option value="listo">Culminadas</option>
            </select>
        </div>
    </div>

    <?php if (!$obras): ?>
        <p class="text-muted" style="margin:0;">
            Todavía no hay edificaciones asignadas a este frente.
        </p>
    <?php else: ?>
    <div id="mf-lista">
    <?php foreach ($obras as $o):
        $av = (int)$o['avance'];
        $col = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#a8871f' : '#97a0b8'));
        $sim = recSimboloDecision($o['decision_final'] ?? null);
        $estado = $av >= 100 ? 'listo' : ($av > 0 ? 'proceso' : 'sin_iniciar');
        $tieneCuad = !empty($o['cuadrillas']);
    ?>
    <div class="mf-obra" data-txt="<?= e(mb_strtolower(($o['nombre_edificio'] ?? '') . ' ' .
              ($o['codigo'] ?? '') . ' ' . ($o['parroquia'] ?? ''), 'UTF-8')) ?>"
         data-estado="<?= $estado ?>" data-cuad="<?= $tieneCuad ? '1' : '0' ?>">

        <div class="mf-obra-cab">
            <span class="clas-letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"
                  title="<?= e($sim['texto']) ?>"><?= $sim['letra'] ?></span>

            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:14.5px;">
                    <?= e($o['nombre_edificio'] ?: 'Sin nombre') ?>
                </div>
                <div style="font-size:11.5px;color:#5b6478;">
                    <?= e($o['codigo']) ?> · <?= e($o['parroquia'] ?: '—') ?>
                </div>
            </div>

            <div class="mf-barra">
                <div style="width:<?= $av ?>%;height:100%;background:<?= $col ?>;"></div>
            </div>
            <span style="font-weight:800;color:<?= $col ?>;min-width:44px;text-align:right;"><?= $av ?>%</span>

            <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$o['id'] ?>"
               class="btn btn-outline btn-sm" title="Abrir la ficha">
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <!-- Cuadrillas trabajando aquí -->
        <div style="margin-top:9px;padding-top:9px;border-top:1px solid #f0f2f7;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:11.5px;color:#55617f;font-weight:600;">Brigadas:</span>

                <?php if ($tieneCuad): foreach ($o['cuadrillas'] as $c): ?>
                <span class="mf-cuad">
                    Brigada <?= (int)$c['numero'] ?>
                    <a href="#" onclick="quitarCuadrilla(<?= (int)$o['id'] ?>,<?= (int)$c['brigada_id'] ?>);return false;"
                       style="color:#97a0b8;text-decoration:none;">&times;</a>
                </span>
                <?php endforeach; else: ?>
                <span style="font-size:12px;color:#a8871f;font-style:italic;">Ninguna asignada</span>
                <?php endif; ?>

                <?php if ($cuadrillas): ?>
                <button class="btn btn-outline btn-sm" style="font-size:12px;padding:3px 10px;"
                        onclick="abrirAsignar(<?= (int)$o['id'] ?>, '<?= e(addslashes($o['nombre_edificio'])) ?>')">
                    <i class="bi bi-plus-lg"></i> Asignar
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <p id="mf-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
        Ninguna coincide con el filtro.
    </p>
    <?php endif; ?>
</div>

<script>
const URL_F = '<?= APP_URL_BASE ?>seguimiento/guardar_frente.php';
const BRIGADAS = <?= json_encode(array_map(fn($c) => [
    'id' => (int)$c['id'], 'numero' => (int)$c['numero'],
], $cuadrillas), JSON_UNESCAPED_UNICODE) ?>;

function filtrarObras() {
    const t = (document.getElementById('mf-buscar').value || '').toLowerCase().trim();
    const f = document.getElementById('mf-filtro').value;
    let n = 0;
    document.querySelectorAll('.mf-obra').forEach(o => {
        const okTxt = !t || (o.dataset.txt || '').includes(t);
        let okF = true;
        if (f === 'sin')     okF = o.dataset.cuad === '0';
        else if (f === 'proceso') okF = o.dataset.estado === 'proceso';
        else if (f === 'listo')   okF = o.dataset.estado === 'listo';
        const ver = okTxt && okF;
        o.style.display = ver ? '' : 'none';
        if (ver) n++;
    });
    document.getElementById('mf-cont').textContent = n;
    document.getElementById('mf-vacio').style.display = n ? 'none' : '';
}

/** Ventana para asignar una o varias cuadrillas a la edificación. */
function abrirAsignar(inspeccionId, nombre) {
    if (!BRIGADAS.length) { alert('Este frente no tiene brigadas.'); return; }

    const opciones = BRIGADAS.map(c =>
        '<label style="display:flex;align-items:center;gap:10px;padding:11px 12px;'
        + 'border:1px solid #e5e8f0;border-radius:9px;margin-bottom:8px;cursor:pointer;">'
        + '<input type="checkbox" class="asig-cuad" value="' + c.id + '" style="width:19px;height:19px;">'
        + '<span style="background:#2d4488;color:#fff;width:24px;height:24px;border-radius:6px;'
        + 'display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;">'
        + c.numero + '</span>'
        + '<span style="flex:1;font-size:13.5px;color:#2a3140;font-weight:600;">Brigada ' + c.numero
        + (c.especialidad ? '<span style="font-weight:400;color:#5b6478;"> · ' + c.especialidad + '</span>' : '')
        + '</span></label>').join('');

    const capa = document.createElement('div');
    capa.id = 'mf-modal';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2300;'
        + 'display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto;';
    capa.innerHTML =
        '<div style="background:#fff;border-radius:13px;max-width:440px;width:100%;padding:20px 22px;">'
        + '<div style="font-weight:700;color:#22366F;font-size:17px;margin-bottom:3px;">Asignar brigadas</div>'
        + '<div style="font-size:13px;color:#5b6478;margin-bottom:14px;">' + nombre + '</div>'
        + '<div style="font-size:12.5px;color:#5b6478;margin-bottom:10px;">'
        + 'Puede marcar varias brigadas para una misma edificación.</div>'
        + opciones

        + '<button onclick="guardarAsignacion(' + inspeccionId + ')" class="btn btn-primary" '
        + 'style="width:100%;justify-content:center;margin-bottom:8px;">'
        + '<i class="bi bi-check-lg"></i> Asignar</button>'
        + '<button onclick="document.getElementById(\'mf-modal\').remove()" '
        + 'style="width:100%;background:transparent;border:1px solid #dbe0ec;border-radius:8px;'
        + 'padding:10px;color:#55617f;cursor:pointer;font-size:14px;">Cancelar</button>'
        + '</div>';
    document.body.appendChild(capa);
}

async function guardarAsignacion(inspeccionId) {
    const marcadas = Array.from(document.querySelectorAll('.asig-cuad:checked')).map(c => parseInt(c.value));
    if (!marcadas.length) { alert('Marque al menos una brigada.'); return; }
    for (const cid of marcadas) {
        const ok = await api({ accion: 'asignar_obra_brigada',
                               inspeccion_id: inspeccionId, brigada_id: cid });
        if (!ok) return;
    }
    location.reload();
}

async function quitarCuadrilla(inspeccionId, cuadrillaId) {
    if (!confirm('¿Quitar esta brigada de la edificación?')) return;
    const ok = await api({ accion: 'quitar_obra_brigada',
                           inspeccion_id: inspeccionId, brigada_id: cuadrillaId });
    if (ok) location.reload();
}

async function api(datos) {
    try {
        const res = await fetch(URL_F, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(datos), credentials: 'same-origin'
        });
        const d = await res.json();
        if (!d.ok) { alert(d.mensaje || 'No se pudo guardar.'); return false; }
        return true;
    } catch (e) {
        alert('Sin conexión. Intente de nuevo.');
        return false;
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
