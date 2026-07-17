<?php
/**
 * Ficha de seguimiento de un edificio (= remodelación, todo en una página).
 * Muestra:
 *   - Datos actualizados de la inspección.
 *   - Barra de avance general (promedio de los apartamentos).
 *   - Lista de pisos y apartamentos.
 *   - Por cada apartamento: fotos del "antes" (levantamiento, detallando el
 *     lugar de cada foto), botón para subir la foto del "durante", y la barra
 *     de progreso (que se habilita solo cuando ya hay foto del durante).
 * El avance es por APARTAMENTO. Solo el rol sistematizador puede cargar
 * fotos del durante y mover el %.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeCargar = esSistematizador();

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) {
    flash('error', 'Edificio no especificado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$ed = recEdificio($inspeccionId);
$edificioId = (int)$ed['id'];

$cat = catalogoDecisionFinal();
$dec = $insp['decision_final'] ?? '';
$colorDec = $cat[$dec]['color'] ?? '#767c94';
$cortoDec = $cat[$dec]['corto'] ?? ($dec ?: 'Sin clasificar');

$pageTitle    = 'Ficha de seguimiento: ' . $insp['nombre_edificio'];
$pageSubtitle = trim(($insp['parroquia'] ?? '') . ' · ' . ($insp['municipio'] ?? ''), ' ·');
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .fs-wrap { max-width:960px; margin:0 auto; }
    .fs-card { background:#fff; border:1px solid #e6e9f2; border-radius:12px; margin-bottom:16px; overflow:hidden; }
    .fs-datos { padding:16px 20px; display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
    .fs-dato .l { font-size:11px; color:#8b91a3; text-transform:uppercase; letter-spacing:.4px; }
    .fs-dato .v { font-size:14px; color:#2a3140; font-weight:600; }
    .fs-barra-g { background:#eef0f5; border-radius:12px; height:24px; overflow:hidden; }
    .fs-barra-g > div { height:100%; background:linear-gradient(90deg,#2E7D32,#43a047); color:#fff; font-size:13px; line-height:24px; text-align:right; padding-right:10px; transition:width .3s; }
    .piso-h { background:#22366F; color:#fff; padding:10px 16px; font-weight:600; font-size:15px; }
    .apto { border-bottom:1px solid #eef0f5; padding:14px 18px; }
    .apto-top { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .apto-nom { font-weight:700; color:#22366F; font-size:15px; }
    .fotos-fila { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; }
    .foto-item { text-align:center; }
    .foto-item img { width:92px; height:92px; object-fit:cover; border-radius:8px; border:1px solid #d8dce6; }
    .foto-item .cap { font-size:10px; color:#767c94; margin-top:2px; max-width:92px; }
    .col-fotos { flex:1; min-width:200px; }
    .col-fotos .tit { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
    .col-antes .tit { color:#A61C1C; }
    .col-durante .tit { color:#2E7D32; }
    .apto-barra { background:#eef0f5; border-radius:10px; height:12px; overflow:hidden; margin-top:8px; }
    .apto-barra > div { height:100%; background:#2E7D32; transition:width .3s; }
    .aviso-durante { font-size:12px; color:#C9A227; margin-top:6px; }
</style>

<div class="fs-wrap">

    <!-- Datos de la inspección (actualizados) -->
    <div class="fs-card">
        <div style="background:<?= $colorDec ?>;color:#fff;padding:14px 20px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.85;">Edificación</div>
            <div style="font-size:20px;font-weight:700;"><?= e($insp['nombre_edificio'] ?: 'Sin nombre') ?></div>
            <div style="font-size:12px;opacity:.9;margin-top:2px;"><i class="bi bi-circle-fill" style="font-size:8px;"></i> <?= e($cortoDec) ?> · <?= e($insp['codigo'] ?? '') ?></div>
        </div>
        <div class="fs-datos">
            <?php
            $datos = [
                ['Parroquia', $insp['parroquia'] ?? '—'],
                ['Municipio', $insp['municipio'] ?? '—'],
                ['Dirección', $insp['avenida_calle'] ?? '—'],
                ['Uso', $insp['uso_edificacion'] ?? '—'],
                ['Familias', $insp['familias'] ?? '—'],
                ['Personas', $insp['numero_personas'] ?? '—'],
                ['Pisos', $insp['num_pisos'] ?? '—'],
                ['Fecha inspección', $insp['fecha_inspeccion'] ?? '—'],
            ];
            foreach ($datos as [$l, $v]):
            ?>
            <div class="fs-dato"><div class="l"><?= $l ?></div><div class="v"><?= e((string)$v) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Barra de avance general -->
    <div class="fs-card" style="padding:16px 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
            <div style="font-weight:700;color:#22366F;"><i class="bi bi-graph-up-arrow"></i> Avance general de la remodelación</div>
            <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver al mapa</a>
        </div>
        <div class="fs-barra-g"><div id="barra-global" style="width:0%;">0%</div></div>
        <?php if (!$puedeCargar): ?>
        <p style="margin:10px 0 0;font-size:12px;color:#C9A227;"><i class="bi bi-info-circle"></i> Solo el rol sistematizador puede subir fotos del "durante" y registrar el avance.</p>
        <?php endif; ?>
    </div>

    <!-- Pisos y apartamentos -->
    <div id="fs-pisos"><p class="text-muted">Cargando pisos…</p></div>
</div>

<input type="file" id="fs-file" accept="image/*" style="display:none;" onchange="_onDuranteElegida(this)">

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const EDIFICIO_ID = <?= $edificioId ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_CARGAR = <?= $puedeCargar ? 'true' : 'false' ?>;
let _duranteDestino = null;

async function cargarFicha() {
    const res = await fetch(URL_BASE + 'ficha_seguimiento_data.php?edificio_id=' + EDIFICIO_ID);
    const d = await res.json();
    if (!d.ok) { document.getElementById('fs-pisos').innerHTML = '<p class="text-muted">No se pudo cargar.</p>'; return; }

    // Barra global
    const bg = document.getElementById('barra-global');
    bg.style.width = d.avance_global + '%';
    bg.textContent = d.avance_global + '%';

    const cont = document.getElementById('fs-pisos');
    if (!d.pisos.length) { cont.innerHTML = '<p class="text-muted">Este edificio aún no tiene pisos registrados en el levantamiento.</p>'; return; }

    let html = '';
    d.pisos.forEach(piso => {
        let aptos = '';
        piso.aptos.forEach(ap => {
            // Fotos antes: apartamento + las de sus ambientes (con el lugar).
            let antes = '';
            ap.fotos_antes.forEach(f => { antes += fotoHTML(f.ruta, 'Apartamento'); });
            ap.ambientes.forEach(am => {
                am.fotos_antes.forEach(f => { antes += fotoHTML(f.ruta, am.tipo + ' ' + am.numero); });
            });
            if (!antes) antes = '<div style="color:#9aa1b4;font-size:12px;">Sin fotos del levantamiento</div>';

            // Fotos durante
            let durante = '';
            ap.fotos_durante.forEach(f => { durante += fotoHTML(f.ruta, 'Durante'); });
            ap.ambientes.forEach(am => {
                am.fotos_durante.forEach(f => { durante += fotoHTML(f.ruta, am.tipo + ' ' + am.numero); });
            });
            if (!durante) durante = '<div style="color:#9aa1b4;font-size:12px;">Sin fotos aún</div>';

            // Control de foto del durante (solo sistematizador)
            const btnFoto = PUEDE_CARGAR
                ? `<button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;" onclick="subirDurante(${ap.id})"><i class="bi bi-camera"></i> Subir foto del durante</button>`
                : '';

            // Barra de progreso: se habilita solo si ya hay foto del durante.
            let barra = '';
            if (PUEDE_CARGAR) {
                if (ap.tiene_durante) {
                    barra = `<div style="margin-top:10px;">
                        <label style="font-size:12px;font-weight:600;">Avance del apartamento: <span id="lbl-${ap.id}">${ap.avance}%</span></label>
                        <input type="range" min="0" max="100" value="${ap.avance}" style="width:100%;"
                               oninput="document.getElementById('lbl-${ap.id}').textContent=this.value+'%'"
                               onchange="guardarAvance(${ap.id}, this.value)">
                    </div>`;
                } else {
                    barra = `<div class="aviso-durante"><i class="bi bi-exclamation-circle"></i> Suba primero una foto del "durante" para habilitar la barra de avance.</div>`;
                }
            } else {
                barra = `<div class="apto-barra"><div style="width:${ap.avance}%;"></div></div>
                         <div style="font-size:11px;color:#55617f;margin-top:2px;">${ap.avance}% de avance</div>`;
            }

            aptos += `
                <div class="apto">
                    <div class="apto-top">
                        <span class="apto-nom"><i class="bi bi-door-open"></i> Apartamento ${ap.identificador}</span>
                        <span style="font-size:13px;color:#2E7D32;font-weight:700;">${ap.avance}%</span>
                    </div>
                    <div class="fotos-fila">
                        <div class="col-fotos col-antes"><div class="tit">Antes</div><div class="fotos-fila">${antes}</div></div>
                        <div class="col-fotos col-durante"><div class="tit">Durante</div><div class="fotos-fila">${durante}</div>${btnFoto}</div>
                    </div>
                    ${barra}
                </div>`;
        });
        if (!piso.aptos.length) aptos = '<div class="apto" style="color:#9aa1b4;">Sin apartamentos en este piso.</div>';
        html += `<div class="fs-card"><div class="piso-h"><i class="bi bi-layers"></i> Piso ${piso.numero}</div>${aptos}</div>`;
    });
    cont.innerHTML = html;
}

function fotoHTML(ruta, cap) {
    return `<div class="foto-item"><img src="${ruta}"><div class="cap">${cap}</div></div>`;
}

function subirDurante(aptoId) {
    _duranteDestino = aptoId;
    document.getElementById('fs-file').click();
}

async function _onDuranteElegida(input) {
    if (!input.files || !input.files[0] || !_duranteDestino) { input.value=''; return; }
    const fd = new FormData();
    fd.append('nivel', 'apartamento');
    fd.append('ref_id', _duranteDestino);
    fd.append('parte', 'durante');
    fd.append('foto', input.files[0]);
    input.value = '';
    const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.ok) cargarFicha();   // recargar: ahora tendrá foto durante y se habilita la barra
    else alert(data.mensaje || 'No se pudo subir.');
    _duranteDestino = null;
}

async function guardarAvance(aptoId, valor) {
    const res = await fetch(URL_BASE + 'guardar_avance.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ apartamento_id: aptoId, porcentaje: parseInt(valor), edificio_id: EDIFICIO_ID })
    });
    const data = await res.json();
    if (data.ok && data.avance_global !== null) {
        const bg = document.getElementById('barra-global');
        bg.style.width = data.avance_global + '%';
        bg.textContent = data.avance_global + '%';
    }
}

cargarFicha();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
