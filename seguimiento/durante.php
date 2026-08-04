<?php
/**
 * SEGUIMIENTO · SUBIR EL "DURANTE".
 *
 * Página NUEVA (no toca remodelacion.php ni campo.php). Da un flujo
 * limpio para que el sistematizador suba las fotos del "durante":
 *   1. Busca su edificio (por nombre o código).
 *   2. Elige el piso.
 *   3. Elige el apartamento.
 *   4. Le aparecen los AMBIENTES que él mismo registró en el
 *      levantamiento, y a cada uno le sube la foto del durante.
 *
 * Todo se apoya en lo que YA existe:
 *   - buscar_edificios.php   (buscador)
 *   - arbol_avance.php       (árbol piso→apto→ambientes reales)
 *   - subir_foto_rec.php     (sube la foto con parte='durante')
 * No cambia ninguna lógica ni estructura de datos.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$activeModule = 'durante';
$pageTitle = 'Subir el durante';
$BASE = APP_URL_BASE;

include __DIR__ . '/../includes/header.php';
?>
<style>
  .du-wrap { max-width: 920px; margin: 0 auto; padding: 4px 6px 40px; }
  .du-top { display: flex; align-items: center; gap: 12px; margin: 6px 2px 16px; }
  .du-top .ic { width: 40px; height: 40px; border-radius: 11px; background: #22366F; color: #fff; display: flex; align-items: center; justify-content: center; }
  .du-top h1 { font-size: 21px; font-weight: 700; color: #22366F; margin: 0; }
  .du-top .sub { font-size: 12.5px; color: #5b6478; }

  .du-buscar { display: flex; align-items: center; gap: 9px; height: 46px; border: 1px solid #D8DCE6; border-radius: 12px; padding: 0 14px; background: #fff; margin-bottom: 14px; }
  .du-buscar input { border: 0; outline: 0; flex: 1; font-size: 15px; color: #2a3140; background: transparent; }

  .du-res { display: flex; flex-direction: column; gap: 8px; margin-bottom: 6px; }
  .du-ed { display: flex; align-items: center; gap: 11px; border: 1px solid #e5e8f0; border-radius: 11px; padding: 12px 14px; background: #fff; cursor: pointer; }
  .du-ed:hover { border-color: #22366F; background: #f7f9fc; }
  .du-ed .eic { width: 38px; height: 38px; border-radius: 9px; background: #E9EEF9; color: #22366F; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .du-ed .info { flex: 1; min-width: 0; }
  .du-ed .info b { font-size: 14px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .du-ed .info span { font-size: 11.5px; color: #9aa1b4; }

  .du-panel { background: #fff; border: 1px solid #e5e8f0; border-radius: 13px; padding: 0; overflow: hidden; }
  .du-cab { display: flex; align-items: center; gap: 11px; padding: 14px 16px; border-bottom: 1px solid #eef1f6; }
  .du-cab .info { flex: 1; min-width: 0; }
  .du-cab .info b { font-size: 15px; color: #22366F; }
  .du-cab .info span { font-size: 11.5px; color: #5b6478; display: block; }
  .du-cab .cambiar { font-size: 12px; color: #22366F; background: #E9EEF9; border: 0; border-radius: 8px; padding: 7px 12px; cursor: pointer; font-weight: 600; }

  .du-cuerpo { display: flex; gap: 0; min-height: 320px; }
  .du-arbol { width: 240px; flex-shrink: 0; border-right: 1px solid #eef1f6; padding: 10px; overflow-y: auto; max-height: 460px; }
  .du-arbol .grupo-tit { font-size: 10px; color: #9aa1b4; letter-spacing: .5px; padding: 6px 8px 8px; font-weight: 700; }
  .du-piso { margin-bottom: 4px; }
  .du-piso-h { display: flex; align-items: center; gap: 6px; padding: 8px 9px; font-size: 13px; color: #2a3140; border-radius: 8px; cursor: pointer; font-weight: 600; }
  .du-piso-h:hover { background: #f4f6fa; }
  .du-piso-h .pct { margin-left: auto; font-size: 10.5px; color: #9aa1b4; }
  .du-apto { display: flex; align-items: center; gap: 7px; padding: 7px 9px 7px 20px; font-size: 12.5px; color: #5b6478; border-radius: 7px; cursor: pointer; }
  .du-apto:hover { background: #f4f6fa; }
  .du-apto.activo { background: #E9EEF9; color: #22366F; font-weight: 600; }
  .du-apto .pct { margin-left: auto; font-size: 10px; }
  .du-apto .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

  .du-detalle { flex: 1; min-width: 0; padding: 16px; }
  .du-detalle .vacio { color: #9aa1b4; font-size: 13px; text-align: center; padding: 60px 20px; }
  .du-amb-tit { font-size: 12px; color: #9aa1b4; letter-spacing: .4px; font-weight: 700; margin-bottom: 12px; text-transform: uppercase; }
  .du-amb { display: flex; align-items: center; gap: 12px; border: 1px solid #e5e8f0; border-radius: 11px; padding: 12px 14px; margin-bottom: 9px; }
  .du-amb .nm { flex: 1; min-width: 0; }
  .du-amb .nm b { font-size: 14px; }
  .du-amb .nm .est { font-size: 11px; color: #9aa1b4; display: block; margin-top: 2px; }
  .du-amb .fotos { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #5b6478; }
  .du-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
  .b-durante { background: #E7F4EC; color: #2E7D32; }
  .b-falta { background: #FDF7E7; color: #A66A00; }
  .du-subir { display: inline-flex; align-items: center; gap: 6px; background: #1D6E56; color: #fff; border: 0; border-radius: 9px; padding: 9px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .du-subir:disabled { opacity: .6; cursor: default; }
  .du-subir.hecho { background: #E7F4EC; color: #2E7D32; }

  .du-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #22366F; color: #fff; padding: 12px 20px; border-radius: 10px; font-size: 13px; z-index: 9999; opacity: 0; transition: opacity .2s; pointer-events: none; }
  .du-toast.on { opacity: 1; }

  @media (max-width: 720px) {
    .du-cuerpo { flex-direction: column; }
    .du-arbol { width: 100%; border-right: 0; border-bottom: 1px solid #eef1f6; max-height: 240px; }
  }
</style>

<div class="du-wrap">

  <div class="du-top">
    <div class="ic"><i class="bi bi-camera-fill"></i></div>
    <div>
      <h1>Subir el "durante"</h1>
      <div class="sub">Busca tu edificio, entra al apartamento y súbele la foto del durante a cada ambiente</div>
    </div>
  </div>

  <!-- PASO 1: buscar edificio -->
  <div id="du-paso-buscar">
    <div class="du-buscar">
      <i class="bi bi-search" style="color:#9aa1b4;"></i>
      <input type="text" id="du-q" placeholder="Buscar por nombre o código del edificio…" autocomplete="off">
    </div>
    <div class="du-res" id="du-res"></div>
  </div>

  <!-- PASO 2: árbol + ambientes -->
  <div id="du-paso-arbol" style="display:none;">
    <div class="du-panel">
      <div class="du-cab">
        <div class="eic" style="width:38px;height:38px;border-radius:9px;background:#E9EEF9;color:#22366F;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-building"></i></div>
        <div class="info">
          <b id="du-ed-nombre">—</b>
          <span id="du-ed-codigo">—</span>
        </div>
        <button class="cambiar" onclick="duVolver()"><i class="bi bi-arrow-left"></i> Cambiar edificio</button>
      </div>
      <div class="du-cuerpo">
        <div class="du-arbol" id="du-arbol">
          <div class="grupo-tit">EDIFICIO · ELIGE PISO Y APTO</div>
          <div id="du-arbol-lista"></div>
        </div>
        <div class="du-detalle" id="du-detalle">
          <div class="vacio">Elige un apartamento a la izquierda para ver sus ambientes.</div>
        </div>
      </div>
    </div>
  </div>

</div>

<input type="file" id="du-file" accept="image/*" capture="environment" style="display:none;">
<div class="du-toast" id="du-toast"></div>

<script>
const DU_BASE = <?= json_encode($BASE) ?>;
let DU_INSP = 0;          // inspeccion_id del edificio elegido
let DU_ARBOL = null;      // árbol cargado
let DU_APTO_SEL = null;   // apartamento seleccionado
let DU_FOTO_DEST = null;  // {refId} ambiente al que se le sube la foto

/* ---------- PASO 1: BUSCAR ---------- */
let duTimer = null;
document.getElementById('du-q').addEventListener('input', function () {
    clearTimeout(duTimer);
    const q = this.value.trim();
    if (q.length < 2) { document.getElementById('du-res').innerHTML = ''; return; }
    duTimer = setTimeout(() => duBuscar(q), 300);
});

async function duBuscar(q) {
    try {
        const r = await fetch(DU_BASE + 'seguimiento/buscar_edificios.php?q=' + encodeURIComponent(q));
        const data = await r.json();
        const cont = document.getElementById('du-res');
        const puntos = data.puntos || [];
        if (!puntos.length) { cont.innerHTML = '<div style="color:#9aa1b4;font-size:13px;padding:10px;">Sin resultados.</div>'; return; }
        cont.innerHTML = puntos.slice(0, 20).map(p =>
            '<div class="du-ed" onclick="duAbrir(' + p.id + ', ' +
                JSON.stringify(p.nombre || 'Sin nombre').replace(/"/g, '&quot;') + ', ' +
                JSON.stringify(p.codigo || '').replace(/"/g, '&quot;') + ')">' +
            '<div class="eic"><i class="bi bi-building"></i></div>' +
            '<div class="info"><b>' + duEsc(p.nombre || 'Sin nombre') + '</b>' +
            '<span>' + duEsc(p.codigo || '') + ' · ' + duEsc(p.parroquia || '') + '</span></div>' +
            '<i class="bi bi-chevron-right" style="color:#c4c9d6;"></i></div>'
        ).join('');
    } catch (e) {
        document.getElementById('du-res').innerHTML = '<div style="color:#A61C1C;font-size:13px;padding:10px;">Error al buscar.</div>';
    }
}

/* ---------- PASO 2: ABRIR EDIFICIO Y CARGAR ÁRBOL ---------- */
async function duAbrir(inspId, nombre, codigo) {
    DU_INSP = inspId;
    document.getElementById('du-ed-nombre').textContent = nombre;
    document.getElementById('du-ed-codigo').textContent = codigo || '';
    document.getElementById('du-paso-buscar').style.display = 'none';
    document.getElementById('du-paso-arbol').style.display = 'block';
    document.getElementById('du-arbol-lista').innerHTML = '<div style="padding:14px;color:#9aa1b4;font-size:12px;">Cargando…</div>';
    document.getElementById('du-detalle').innerHTML = '<div class="vacio">Elige un apartamento para ver sus ambientes.</div>';

    try {
        const r = await fetch(DU_BASE + 'seguimiento/arbol_avance.php?inspeccion=' + inspId);
        const data = await r.json();
        if (!data.ok) {
            document.getElementById('du-arbol-lista').innerHTML =
                '<div style="padding:14px;color:#A66A00;font-size:12.5px;">' + duEsc(data.mensaje || 'Sin levantamiento.') + '</div>';
            return;
        }
        DU_ARBOL = data;
        duPintarArbol();
    } catch (e) {
        document.getElementById('du-arbol-lista').innerHTML = '<div style="padding:14px;color:#A61C1C;font-size:12.5px;">Error al cargar el árbol.</div>';
    }
}

function duPintarArbol() {
    const pisos = DU_ARBOL.pisos || [];
    if (!pisos.length) {
        document.getElementById('du-arbol-lista').innerHTML = '<div style="padding:14px;color:#9aa1b4;font-size:12.5px;">Este edificio no tiene pisos registrados.</div>';
        return;
    }
    let html = '';
    pisos.forEach((piso, pi) => {
        html += '<div class="du-piso">';
        html += '<div class="du-piso-h"><i class="bi bi-layers"></i> ' + duEsc(piso.etiqueta) +
                '<span class="pct">' + (piso.avance || 0) + '%</span></div>';
        (piso.apartamentos || []).forEach((apto, ai) => {
            const av = apto.avance || 0;
            const color = av >= 100 ? '#2E7D32' : (av > 0 ? '#EF9F27' : '#C4C9D6');
            const etq = apto.identificador || (apto.es_local ? 'Local' : 'Apto');
            html += '<div class="du-apto" onclick="duElegirApto(' + pi + ',' + ai + ',this)">' +
                    '<span class="dot" style="background:' + color + ';"></span>' +
                    '<span>' + duEsc(etq) + '</span>' +
                    '<span class="pct" style="color:' + color + ';">' + av + '%</span></div>';
        });
        html += '</div>';
    });
    document.getElementById('du-arbol-lista').innerHTML = html;
}

/* ---------- PASO 3: ELEGIR APTO → MOSTRAR AMBIENTES REGISTRADOS ---------- */
function duElegirApto(pi, ai, el) {
    document.querySelectorAll('.du-apto').forEach(a => a.classList.remove('activo'));
    if (el) el.classList.add('activo');
    const apto = DU_ARBOL.pisos[pi].apartamentos[ai];
    DU_APTO_SEL = apto;

    const ambientes = apto.ambientes || [];
    if (!ambientes.length) {
        document.getElementById('du-detalle').innerHTML =
            '<div class="vacio">Este apartamento no tiene ambientes registrados en el levantamiento.</div>';
        return;
    }

    let html = '<div class="du-amb-tit">Ambientes de ' + duEsc(apto.identificador || 'este apartamento') + '</div>';
    ambientes.forEach(amb => {
        const tieneDurante = !!amb.tiene_foto_durante;
        html += '<div class="du-amb">' +
            '<div class="nm"><b>' + duEsc(amb.etiqueta) + '</b>' +
                '<span class="est">' + (amb.necesita_reparacion ? 'Necesita reparación' : 'Registrado') +
                ' · avance ' + (amb.avance || 0) + '%</span></div>';
        if (tieneDurante) {
            html += '<span class="du-badge b-durante"><i class="bi bi-check-lg"></i> Durante subido (' + (amb.fotos_durante || 0) + ')</span>' +
                    '<button class="du-subir" onclick="duPedirFoto(' + amb.id + ')"><i class="bi bi-camera"></i> Otra</button>';
        } else {
            html += '<span class="du-badge b-falta">Falta el durante</span>' +
                    '<button class="du-subir" onclick="duPedirFoto(' + amb.id + ')"><i class="bi bi-camera"></i> Subir durante</button>';
        }
        html += '</div>';
    });
    document.getElementById('du-detalle').innerHTML = html;
}

/* ---------- PASO 4: SUBIR FOTO DEL DURANTE ---------- */
function duPedirFoto(ambienteId) {
    DU_FOTO_DEST = { refId: ambienteId };
    document.getElementById('du-file').click();
}

document.getElementById('du-file').addEventListener('change', async function () {
    if (!this.files || !this.files[0] || !DU_FOTO_DEST) { this.value = ''; return; }
    const archivo = this.files[0];
    this.value = '';
    duToast('Subiendo foto…');

    const fd = new FormData();
    fd.append('nivel', 'ambiente');
    fd.append('ref_id', DU_FOTO_DEST.refId);
    fd.append('parte', 'durante');
    fd.append('foto', archivo);

    try {
        const r = await fetch(DU_BASE + 'seguimiento/subir_foto_rec.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.ok || data.id) {
            duToast('Foto del durante guardada ✓');
            // recargar el árbol para reflejar el nuevo estado
            await duRecargarAptoActual();
        } else {
            duToast(data.mensaje || 'No se pudo subir.');
        }
    } catch (e) {
        duToast('Error de red al subir.');
    }
});

async function duRecargarAptoActual() {
    // Recarga el árbol y vuelve a pintar el apartamento abierto.
    try {
        const r = await fetch(DU_BASE + 'seguimiento/arbol_avance.php?inspeccion=' + DU_INSP);
        const data = await r.json();
        if (data.ok) {
            DU_ARBOL = data;
            // buscar el apto por id y repintar
            let pi = -1, ai = -1;
            (DU_ARBOL.pisos || []).forEach((piso, ip) => {
                (piso.apartamentos || []).forEach((ap, ia) => {
                    if (DU_APTO_SEL && ap.id === DU_APTO_SEL.id) { pi = ip; ai = ia; }
                });
            });
            duPintarArbol();
            if (pi >= 0) {
                // re-marcar activo y repintar ambientes
                const aptosDom = document.querySelectorAll('.du-apto');
                duElegirApto(pi, ai, null);
            }
        }
    } catch (e) { /* silencioso */ }
}

/* ---------- utilidades ---------- */
function duVolver() {
    document.getElementById('du-paso-arbol').style.display = 'none';
    document.getElementById('du-paso-buscar').style.display = 'block';
    DU_INSP = 0; DU_ARBOL = null; DU_APTO_SEL = null;
}
function duEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
let duToastTimer = null;
function duToast(msg) {
    const t = document.getElementById('du-toast');
    t.textContent = msg; t.classList.add('on');
    clearTimeout(duToastTimer);
    duToastTimer = setTimeout(() => t.classList.remove('on'), 2600);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
