<?php
/**
 * _kpi_modal.php
 *
 * Componente reutilizable (CSS + HTML + JS) para el modal de detalle de un
 * KPI de reconstrucción. Se incluye al final de control_gubernamental.php y
 * de sala_situacion.php. No imprime nada visible hasta que se llama a
 * kpiAbrir(tipo) desde un botón.
 *
 * Uso desde la página anfitriona:
 *   - Poner onclick="kpiAbrir('levantamiento')" (o 'reconstruccion' /
 *     'recuperadas') en el KPI que se quiera hacer clicable.
 *   - Incluir este archivo una sola vez:  include __DIR__ . '/_kpi_modal.php';
 *
 * No rompe el diseño existente: todo vive en un overlay position:fixed que
 * solo aparece al abrirse.
 */
?>
<style>
.kpi-ov { position:fixed; inset:0; background:rgba(16,20,34,.55); z-index:6000;
    display:none; align-items:flex-start; justify-content:center; padding:24px 12px; overflow-y:auto; }
.kpi-ov.ver { display:flex; }
.kpi-modal { background:#fff; border-radius:14px; width:100%; max-width:720px; margin:auto;
    box-shadow:0 18px 50px rgba(16,20,34,.3); overflow:hidden; }
.kpi-head { background:#22366F; color:#fff; padding:15px 18px; display:flex;
    align-items:center; justify-content:space-between; gap:12px; }
.kpi-head h3 { margin:0; font-size:16px; font-weight:700; }
.kpi-head .kpi-sub { font-size:12px; opacity:.9; margin-top:2px; }
.kpi-x { background:rgba(255,255,255,.15); border:0; color:#fff; width:34px; height:34px;
    border-radius:9px; font-size:18px; cursor:pointer; flex-shrink:0; line-height:1; }
.kpi-x:hover { background:rgba(255,255,255,.28); }
.kpi-body { padding:14px 16px; max-height:calc(100vh - 180px); overflow-y:auto; }

.kpi-parr { margin-bottom:14px; }
.kpi-parr-tit { font-size:13px; font-weight:700; color:#22366F; padding:7px 10px;
    background:#eef1f8; border-radius:8px; display:flex; justify-content:space-between; align-items:center; }
.kpi-parr-tit .n { background:#22366F; color:#fff; font-size:11px; padding:2px 9px; border-radius:20px; }
.kpi-edif { display:flex; align-items:center; gap:11px; padding:11px 10px; border-bottom:1px solid #eef0f5;
    cursor:pointer; }
.kpi-edif:hover { background:#f6f8fc; }
.kpi-edif-info { flex:1; min-width:0; }
.kpi-edif-nom { font-size:14px; font-weight:600; color:#2a3140; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.kpi-edif-sub { font-size:11.5px; color:#767c94; margin-top:2px; }
.kpi-edif-sist { color:#22366F; font-weight:600; }
.kpi-barra { height:8px; background:#eef0f6; border-radius:20px; overflow:hidden; margin-top:6px; }
.kpi-barra > div { height:100%; transition:width .3s; }
.kpi-pct { font-size:15px; font-weight:800; min-width:46px; text-align:right; }
.kpi-chev { color:#a3a9ba; font-size:15px; }

.kpi-fases { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.kpi-fase-col { border:1px solid #e5e8f0; border-radius:11px; overflow:hidden; background:#fafbfe; }
.kpi-fase-cab { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    padding:7px; text-align:center; }
.kpi-fase-col.antes   .kpi-fase-cab { background:#FBEAEA; color:#A61C1C; }
.kpi-fase-col.durante .kpi-fase-cab { background:#FDF3E7; color:#A66A00; }
.kpi-fase-col.despues .kpi-fase-cab { background:#E7F4EC; color:#2E7D32; }
.kpi-fase-cuerpo { padding:8px; display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start;
    justify-content:center; min-height:70px; }
.kpi-fase-cuerpo img { width:74px; height:74px; object-fit:cover; border-radius:7px;
    border:1px solid #d8dce6; cursor:zoom-in; }
.kpi-fase-vacia { font-size:11px; color:#a3a9ba; padding:14px 4px; text-align:center; }

.kpi-cargando { text-align:center; padding:36px; color:#5b6478; font-size:14px; }
.kpi-vacio { text-align:center; padding:32px 18px; color:#5b6478; }
.kpi-vacio i { font-size:40px; color:#C9A227; }
.kpi-back { background:none; border:0; color:#22366F; font-size:13px; font-weight:600;
    cursor:pointer; padding:4px 0 10px; display:inline-flex; align-items:center; gap:6px; }
.kpi-lupa { position:fixed; inset:0; background:rgba(16,20,34,.92); z-index:7000; display:none;
    align-items:center; justify-content:center; padding:16px; }
.kpi-lupa.ver { display:flex; }
.kpi-lupa img { max-width:100%; max-height:90vh; border-radius:10px; }
</style>

<div class="kpi-ov" id="kpi-ov" onclick="if(event.target===this)kpiCerrar()">
  <div class="kpi-modal">
    <div class="kpi-head">
      <div>
        <h3 id="kpi-titulo">Edificaciones</h3>
        <div class="kpi-sub" id="kpi-subtitulo"></div>
      </div>
      <button class="kpi-x" onclick="kpiCerrar()" aria-label="Cerrar">&times;</button>
    </div>
    <div class="kpi-body" id="kpi-body">
      <div class="kpi-cargando">Cargando…</div>
    </div>
  </div>
</div>
<div class="kpi-lupa" id="kpi-lupa" onclick="this.classList.remove('ver')">
  <img id="kpi-lupa-img" src="" alt="Foto ampliada">
</div>

<script>
/* Base de URL para los endpoints. Se lee al momento de usarla (no al
 * cargar el script) porque _APP_URL_BASE lo define el footer, que se
 * incluye después de este modal. */
function kpiBase() { return (window._APP_URL_BASE || '/') + 'dashboard/'; }
let KPI_TIPO_ACTUAL = null;

function kpiColor(p) {
    if (p >= 100) return '#2E7D32';
    if (p >= 50)  return '#A66A00';
    if (p > 0)    return '#C9A227';
    return '#C8CDDB';
}
function kpiEsc(t) {
    return String(t == null ? '' : t)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* --- Abrir el modal con la lista por parroquia de un KPI --- */
async function kpiAbrir(tipo) {
    KPI_TIPO_ACTUAL = tipo;
    const ov = document.getElementById('kpi-ov');
    ov.classList.add('ver');
    document.getElementById('kpi-body').innerHTML = '<div class="kpi-cargando">Cargando edificaciones…</div>';
    document.getElementById('kpi-subtitulo').textContent = '';
    try {
        const res = await fetch(kpiBase() + 'api_kpi_edificios.php?tipo=' + encodeURIComponent(tipo));
        const d = await res.json();
        if (!d.ok) { kpiError(d.mensaje || 'No se pudo cargar.'); return; }
        document.getElementById('kpi-titulo').textContent = d.titulo;
        document.getElementById('kpi-subtitulo').textContent =
            d.total + (d.total === 1 ? ' edificación' : ' edificaciones') + ' · por parroquia';
        kpiPintarLista(d);
    } catch (e) { kpiError('Error de conexión.'); }
}

function kpiPintarLista(d) {
    const body = document.getElementById('kpi-body');
    if (!d.parroquias.length) {
        body.innerHTML = '<div class="kpi-vacio"><i class="bi bi-inbox"></i>'
            + '<div style="margin-top:8px;font-size:14px;">No hay edificaciones en esta categoría todavía.</div></div>';
        return;
    }
    let h = '';
    d.parroquias.forEach(p => {
        h += '<div class="kpi-parr">'
           + '<div class="kpi-parr-tit"><span><i class="bi bi-geo-alt-fill"></i> ' + kpiEsc(p.parroquia) + '</span>'
           + '<span class="n">' + p.total + '</span></div>';
        p.edificios.forEach(e => {
            let lev;
            if (KPI_TIPO_ACTUAL === 'levantamiento') {
                // En este KPI la barra ya es el avance del levantamiento.
                lev = e.lev_estado === 'completo' ? 'Levantamiento completo · listo para reconstruir'
                    : (e.lev_estado === 'incompleto' ? 'Levantamiento cerrado, con datos por completar'
                    : 'Levantamiento en proceso');
            } else {
                // En reconstrucción/recuperadas la barra es el avance de obra.
                lev = e.lev_estado === 'completo' ? 'Levantamiento completo'
                    : (e.lev_estado === 'incompleto' ? 'Levantamiento con faltantes'
                    : 'Levantamiento en proceso (' + e.lev_pct + '%)');
            }
            const etiqueta = e.etiqueta_avance || '';
            h += '<div class="kpi-edif" onclick="kpiFotos(' + e.edificio_id + ')">'
               + '<div class="kpi-edif-info">'
               + '<div class="kpi-edif-nom">' + kpiEsc(e.nombre) + '</div>'
               + '<div class="kpi-edif-sub"><i class="bi bi-person-badge"></i> '
               + '<span class="kpi-edif-sist">' + kpiEsc(e.sistematizador) + '</span>'
               + ' · ' + e.n_pisos + ' pisos · ' + e.n_aptos + ' aptos</div>'
               + '<div class="kpi-edif-sub">' + lev + '</div>'
               + '<div class="kpi-barra"><div style="width:' + Math.max(e.avance,2) + '%;background:'
               + kpiColor(e.avance) + ';"></div></div>'
               + '</div>'
               + '<div style="text-align:right;min-width:52px;">'
               + '<div class="kpi-pct" style="color:' + kpiColor(e.avance) + ';">' + e.avance + '%</div>'
               + (etiqueta ? '<div style="font-size:9.5px;color:#9aa1b4;">' + etiqueta + '</div>' : '')
               + '</div>'
               + '<i class="bi bi-chevron-right kpi-chev"></i>'
               + '</div>';
        });
        h += '</div>';
    });
    body.innerHTML = h;
}

/* --- Ver las fotos antes/durante/después de un edificio --- */
async function kpiFotos(edificioId) {
    const body = document.getElementById('kpi-body');
    body.innerHTML = '<div class="kpi-cargando">Cargando fotos…</div>';
    try {
        const res = await fetch(kpiBase() + 'api_kpi_fotos.php?edificio=' + edificioId);
        const d = await res.json();
        if (!d.ok) { kpiError(d.mensaje || 'No se pudieron cargar las fotos.'); return; }
        kpiPintarFotos(d);
    } catch (e) { kpiError('Error de conexión.'); }
}

function kpiPintarFotos(d) {
    const body = document.getElementById('kpi-body');
    const ed = d.edificio || {};
    document.getElementById('kpi-titulo').textContent = ed.nombre || 'Edificación';
    document.getElementById('kpi-subtitulo').textContent =
        (ed.parroquia ? ed.parroquia + ' · ' : '') + 'Avance ' + (ed.avance || 0) + '%';

    const col = (titulo, cls, fotos) => {
        let inner;
        if (fotos.length) {
            inner = fotos.map(f =>
                '<img src="' + kpiEsc(f.ruta) + '" alt="' + titulo + '" title="' + kpiEsc(f.lugar)
                + (f.fecha ? ' · ' + f.fecha : '') + '" onclick="kpiLupa(\'' + kpiEsc(f.ruta) + '\')">'
            ).join('');
        } else {
            inner = '<div class="kpi-fase-vacia">Sin fotos</div>';
        }
        return '<div class="kpi-fase-col ' + cls + '">'
             + '<div class="kpi-fase-cab">' + titulo + ' (' + fotos.length + ')</div>'
             + '<div class="kpi-fase-cuerpo">' + inner + '</div></div>';
    };

    let h = '<button class="kpi-back" onclick="kpiAbrir(KPI_TIPO_ACTUAL)"><i class="bi bi-arrow-left"></i> Volver a la lista</button>';
    const total = (d.conteo.antes + d.conteo.durante + d.conteo.despues);
    if (total === 0) {
        h += '<div class="kpi-vacio"><i class="bi bi-camera"></i>'
           + '<div style="margin-top:8px;font-size:14px;">Esta edificación todavía no tiene fotos cargadas.</div></div>';
    } else {
        h += '<div class="kpi-fases">'
           + col('Antes', 'antes', d.antes)
           + col('Durante', 'durante', d.durante)
           + col('Después', 'despues', d.despues)
           + '</div>';
    }
    body.innerHTML = h;
}

function kpiLupa(src) {
    document.getElementById('kpi-lupa-img').src = src;
    document.getElementById('kpi-lupa').classList.add('ver');
}
function kpiError(msg) {
    document.getElementById('kpi-body').innerHTML =
        '<div class="kpi-vacio"><i class="bi bi-exclamation-triangle" style="color:#A61C1C;"></i>'
        + '<div style="margin-top:8px;font-size:14px;">' + kpiEsc(msg) + '</div></div>';
}
function kpiCerrar() {
    document.getElementById('kpi-ov').classList.remove('ver');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') kpiCerrar(); });
</script>
