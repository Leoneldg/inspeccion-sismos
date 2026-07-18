<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$pageTitle    = 'Seguimiento y Control';
$pageSubtitle = 'Mapa de recuperación de edificaciones inspeccionadas';
$activeModule = 'seguimiento';

$kpis        = segKpis();
$decisiones  = catalogoDecisionFinal();
$fasesCat    = segFasesRecuperacion();
$puedeEditar = puede('seguimiento', 'editar');
$entes       = segEntes(usuarioEsMaster() ? null : estadoDelUsuario());

// ---------------------------------------------------------------------
// Puntos para el mapa.
//   1) Si la inspección tiene coordenadas GPS propias y válidas, se usan.
//   2) Si NO las tiene (nulas, 0,0) o tiene una coordenada "por defecto"
//      (la misma repetida en muchas inspecciones, señal de que no es una
//      ubicación real sino un valor puesto automáticamente), se le genera
//      un punto aleatorio dentro del polígono de su parroquia.
//   3) Si no hay forma de ubicarla (sin parroquia), no se muestra.
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// RENDIMIENTO: en vez de dibujar miles de puntos de golpe (lento), el mapa
// muestra un conteo por parroquia (burbujas). Los puntos individuales se
// cargan bajo demanda al seleccionar una parroquia (puntos_parroquia.php).
// ---------------------------------------------------------------------
$conteoParroquias = segConteoPorParroquia();

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<style>
/* ---- KPIs más grandes y destacados ---- */
.seg-kpi-num { font-size: 42px !important; font-weight: 800 !important; letter-spacing: -1px; }
.seg-kpi-lbl { font-size: 13px !important; font-weight: 700 !important; text-transform: uppercase; letter-spacing: .5px; color: #55617f; }
.seg-kpi-ico { width: 52px !important; height: 52px !important; font-size: 24px !important; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.seg-kpi { padding: 18px 20px !important; gap: 14px; align-items: center; }
.seg-progress-txt { font-size: 20px !important; font-weight: 800 !important; }
@media (max-width: 640px) {
    .seg-kpi-num { font-size: 34px !important; }
    .seg-kpi-ico { width: 44px !important; height: 44px !important; font-size: 20px !important; }
}

/* ---- Mapa de seguimiento ---- */
#seg-map { height: 620px; width: 100%; border-radius: 10px; z-index: 1; }
.seg-map-wrap { position: relative; }

/* Panel lateral con la ficha técnica del punto seleccionado */
.seg-panel {
    position: absolute; top: 12px; left: 12px; width: 330px; max-width: calc(100% - 24px);
    max-height: calc(100% - 24px); overflow-y: auto; z-index: 500;
    background: #fff; border-radius: 10px; box-shadow: 0 6px 24px rgba(20,30,60,.22);
    display: none;
}
.seg-panel.open { display: block; }
.seg-panel-head { padding: 12px 14px; border-bottom: 1px solid #e8ebf3; position: relative; }
.seg-panel-head h3 { margin: 0 22px 2px 0; font-size: 15px; line-height: 1.25; }
.seg-panel-close {
    position: absolute; top: 10px; right: 10px; border: 0; background: transparent;
    font-size: 17px; color: #97a0b8; cursor: pointer; line-height: 1;
}
.seg-panel-body { padding: 12px 14px; }
.seg-panel-row { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; font-size: 13px; border-bottom: 1px dashed #eef0f6; }
.seg-panel-row:last-child { border-bottom: 0; }
.seg-panel-row span:first-child { color: #767c94; }
.seg-panel-row span:last-child { font-weight: 600; text-align: right; }
.seg-panel-foot { padding: 0 14px 14px; }

/* Botón grande de asignar fase */
.btn-fase {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 14px 12px; border: 0; border-radius: 9px;
    font-size: 14.5px; font-weight: 700; color: #fff; cursor: pointer;
    background: #2d4488; transition: filter .15s; text-align: center; line-height: 1.2;
}
.btn-fase:hover:not(:disabled) { filter: brightness(1.08); }
.btn-fase:disabled { opacity: .75; cursor: default; }
.btn-fase i { font-size: 19px; }
.btn-fase-sub { display: block; font-weight: 500; font-size: 11.5px; opacity: .9; margin-top: 2px; }

.fase-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}
.seg-leyenda { display: flex; flex-wrap: wrap; gap: 14px; padding: 10px 14px 0; font-size: 12.5px; color: #55617f; }
.seg-leyenda i { font-size: 11px; }
.seg-marker-ico { display: block; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.45); }
.seg-marker-mant { position: absolute; right: -3px; bottom: -3px; background: #2d4488; color: #fff;
    border-radius: 50%; width: 13px; height: 13px; font-size: 8px; line-height: 13px; text-align: center; border: 1.5px solid #fff; }
</style>

<!-- KPIs del módulo -->
<div class="seg-kpi-grid">
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-buildings-fill"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['total_edificios'] ?></div><div class="seg-kpi-lbl">INSPECCIONES</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#fff4e0;color:#C9A227;"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['en_ejecucion'] ?></div><div class="seg-kpi-lbl">RECONSTRUCCIÓN</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#e5f7ee;color:#2E7D32;"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['culminadas'] ?></div><div class="seg-kpi-lbl">CULMINADAS</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#f1f2f6;color:#767c94;"><i class="bi bi-clipboard-x"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['sin_seguimiento'] ?></div><div class="seg-kpi-lbl">SIN ASIGNAR</div></div>
    </div>
    <div class="seg-kpi seg-kpi-wide">
        <div class="seg-kpi-ico" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-graph-up-arrow"></i></div>
        <div style="flex:1;">
            <div class="seg-kpi-lbl">AVANCE PROMEDIO</div>
            <div class="seg-progress" style="margin-top:6px;">
                <div class="seg-progress-bar" style="width:<?= round((float)$kpis['avance_promedio']) ?>%;"></div>
                <span class="seg-progress-txt"><?= round((float)$kpis['avance_promedio']) ?>%</span>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-body">
        <div class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="margin:0;">
                <label class="text-sm">Buscar edificación</label>
                <input type="text" id="f-buscar" class="form-control" style="width:250px;" placeholder="Nombre o código…" onkeydown="if(event.key==='Enter')ejecutarBusqueda()">
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Parroquia</label>
                <select id="f-parroquia" class="form-control" style="width:200px;" onchange="ejecutarBusqueda()">
                    <option value="">Todas las parroquias</option>
                    <?php
                    // Parroquias que tienen inspecciones (del conteo), ordenadas.
                    $parrOrden = $conteoParroquias;
                    usort($parrOrden, fn($a, $b) => strcmp($a['parroquia'], $b['parroquia']));
                    foreach ($parrOrden as $cp):
                    ?>
                    <option value="<?= e($cp['parroquia']) ?>" data-estado="<?= e($cp['estado']) ?>">
                        <?= e(mb_strtoupper($cp['parroquia'], 'UTF-8')) ?> (<?= (int)$cp['total'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado</label>
                <select id="f-estado" class="form-control" style="width:160px;" onchange="ejecutarBusqueda()">
                    <?php
                    $estadosLista = catalogoEstados();
                    foreach ($estadosLista as $est):
                        // Predeterminado siempre en Distrito Capital.
                        $sel = ($est === 'Distrito Capital') ? 'selected' : '';
                    ?>
                    <option value="<?= e($est) ?>" <?= $sel ?>><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Status</label>
                <select id="f-color" class="form-control" style="width:150px;" onchange="ejecutarBusqueda()">
                    <option value="">Todos</option>
                    <option value="verde">🟢 VERDE</option>
                    <option value="amarillo">🟡 AMARILLO</option>
                    <option value="rojo">🔴 ROJO</option>
                    <option value="derrumbado">⚫ DERRUMBADO</option>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Ente</label>
                <select id="f-ente" class="form-control" style="width:180px;" onchange="ejecutarBusqueda()">
                    <option value="">TODOS LOS ENTES</option>
                    <?php foreach ($entes as $ente): ?>
                    <option value="<?= (int)$ente['id'] ?>"><?= e(mb_strtoupper($ente['nombre'], 'UTF-8')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" onclick="ejecutarBusqueda()"><i class="bi bi-search"></i> Buscar</button>
            <button class="btn btn-outline" onclick="limpiarBusqueda()"><i class="bi bi-x-circle"></i> Limpiar</button>
            <a href="<?= APP_URL_BASE ?>seguimiento/entes.php" class="btn btn-outline"><i class="bi bi-building-gear"></i> Entes</a>
        </div>
        <!-- Resultados de la búsqueda -->
        <div id="f-resultados" style="display:none;margin-top:14px;border-top:1px solid #eef0f5;padding-top:12px;"></div>
    </div>
</div>

<!-- Mapa -->
<div class="card">
    <div class="card-header">
        <?php $totalConteo = array_sum(array_map(fn($c) => (int)$c['total'], $conteoParroquias)); ?>
        <h2><i class="bi bi-geo-alt-fill"></i> Mapa de recuperación (<?= $totalConteo ?>)</h2>
        <span class="text-sm text-muted">
            <i class="bi bi-geo"></i> <?= count($conteoParroquias) ?> parroquias con edificaciones
        </span>
    </div>

    <div class="seg-leyenda">
        <span><strong>Color del punto = decisión:</strong></span>
        <?php foreach ($decisiones as $meta): ?>
            <span><i class="bi bi-circle-fill" style="color:<?= $meta['color'] ?>;"></i> <?= e($meta['corto']) ?></span>
        <?php endforeach; ?>
        <span style="margin-left:6px;"><i class="bi bi-tools" style="color:#2d4488;"></i> = en fase de recuperación</span>
        <span><i class="bi bi-circle" style="color:#767c94;"></i> borde punteado = ubicación aproximada (por parroquia, sin GPS)</span>
    </div>

    <div class="card-body">
        <?php if (!$conteoParroquias): ?>
            <div class="empty-state"><i class="bi bi-geo-alt"></i> No hay edificaciones con coordenadas para mostrar en el mapa.</div>
        <?php else: ?>
        <div class="seg-map-wrap">
            <div id="seg-map"></div>

            <!-- Panel con la ficha técnica del punto seleccionado -->
            <div class="seg-panel" id="seg-panel">
                <div class="seg-panel-head">
                    <button class="seg-panel-close" id="seg-panel-close" title="Cerrar"><i class="bi bi-x-lg"></i></button>
                    <h3 id="sp-nombre">—</h3>
                    <div style="font-family:var(--font-mono);font-size:12px;color:#767c94;" id="sp-codigo">—</div>
                    <div style="margin-top:7px;" id="sp-chips"></div>
                </div>
                <div class="seg-panel-body" id="sp-datos"></div>
                <div class="seg-panel-foot">
                    <!-- Mensaje de estado / confirmación -->
                    <div id="sp-msg" style="display:none;margin-bottom:10px;padding:10px 11px;border-radius:8px;font-size:13px;line-height:1.4;"></div>

                    <!-- Botón según estado: si NO hay levantamiento, se llena;
                         si YA está completo, se va a la ficha de seguimiento. -->
                    <a href="#" id="sp-levantamiento" class="btn btn-primary" style="width:100%;justify-content:center;">
                        <i class="bi bi-building-gear"></i> Levantamiento técnico
                    </a>
                    <a href="#" id="sp-ficha" class="btn btn-primary" style="width:100%;justify-content:center;display:none;">
                        <i class="bi bi-clipboard-data"></i> Ficha de seguimiento
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const CONTEO_PARROQUIAS = <?= json_encode($conteoParroquias, JSON_UNESCAPED_UNICODE) ?>;
const FASES        = <?= json_encode($fasesCat, JSON_UNESCAPED_UNICODE) ?>;
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;
const APP_URL_BASE = '<?= APP_URL_BASE ?>';
const PARROQUIA_URL = APP_URL_BASE + 'dashboard/api_parroquia.php';
const PUNTOS_URL = APP_URL_BASE + 'seguimiento/puntos_parroquia.php';
const BUSCAR_URL = APP_URL_BASE + 'seguimiento/buscar_edificios.php';

let map;
let marcadores = {};      // id -> marker
let seleccionado = null;  // punto actualmente abierto en el panel

/* ---------- Ícono del marcador (color por decisión + señal de mantenimiento) ---------- */
function iconoPunto(p) {
    const size = 20;
    // En recuperación (tiene ente asignado y aún no culmina) -> ícono de mantenimiento.
    const enRecuperacion = !!p.ente && p.fase !== 3;
    const mant = enRecuperacion
        ? '<span class="seg-marker-mant"><i class="bi bi-tools"></i></span>'
        : '';
    const anillo = (p.fase === 3) ? 'box-shadow:0 0 0 3px #2E7D32, 0 1px 4px rgba(0,0,0,.45);' : '';
    // Los ubicados por parroquia (sin GPS) llevan borde punteado, para
    // distinguir la ubicación aproximada de la exacta.
    const borde = p.aprox ? 'border:2px dashed #fff;' : 'border:2px solid #fff;';
    return L.divIcon({
        className: '',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        html: `<div style="position:relative;width:${size}px;height:${size}px;">
                 <span class="seg-marker-ico" style="width:${size}px;height:${size}px;background:${p.color};${borde}${anillo}"></span>
                 ${mant}
               </div>`
    });
}

/* ---------- Mensaje de confirmación / error dentro del panel ---------- */
function mostrarMsg(texto, exito) {
    const msg = document.getElementById('sp-msg');
    msg.style.display    = 'block';
    msg.style.background = exito ? '#e5f7ee' : '#fdeaea';
    msg.style.color      = exito ? '#1e5b2a' : '#A61C1C';
    msg.style.border     = '1px solid ' + (exito ? '#b9e3c8' : '#f3c2c2');
    msg.innerHTML = (exito ? '<i class="bi bi-check-circle-fill"></i> ' : '<i class="bi bi-exclamation-triangle-fill"></i> ')
                  + texto;
}

/* ---------- Abre el panel con la ficha técnica del punto ---------- */
function abrirPanel(p) {
    seleccionado = p;
    document.getElementById('sp-nombre').textContent = p.nombre;
    document.getElementById('sp-codigo').textContent = p.codigo;
    document.getElementById('sp-ficha').href = APP_URL_BASE + 'seguimiento/remodelacion.php?inspeccion=' + p.id;
    document.getElementById('sp-levantamiento').href = p.levantamiento_url;

    // Según el estado: si el levantamiento está completo, mostrar "Ficha de
    // seguimiento"; si no, mostrar "Levantamiento técnico".
    const btnLev = document.getElementById('sp-levantamiento');
    const btnFicha = document.getElementById('sp-ficha');
    if (p.levantamiento_completo) {
        btnLev.style.display = 'none';
        btnFicha.style.display = '';
    } else {
        btnLev.style.display = '';
        btnFicha.style.display = 'none';
    }

    const f = FASES[p.fase];
    document.getElementById('sp-chips').innerHTML =
        `<span class="fase-chip" style="background:${p.color}22;color:${p.color};">
            <i class="bi bi-circle-fill"></i> ${p.decision}
         </span>
         <span class="fase-chip" style="background:${f.color}22;color:${f.color};margin-left:5px;">
            <i class="bi ${f.icono}"></i> ${f.nombre}
         </span>`;

    const filas = [
        ['Ubicación',    [p.parroquia, p.municipio, p.estado].filter(x => x && x !== '—').join(', ') || '—'],
        ...(p.aprox ? [['Coordenadas', '<span style="color:#C9A227;" title="Sin GPS: se ubicó dentro de su parroquia"><i class="bi bi-geo"></i> Aprox. por parroquia</span>']] : []),
        ['Uso',          p.uso],
        ['Pisos',        p.pisos || '—'],
        ['Personas',     p.personas || '—'],
        ['Inspección',   p.fecha],
        ['Ente',         p.ente || 'Sin asignar'],
        ['Estado obra',  p.estado_obra || 'Sin seguimiento'],
        ['Avance',       p.avance + '%'],
    ];
    document.getElementById('sp-datos').innerHTML = filas.map(
        ([k, v]) => `<div class="seg-panel-row"><span>${k}</span><span>${v}</span></div>`
    ).join('');

    document.getElementById('seg-panel').classList.add('open');
}

/* ---------- Inicialización del mapa ---------- */
// ===================== PANEL DE PARROQUIA =====================
async function abrirPanelParroquia(estado, nombreParroquia) {
    const panel = document.getElementById('panel-parroquia');
    document.getElementById('pp-nombre').textContent = nombreParroquia;
    document.getElementById('pp-contenido').innerHTML = '<p class="text-muted">Cargando…</p>';
    panel.style.right = '0';
    try {
        const url = PARROQUIA_URL + '?estado=' + encodeURIComponent(estado) + '&parroquia=' + encodeURIComponent(nombreParroquia);
        const res = await fetch(url);
        const d = await res.json();
        if (!d.ok) { document.getElementById('pp-contenido').innerHTML = '<p class="text-muted">' + (d.mensaje || 'No se pudo cargar.') + '</p>'; return; }
        pintarPanelParroquia(d);
    } catch (e) {
        document.getElementById('pp-contenido').innerHTML = '<p class="text-muted">Error de red.</p>';
    }
}

function cerrarPanelParroquia() {
    document.getElementById('panel-parroquia').style.right = '-460px';
}

function pintarPanelParroquia(d) {
    const cont = document.getElementById('pp-contenido');
    let enc = '';
    if (d.encargados && d.encargados.length) {
        enc = d.encargados.map(r =>
            `<div style="background:#eef2fb;border-radius:9px;padding:10px 12px;margin-bottom:6px;">
                <div style="font-weight:600;color:#22366F;"><i class="bi bi-person-badge"></i> ${r.nombre}</div>
                <div style="font-size:12px;color:#55617f;">${r.cargo||''}${r.telefono?' · '+r.telefono:''}</div>
            </div>`).join('');
    } else {
        enc = '<div style="color:#9aa1b4;font-style:italic;font-size:13px;">Sin encargado asignado a esta parroquia.</div>';
    }
    const pc = d.por_color || {};
    const card = (lbl,val,color) =>
        `<div style="flex:1;text-align:center;padding:10px 6px;background:${color}14;border-radius:9px;border:1px solid ${color}44;">
            <div style="font-size:22px;font-weight:bold;color:${color};">${val}</div>
            <div style="font-size:10px;color:#555;text-transform:uppercase;">${lbl}</div></div>`;
    const tarjetas =
        `<div style="display:flex;gap:6px;margin:12px 0;flex-wrap:wrap;">
            ${card('Rojo', pc.rojo||0, '#A61C1C')}
            ${card('Amarillo', pc.amarillo||0, '#C9A227')}
            ${card('Verde', pc.verde||0, '#2E7D32')}
            ${card('Derrumbado', pc.derrumbado||0, '#2B2B2B')}
        </div>`;
    let edifs = '';
    if (d.edificaciones && d.edificaciones.length) {
        edifs = d.edificaciones.map(e => {
            const estadoTxt = e.completado ? 'Levantamiento completo' : 'En levantamiento';
            const barra = e.completado
                ? `<div style="background:#eef0f5;border-radius:10px;height:8px;overflow:hidden;margin-top:5px;">
                     <div style="width:${e.avance}%;height:100%;background:#2E7D32;"></div></div>
                   <div style="font-size:11px;color:#55617f;margin-top:2px;">${e.avance}% de avance</div>`
                : '';
            return `<div style="border:1px solid #e8ebf3;border-radius:9px;padding:10px 12px;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:7px;">
                    <span style="width:11px;height:11px;border-radius:50%;background:${e.color};display:inline-block;"></span>
                    <span style="font-weight:600;color:#2a3140;flex:1;">${e.nombre||'Edificación'}</span>
                </div>
                <div style="font-size:11px;color:#767c94;margin-top:3px;"><i class="bi bi-tools"></i> ${estadoTxt}</div>
                ${barra}
                <div style="margin-top:7px;display:flex;gap:6px;">
                    <a href="${APP_URL_BASE}seguimiento/levantamiento.php?inspeccion=${e.inspeccion_id}" style="font-size:11px;">Levantamiento</a>
                    ${e.completado ? `<a href="${APP_URL_BASE}seguimiento/remodelacion.php?inspeccion=${e.inspeccion_id}" style="font-size:11px;color:#2E7D32;">Seguimiento de avance</a>` : ''}
                </div>
            </div>`;
        }).join('');
    } else {
        edifs = '<div style="color:#9aa1b4;font-style:italic;font-size:13px;">Ninguna edificación ha comenzado el levantamiento.</div>';
    }
    cont.innerHTML = `
        <div style="font-size:12px;font-weight:700;color:#22366F;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="bi bi-person-badge"></i> Encargado</div>
        ${enc}
        <div style="font-size:12px;font-weight:700;color:#22366F;text-transform:uppercase;letter-spacing:.4px;margin:16px 0 4px;"><i class="bi bi-building"></i> Edificaciones (${d.total})</div>
        ${tarjetas}
        <div style="font-size:13px;color:#55617f;margin-bottom:10px;"><i class="bi bi-play-circle"></i> ${d.comenzadas} de ${d.total} comenzaron el levantamiento</div>
        <div style="font-size:12px;font-weight:700;color:#22366F;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 8px;"><i class="bi bi-list-check"></i> Seguimiento de edificaciones</div>
        ${edifs}
        <button onclick="descargarPdfParroquia('${d.estado}','${d.parroquia}')" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:14px;">
            <i class="bi bi-file-earmark-pdf"></i> Descargar PDF de la parroquia
        </button>`;
}

function descargarPdfParroquia(estado, parroquia) {
    window.location.href = APP_URL_BASE + 'dashboard/pdf_parroquia.php?estado=' + encodeURIComponent(estado) + '&parroquia=' + encodeURIComponent(parroquia);
}

// Mapa normaliza texto para casar parroquia del geojson con la del conteo.
function normTxt(s){ return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim(); }

// Índice de conteo por parroquia (normalizado).
const CONTEO_IDX = {};
CONTEO_PARROQUIAS.forEach(c => { CONTEO_IDX[normTxt(c.parroquia)] = c; });

let capaPuntos = null;       // capa de puntos de la parroquia seleccionada
let capaBurbujas = null;     // capa de burbujas de conteo
let parroquiaActiva = null;

(function initMapa() {
    const CARACAS = [10.5061, -66.9146];
    map = L.map('seg-map', { zoomControl: true }).setView(CARACAS, 12);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19, attribution: 'Esri'
    }).addTo(map);

    capaBurbujas = L.featureGroup().addTo(map);
    capaPuntos = L.featureGroup().addTo(map);

    // Dibujar parroquias del DC + una burbuja de conteo en el centro de cada una.
    fetch(APP_URL_BASE + 'assets/geo/parroquias/distrito_capital.geojson')
        .then(r => r.json())
        .then(geo => {
            L.geoJSON(geo, {
                style: { color:'#C9A227', weight:1.5, fillColor:'#22366F', fillOpacity:0.08 },
                onEachFeature: (feature, layer) => {
                    const nombre = feature.properties.parroquia || '';
                    const estado = feature.properties.estado || 'Distrito Capital';
                    layer.on('mouseover', () => layer.setStyle({ fillOpacity:0.20, weight:2.5 }));
                    layer.on('mouseout', () => layer.setStyle({ fillOpacity:0.08, weight:1.5 }));
                    layer.on('click', () => seleccionarParroquia(estado, nombre, layer.getBounds()));

                    // Burbuja con el conteo de la parroquia.
                    const c = CONTEO_IDX[normTxt(nombre)];
                    const total = c ? parseInt(c.total) : 0;
                    if (total > 0) {
                        const centro = layer.getBounds().getCenter();
                        const size = 34 + Math.min(26, Math.round(total/40));
                        const burbuja = L.marker(centro, {
                            icon: L.divIcon({
                                className: 'parr-burbuja',
                                html: `<div style="width:${size}px;height:${size}px;background:#22366F;border:2px solid #fff;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;box-shadow:0 2px 6px rgba(0,0,0,.3);cursor:pointer;">${total}</div>
                                       <div style="text-align:center;font-size:10px;color:#fff;text-shadow:0 1px 2px #000;font-weight:600;margin-top:1px;">${nombre}</div>`,
                                iconSize: [size, size], iconAnchor: [size/2, size/2],
                            })
                        });
                        burbuja.on('click', () => seleccionarParroquia(estado, nombre, layer.getBounds()));
                        capaBurbujas.addLayer(burbuja);
                    }
                }
            }).addTo(map);
        }).catch(()=>{});

    document.getElementById('seg-panel-close').addEventListener('click', () => {
        document.getElementById('seg-panel').classList.remove('open');
        seleccionado = null;
    });
})();

// Al seleccionar una parroquia: cargar SOLO sus puntos y abrir el panel.
async function seleccionarParroquia(estado, parroquia, bounds) {
    parroquiaActiva = parroquia;
    // Ocultar burbujas y hacer zoom a la parroquia.
    if (bounds) map.fitBounds(bounds.pad(0.05));
    capaBurbujas.setStyle ? null : null;
    // Cargar los puntos de esta parroquia (bajo demanda).
    capaPuntos.clearLayers();
    marcadores = {};
    try {
        const res = await fetch(PUNTOS_URL + '?estado=' + encodeURIComponent(estado) + '&parroquia=' + encodeURIComponent(parroquia));
        const d = await res.json();
        if (d.ok && d.puntos.length) {
            d.puntos.forEach(p => {
                const m = L.marker([p.lat, p.lng], { icon: iconoPunto(p), title: p.nombre });
                m.on('click', () => abrirPanel(p));
                marcadores[p.id] = m;
                capaPuntos.addLayer(m);
            });
        }
    } catch(e) {}
    // Abrir el panel de la parroquia (encargado + resumen).
    abrirPanelParroquia(estado, parroquia);
}

// Botón para volver a la vista general (burbujas).
function volverVistaParroquias() {
    parroquiaActiva = null;
    capaPuntos.clearLayers();
    map.setView([10.5061, -66.9146], 12);
    cerrarPanelParroquia();
}

// ===================== BÚSQUEDA DE EDIFICACIONES =====================
let _ultimaBusqueda = [];   // resultados actuales, para imprimir

async function ejecutarBusqueda() {
    const q = document.getElementById('f-buscar').value.trim();
    const sel = document.getElementById('f-parroquia');
    const parroquia = sel.value;
    const estado = document.getElementById('f-estado').value;
    const enteId = document.getElementById('f-ente').value;
    const color = document.getElementById('f-color').value;

    if (!q && !parroquia && !enteId && !color) { limpiarBusqueda(); return; }

    const cont = document.getElementById('f-resultados');
    cont.style.display = 'block';
    cont.innerHTML = '<p class="text-muted" style="margin:0;">Buscando…</p>';

    try {
        const url = BUSCAR_URL + '?q=' + encodeURIComponent(q)
                  + '&parroquia=' + encodeURIComponent(parroquia)
                  + '&estado=' + encodeURIComponent(estado)
                  + '&ente_id=' + encodeURIComponent(enteId)
                  + '&color=' + encodeURIComponent(color);
        const res = await fetch(url);
        const d = await res.json();
        if (!d.ok) { cont.innerHTML = '<p class="text-muted" style="margin:0;">' + (d.mensaje || 'Error.') + '</p>'; return; }
        _ultimaBusqueda = d.puntos;
        pintarResultados(d.puntos);
        dibujarPuntosEnMapa(d.puntos);
    } catch(e) {
        cont.innerHTML = '<p class="text-muted" style="margin:0;">Error de red.</p>';
    }
}

function pintarResultados(puntos) {
    const cont = document.getElementById('f-resultados');
    if (!puntos.length) {
        cont.innerHTML = '<p class="text-muted" style="margin:0;"><i class="bi bi-search"></i> Sin resultados.</p>';
        return;
    }
    let filas = puntos.map(p => `
        <div style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-bottom:1px solid #f0f2f7;cursor:pointer;"
             onclick='abrirPanel(${JSON.stringify(p).replace(/'/g, "&#39;")})'>
            <span style="width:11px;height:11px;border-radius:50%;background:${p.color};flex-shrink:0;"></span>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${p.nombre||'Sin nombre'}</div>
                <div style="font-size:11px;color:#767c94;">${p.codigo} · ${p.parroquia} · ${p.decision}</div>
            </div>
            ${p.tiene_coord ? '<i class="bi bi-geo-alt-fill" style="color:#2d4488;" title="Ubicada en el mapa"></i>' : '<i class="bi bi-geo" style="color:#c9a227;" title="Sin coordenadas"></i>'}
        </div>`).join('');
    cont.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
            <div style="font-weight:700;color:#22366F;"><i class="bi bi-list-ul"></i> ${puntos.length} resultado(s)</div>
            <button class="btn btn-outline btn-sm" onclick="imprimirResultados()"><i class="bi bi-file-earmark-pdf"></i> Imprimir lista</button>
        </div>
        <div style="max-height:280px;overflow-y:auto;">${filas}</div>`;
}

function dibujarPuntosEnMapa(puntos) {
    capaBurbujas.clearLayers();
    capaPuntos.clearLayers();
    marcadores = {};
    const coords = [];
    puntos.forEach(p => {
        if (!p.tiene_coord) return;
        const m = L.marker([p.lat, p.lng], { icon: iconoPunto(p), title: p.nombre });
        m.on('click', () => abrirPanel(p));
        marcadores[p.id] = m;
        capaPuntos.addLayer(m);
        coords.push([p.lat, p.lng]);
    });
    if (coords.length) map.fitBounds(L.latLngBounds(coords).pad(0.2));
}

function limpiarBusqueda() {
    document.getElementById('f-buscar').value = '';
    document.getElementById('f-parroquia').value = '';
    document.getElementById('f-resultados').style.display = 'none';
    document.getElementById('f-resultados').innerHTML = '';
    capaPuntos.clearLayers();
    // Volver a mostrar las burbujas de conteo por parroquia.
    location.reload();
}

// Imprimir la lista de resultados (abre ventana lista para PDF/impresión).
function imprimirResultados() {
    if (!_ultimaBusqueda.length) return;
    const hoy = new Date().toLocaleDateString('es-VE');
    // Contar por color para el resumen.
    const cnt = { '#A61C1C':0, '#C9A227':0, '#2E7D32':0, '#2B2B2B':0 };
    _ultimaBusqueda.forEach(p => { if (cnt[p.color] !== undefined) cnt[p.color]++; });

    const filas = _ultimaBusqueda.map((p, i) => `
        <tr>
            <td style="text-align:center;">${i+1}</td>
            <td><span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:${p.color};"></span></td>
            <td>${p.nombre || 'Sin nombre'}</td>
            <td>${p.codigo || ''}</td>
            <td>${p.parroquia || ''}</td>
            <td>${p.decision || ''}</td>
            <td>${p.ente || 'Sin asignar'}</td>
        </tr>`).join('');

    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lista de edificaciones</title>
        <style>
            * { font-family: Arial, sans-serif; }
            body { margin: 24px; color: #2a3140; }
            h1 { color: #22366F; font-size: 20px; margin: 0; }
            .sub { color: #767c94; font-size: 12px; margin: 2px 0 0; }
            .linea { height: 3px; background: #C9A227; width: 70px; margin: 10px 0; }
            .resumen { display: flex; gap: 8px; margin: 12px 0; }
            .rcard { flex: 1; text-align: center; padding: 8px; border-radius: 8px; border: 1px solid #ddd; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th { background: #22366F; color: #fff; font-size: 11px; padding: 7px 6px; text-align: left; }
            td { font-size: 11px; padding: 5px 6px; border-bottom: 1px solid #e8ebf3; }
            @media print { .noprint { display: none; } }
        </style></head><body>
        <h1>Lista de edificaciones</h1>
        <p class="sub">Seguimiento y Control · ${_ultimaBusqueda.length} edificaciones · ${hoy}</p>
        <div class="linea"></div>
        <div class="resumen">
            <div class="rcard" style="color:#A61C1C;border-color:#A61C1C55;"><b style="font-size:20px;">${cnt['#A61C1C']}</b><br>ROJO</div>
            <div class="rcard" style="color:#C9A227;border-color:#C9A22755;"><b style="font-size:20px;">${cnt['#C9A227']}</b><br>AMARILLO</div>
            <div class="rcard" style="color:#2E7D32;border-color:#2E7D3255;"><b style="font-size:20px;">${cnt['#2E7D32']}</b><br>VERDE</div>
            <div class="rcard" style="color:#2B2B2B;border-color:#2B2B2B55;"><b style="font-size:20px;">${cnt['#2B2B2B']}</b><br>DERRUMBADO</div>
        </div>
        <table>
            <thead><tr><th>#</th><th></th><th>Edificación</th><th>Código</th><th>Parroquia</th><th>Status</th><th>Ente</th></tr></thead>
            <tbody>${filas}</tbody>
        </table>
        <p class="noprint" style="margin-top:16px;text-align:center;">
            <button onclick="window.print()" style="padding:8px 20px;background:#22366F;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:14px;">Imprimir / Guardar PDF</button>
        </p>
        <script>window.onload = () => setTimeout(() => window.print(), 400);<\/script>
        </body></html>`;

    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
}
</script>

<!-- Panel lateral de parroquia (encargado + resumen) -->
<div id="panel-parroquia" style="position:fixed;top:0;right:-460px;width:440px;max-width:92vw;height:100vh;background:#fff;box-shadow:-4px 0 24px rgba(20,25,40,.18);z-index:1200;transition:right .28s;overflow-y:auto;">
    <div style="background:#22366F;color:#fff;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-size:11px;opacity:.8;text-transform:uppercase;letter-spacing:.5px;">Parroquia</div>
            <div id="pp-nombre" style="font-size:19px;font-weight:700;">—</div>
        </div>
        <button onclick="cerrarPanelParroquia()" style="background:none;border:0;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div id="pp-contenido" style="padding:18px 20px;">
        <p class="text-muted">Cargando…</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
