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
.hidden { display: none !important; }
.piso-h:hover { background: #f7f9fd; }

/* ================= RESPONSIVE ================= */
@media (max-width: 640px) {
    /* Encabezado del piso: el nombre arriba, la barra debajo */
    .piso-h { flex-wrap: wrap !important; gap: 8px !important; padding: 12px 14px !important; }
    .piso-h > span:nth-of-type(1) { flex: 1 1 100% !important; }
    .piso-h .barra-piso { flex: 1 1 auto !important; width: auto !important; min-width: 120px; }

    /* Filas de apartamento: se apilan para que el slider sea usable */
    .fs-apto-fila { flex-wrap: wrap !important; gap: 8px !important; }
    .fs-apto-info { flex: 1 1 100% !important; }
    .fs-apto-control { flex: 1 1 100% !important; }
    .fs-apto-control input[type=range] { width: 100% !important; }

    /* Modal de fotos a pantalla casi completa */
    #fs-modal-fotos > div { max-height: 94vh !important; }
    .fotos-fila { flex-direction: column !important; }
    .col-fotos { width: 100% !important; }
}
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
            <div style="display:flex;gap:8px;">
                <a href="<?= APP_URL_BASE ?>seguimiento/trazabilidad.php?inspeccion=<?= $inspeccionId ?>" class="btn btn-outline btn-sm" title="Quién hizo cada cosa">
                    <i class="bi bi-shield-check"></i> Trazabilidad
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver al mapa</a>
            </div>
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

<!-- Modal de fotos (Antes / Durante) del apartamento -->
<div id="fs-modal-fotos" style="display:none;position:fixed;inset:0;background:rgba(20,25,40,.55);z-index:1300;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:12px;max-width:900px;width:100%;max-height:88vh;overflow-y:auto;">
        <div style="background:#22366F;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">
            <b id="fs-modal-tit">Apartamento</b>
            <button onclick="cerrarModalFotos()" style="background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="fs-modal-body" style="padding:16px 18px;"></div>
    </div>
</div>

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const EDIFICIO_ID = <?= $edificioId ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_CARGAR = <?= $puedeCargar ? 'true' : 'false' ?>;
let _duranteDestino = null;

let _arbol = null;   // árbol de pisos/apartamentos con porcentajes

// Carga instantánea: solo pisos y porcentajes (sin fotos).
async function cargarFicha() {
    const res = await fetch(URL_BASE + 'arbol_avance.php?inspeccion=' + INSPECCION_ID);
    const d = await res.json();
    if (!d.ok) {
        document.getElementById('fs-pisos').innerHTML = '<p class="text-muted">' + (d.mensaje || 'No se pudo cargar.') + '</p>';
        return;
    }
    _arbol = d;
    pintarBarraGlobal(d.avance_edificio);
    pintarPisos(d.pisos);
}

function pintarBarraGlobal(pct) {
    const bg = document.getElementById('barra-global');
    bg.style.width = pct + '%';
    bg.textContent = pct + '%';
}

function colorPct(p) {
    if (p >= 100) return '#2E7D32';
    if (p > 0)    return '#C9A227';
    return '#97a0b8';
}

// Lista de pisos con su % (desplegable a apartamentos).
function pintarPisos(pisos) {
    const cont = document.getElementById('fs-pisos');
    if (!pisos.length) {
        cont.innerHTML = '<p class="text-muted">Este edificio aún no tiene pisos registrados en el levantamiento.</p>';
        return;
    }
    cont.innerHTML = pisos.map(piso => `
        <div class="fs-card" id="piso-card-${piso.piso_id}" style="padding:0;overflow:hidden;">
            <div class="piso-h" style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:14px 18px;"
                 onclick="togglePisoFicha(${piso.piso_id})">
                <i class="bi bi-chevron-right" id="chev-${piso.piso_id}" style="transition:transform .2s;"></i>
                <i class="bi bi-layers"></i>
                <span style="flex:1;font-weight:700;">${piso.etiqueta}</span>
                <span style="font-size:12px;color:#767c94;">${piso.apartamentos.length} apto(s)</span>
                <div class="barra-piso" style="width:140px;background:#eef0f6;border-radius:20px;height:18px;position:relative;overflow:hidden;">
                    <div style="width:${piso.avance}%;background:${colorPct(piso.avance)};height:100%;transition:width .3s;"></div>
                </div>
                <span style="font-weight:800;color:${colorPct(piso.avance)};min-width:44px;text-align:right;"
                      id="pct-piso-${piso.piso_id}">${piso.avance}%</span>
            </div>
            <div class="piso-aptos hidden" id="piso-aptos-${piso.piso_id}" style="padding:0 18px 14px;"></div>
        </div>`).join('');
}

// Despliega un piso: muestra sus apartamentos con % (y carga fotos bajo demanda).
function togglePisoFicha(pisoId) {
    const cont = document.getElementById('piso-aptos-' + pisoId);
    const chev = document.getElementById('chev-' + pisoId);
    const abierto = !cont.classList.contains('hidden');
    if (abierto) {
        cont.classList.add('hidden');
        chev.style.transform = 'rotate(0deg)';
        return;
    }
    cont.classList.remove('hidden');
    chev.style.transform = 'rotate(90deg)';
    if (cont.dataset.pintado) return;
    cont.dataset.pintado = '1';

    const piso = _arbol.pisos.find(p => p.piso_id === pisoId);
    if (!piso || !piso.apartamentos.length) {
        cont.innerHTML = '<div style="color:#9aa1b4;padding:8px 0;">Sin apartamentos en este piso.</div>';
        return;
    }
    cont.innerHTML = piso.apartamentos.map(ap => filaApartamento(ap, pisoId)).join('');
}

function filaApartamento(ap, pisoId) {
    const jefe = ap.jefe_nombre
        ? `<div style="font-size:11px;color:#767c94;">${ap.jefe_nombre}${ap.jefe_telefono ? ' · ' + ap.jefe_telefono : ''}</div>`
        : '';
    // Control de avance: requiere foto del "durante".
    let control = '';
    if (PUEDE_CARGAR) {
        if (ap.tiene_foto_durante) {
            control = `<input type="range" min="0" max="100" value="${ap.avance}" style="width:150px;"
                          oninput="document.getElementById('pct-apto-${ap.id}').textContent=this.value+'%'"
                          onchange="guardarAvance(${ap.id}, this.value, ${pisoId})">`;
        } else {
            control = `<button type="button" class="btn btn-outline btn-sm" onclick="subirDurante(${ap.id})">
                          <i class="bi bi-camera"></i> Foto del durante
                       </button>`;
        }
    } else {
        control = `<div style="width:150px;background:#eef0f6;border-radius:20px;height:14px;overflow:hidden;">
                      <div style="width:${ap.avance}%;background:${colorPct(ap.avance)};height:100%;"></div>
                   </div>`;
    }
    return `
        <div class="fs-apto-fila" style="display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px solid #f0f2f7;">
            <div class="fs-apto-info" style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:13px;">
                    <i class="bi bi-door-open"></i> Apartamento ${ap.identificador}
                </div>
                ${jefe}
            </div>
            <div class="fs-apto-control" style="display:flex;align-items:center;gap:10px;">
                ${control}
                <span style="font-weight:800;color:${colorPct(ap.avance)};min-width:44px;text-align:right;"
                      id="pct-apto-${ap.id}">${ap.avance}%</span>
                <button type="button" class="btn btn-outline btn-sm" title="Ver fotos"
                        onclick="verFotosApto(${ap.id}, '${ap.identificador}')"><i class="bi bi-images"></i></button>
            </div>
        </div>`;
}

// Recalcula en pantalla el % del piso y del edificio (sin recargar).
function recalcularEnPantalla(pisoId) {
    const piso = _arbol.pisos.find(p => p.piso_id === pisoId);
    if (!piso) return;
    // % del piso = promedio de sus apartamentos
    const suma = piso.apartamentos.reduce((s, a) => s + a.avance, 0);
    piso.avance = piso.apartamentos.length ? Math.round(suma / piso.apartamentos.length) : 0;
    const lblPiso = document.getElementById('pct-piso-' + pisoId);
    if (lblPiso) {
        lblPiso.textContent = piso.avance + '%';
        lblPiso.style.color = colorPct(piso.avance);
        const barra = lblPiso.previousElementSibling.firstElementChild;
        if (barra) { barra.style.width = piso.avance + '%'; barra.style.background = colorPct(piso.avance); }
    }
    // % del edificio = promedio de los pisos
    const sumaP = _arbol.pisos.reduce((s, p) => s + p.avance, 0);
    _arbol.avance_edificio = _arbol.pisos.length ? Math.round(sumaP / _arbol.pisos.length) : 0;
    pintarBarraGlobal(_arbol.avance_edificio);
}

// Ver fotos de un apartamento (bajo demanda, para no cargar todo de golpe).
async function verFotosApto(aptoId, ident) {
    const cont = document.getElementById('fs-modal-fotos');
    const cuerpo = document.getElementById('fs-modal-body');
    document.getElementById('fs-modal-tit').textContent = 'Apartamento ' + ident;
    cuerpo.innerHTML = '<p class="text-muted">Cargando fotos…</p>';
    cont.style.display = 'flex';
    try {
        const res = await fetch(URL_BASE + 'ficha_seguimiento_data.php?edificio_id=' + EDIFICIO_ID + '&apto=' + aptoId);
        const d = await res.json();
        if (!d.ok) { cuerpo.innerHTML = '<p class="text-muted">No se pudieron cargar las fotos.</p>'; return; }
        let ap = null;
        (d.pisos || []).forEach(p => (p.aptos || []).forEach(a => { if (a.id === aptoId) ap = a; }));
        if (!ap) { cuerpo.innerHTML = '<p class="text-muted">Sin fotos registradas.</p>'; return; }

        let antes = '';
        (ap.fotos_antes || []).forEach(f => { antes += fotoHTML(f.ruta, 'Apartamento'); });
        (ap.ambientes || []).forEach(am => (am.fotos_antes || []).forEach(f => { antes += fotoHTML(f.ruta, am.tipo + ' ' + am.numero); }));
        if (!antes) antes = '<div style="color:#9aa1b4;font-size:12px;">Sin fotos del levantamiento</div>';

        let durante = '';
        (ap.fotos_durante || []).forEach(f => { durante += fotoHTML(f.ruta, 'Durante'); });
        (ap.ambientes || []).forEach(am => (am.fotos_durante || []).forEach(f => { durante += fotoHTML(f.ruta, am.tipo + ' ' + am.numero); }));
        if (!durante) durante = '<div style="color:#9aa1b4;font-size:12px;">Sin fotos aún</div>';

        const btnFoto = PUEDE_CARGAR
            ? `<button type="button" class="btn btn-outline btn-sm" style="margin-top:8px;" onclick="subirDurante(${aptoId})"><i class="bi bi-camera"></i> Subir foto del durante</button>`
            : '';
        cuerpo.innerHTML = `
            <div class="fotos-fila">
                <div class="col-fotos col-antes"><div class="tit">Antes</div><div class="fotos-fila">${antes}</div></div>
                <div class="col-fotos col-durante"><div class="tit">Durante</div><div class="fotos-fila">${durante}</div>${btnFoto}</div>
            </div>`;
    } catch (e) {
        cuerpo.innerHTML = '<p class="text-muted">Error al cargar.</p>';
    }
}

function cerrarModalFotos() {
    document.getElementById('fs-modal-fotos').style.display = 'none';
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
    const aptoId = _duranteDestino;
    const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
        // Marcar que ya tiene foto del durante y redibujar solo ese piso.
        let pisoAfectado = null;
        (_arbol?.pisos || []).forEach(p => p.apartamentos.forEach(a => {
            if (a.id === aptoId) { a.tiene_foto_durante = true; pisoAfectado = p.piso_id; }
        }));
        if (pisoAfectado) {
            const cont = document.getElementById('piso-aptos-' + pisoAfectado);
            const piso = _arbol.pisos.find(p => p.piso_id === pisoAfectado);
            if (cont && piso) cont.innerHTML = piso.apartamentos.map(a => filaApartamento(a, pisoAfectado)).join('');
        }
        cerrarModalFotos();
    } else alert(data.mensaje || 'No se pudo subir.');
    _duranteDestino = null;
}

async function guardarAvance(aptoId, valor, pisoId) {
    const pct = parseInt(valor);
    // Actualizar en memoria y en pantalla al instante (sin esperar al servidor).
    const piso = _arbol.pisos.find(p => p.piso_id === pisoId);
    if (piso) {
        const ap = piso.apartamentos.find(a => a.id === aptoId);
        if (ap) ap.avance = pct;
    }
    const lbl = document.getElementById('pct-apto-' + aptoId);
    if (lbl) { lbl.textContent = pct + '%'; lbl.style.color = colorPct(pct); }
    recalcularEnPantalla(pisoId);

    // Guardar en el servidor.
    const res = await fetch(URL_BASE + 'guardar_avance.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ apartamento_id: aptoId, porcentaje: pct, edificio_id: EDIFICIO_ID })
    });
    const data = await res.json();
    if (data.sesion_expirada) { alert(data.mensaje); return; }
    if (!data.ok) alert(data.mensaje || 'No se pudo guardar el avance.');
}

cargarFicha();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
