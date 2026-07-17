<?php
/**
 * Seguimiento de remodelación de un edificio (fase Durante/Después).
 * Muestra cada ambiente que necesita reparación con su foto "Antes"
 * (del levantamiento) al lado del "Durante". El rol sistematizador
 * carga las fotos del durante e indica el % de avance de cada ambiente.
 * El apartamento y el edificio promedian automáticamente.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeCargar = esSistematizador();   // solo el sistematizador carga durante/avance

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) {
    flash('error', 'Edificio no especificado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$ed = recEdificio($inspeccionId);
$edificioId = (int)$ed['id'];
$avanceGlobal = recAvanceEdificio($edificioId);

$pageTitle    = 'Remodelación: ' . $insp['nombre_edificio'];
$pageSubtitle = trim(($insp['parroquia'] ?? '') . ' · Seguimiento de avance', ' ·');
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .rem-wrap { max-width:920px; margin:0 auto; }
    .rem-global { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:20px 24px; margin-bottom:18px; }
    .rem-barra { background:#eef0f5; border-radius:12px; height:22px; overflow:hidden; margin-top:8px; }
    .rem-barra > div { height:100%; background:linear-gradient(90deg,#2E7D32,#43a047); text-align:right; color:#fff; font-size:12px; line-height:22px; padding-right:8px; }
    .apto-block { background:#fff; border:1px solid #e6e9f2; border-radius:12px; margin-bottom:14px; overflow:hidden; }
    .apto-h { padding:12px 18px; background:#f2f5fc; font-weight:600; color:#22366F; display:flex; justify-content:space-between; align-items:center; }
    .amb-block { padding:14px 18px; border-bottom:1px solid #f0f2f7; }
    .comp-fotos { display:flex; gap:14px; flex-wrap:wrap; margin-top:8px; }
    .foto-col { flex:1; min-width:150px; }
    .foto-col .lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
    .foto-col img { width:100%; max-width:220px; border-radius:8px; border:1px solid #d8dce6; }
    .foto-antes .lbl { color:#A61C1C; }
    .foto-durante .lbl { color:#2E7D32; }
    .rango-avance { width:100%; }
    @media (max-width:640px){ .foto-col{ min-width:calc(50% - 7px); } }
</style>

<div class="rem-wrap">

    <div class="rem-global">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div>
                <h3 style="margin:0;color:#22366F;"><i class="bi bi-building-gear"></i> <?= e($insp['nombre_edificio']) ?></h3>
                <p style="margin:2px 0 0;color:#767c94;font-size:13px;">Avance global de la remodelación</p>
            </div>
            <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
        <div class="rem-barra"><div id="barra-global" style="width:<?= $avanceGlobal ?>%;"><?= $avanceGlobal ?>%</div></div>
        <?php if (!$puedeCargar): ?>
        <p style="margin:10px 0 0;font-size:12px;color:#C9A227;"><i class="bi bi-info-circle"></i> Solo el rol sistematizador puede cargar fotos del "durante" y registrar avance.</p>
        <?php endif; ?>
    </div>

    <div id="rem-lista"><p class="text-muted">Cargando ambientes…</p></div>
</div>

<input type="file" id="rem-file" accept="image/*" style="display:none;" onchange="_onDuranteElegida(this)">

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const EDIFICIO_ID = <?= $edificioId ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_CARGAR = <?= $puedeCargar ? 'true' : 'false' ?>;
let _duranteDestino = null;

async function cargarRemodelacion() {
    const res = await fetch(URL_BASE + 'listar_remodelacion.php?edificio_id=' + EDIFICIO_ID);
    const d = await res.json();
    const cont = document.getElementById('rem-lista');
    if (!d.ok || !d.apartamentos.length) {
        cont.innerHTML = '<p class="text-muted">No hay ambientes con reparación registrados en este edificio.</p>';
        return;
    }
    let html = '';
    d.apartamentos.forEach(ap => {
        let ambs = '';
        ap.ambientes.forEach(am => {
            const antes = am.foto_antes
                ? `<img src="${am.foto_antes}">`
                : '<div style="color:#9aa1b4;font-size:12px;">Sin foto del levantamiento</div>';
            const durante = am.foto_durante
                ? `<img src="${am.foto_durante}">`
                : '<div style="color:#9aa1b4;font-size:12px;">Sin foto aún</div>';
            const controlCarga = PUEDE_CARGAR ? `
                <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;" onclick="subirDurante(${am.id})">
                    <i class="bi bi-camera"></i> Foto del durante
                </button>` : '';
            const controlAvance = PUEDE_CARGAR ? `
                <div style="margin-top:10px;">
                    <label style="font-size:12px;font-weight:600;">Avance: <span id="lbl-${am.id}">${am.avance}%</span></label>
                    <input type="range" min="0" max="100" value="${am.avance}" class="rango-avance"
                           oninput="document.getElementById('lbl-${am.id}').textContent=this.value+'%'"
                           onchange="guardarAvance(${am.id}, this.value)">
                </div>`
                : `<div style="margin-top:8px;font-size:13px;"><b>Avance:</b> ${am.avance}%</div>`;
            ambs += `
                <div class="amb-block">
                    <div style="font-weight:600;color:#2a3140;">${am.tipo} ${am.numero}</div>
                    <div class="comp-fotos">
                        <div class="foto-col foto-antes"><div class="lbl">Antes</div>${antes}</div>
                        <div class="foto-col foto-durante"><div class="lbl">Durante</div>${durante}${controlCarga}</div>
                    </div>
                    ${controlAvance}
                </div>`;
        });
        html += `
            <div class="apto-block">
                <div class="apto-h">
                    <span><i class="bi bi-door-open"></i> Apartamento ${ap.identificador}</span>
                    <span style="font-size:13px;">${ap.avance}%</span>
                </div>
                ${ambs}
            </div>`;
    });
    cont.innerHTML = html;
}

function subirDurante(ambId) {
    _duranteDestino = ambId;
    document.getElementById('rem-file').click();
}

async function _onDuranteElegida(input) {
    if (!input.files || !input.files[0] || !_duranteDestino) { input.value=''; return; }
    const fd = new FormData();
    fd.append('nivel', 'ambiente');
    fd.append('ref_id', _duranteDestino);
    fd.append('parte', 'durante');
    fd.append('foto', input.files[0]);
    input.value = '';
    const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.ok) cargarRemodelacion();
    else alert(data.mensaje || 'No se pudo subir.');
    _duranteDestino = null;
}

async function guardarAvance(ambId, valor) {
    const res = await fetch(URL_BASE + 'guardar_avance.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ ambiente_id: ambId, porcentaje: parseInt(valor), edificio_id: EDIFICIO_ID })
    });
    const data = await res.json();
    if (data.ok) {
        // Actualizar barra global y avance del apartamento sin recargar todo.
        const b = document.getElementById('barra-global');
        b.style.width = data.avance_global + '%';
        b.textContent = data.avance_global + '%';
    }
}

cargarRemodelacion();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
