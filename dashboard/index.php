<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('dashboard', 'ver');

$widgetsCfg = obtenerConfigDashboard();
$wcfg = array_combine(array_column($widgetsCfg, 'id'), $widgetsCfg);
$kpisCustomDefs = array_values(array_filter(obtenerConfigKpisCustom(), fn($k) => !empty($k['visible'])));
/** Atributo style= listo para pegar en el widget (orden + color/degradado), o cadena vacía. */
function estiloDash(array $wcfg, string $id): string {
    $w = $wcfg[$id] ?? null;
    if (!$w) return '';
    $partes = 'order:' . (int)($w['orden'] ?? 0) . ';';
    $partes .= estiloWidgetDashboard($w);
    return $partes;
}
function visibleDash(array $wcfg, string $id): bool {
    return $wcfg[$id]['visible'] ?? true;
}

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Panorama general de inspecciones estructurales post-sismo';
$activeModule = 'dashboard';

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

<div class="flex items-center justify-between gap-12" style="flex-wrap:wrap;margin-bottom:18px;">
    <div class="flex items-center gap-12" style="flex-wrap:wrap;">
        <span class="badge badge-gris"><i class="bi bi-arrow-repeat"></i> Actualizado <span id="hora-actualizacion">—</span></span>
        <span class="text-sm text-muted">Actualización automática cada 60s</span>
        <select id="filtro-parroquia" class="form-control" style="width:auto;min-width:190px;">
            <option value="">Todas las parroquias</option>
        </select>
        <button id="btn-quitar-filtro" class="btn btn-outline btn-sm" style="display:none;">
            <i class="bi bi-x-lg"></i> Quitar filtro
        </button>
    </div>
    <?php if (puede('formulario', 'crear')): ?>
    <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-accent btn-sm">
        <i class="bi bi-plus-lg"></i> Nueva inspección
    </a>
    <?php endif; ?>
</div>

<div class="split-grid cols-10-14 align-start dashboard-chart-map" style="margin-bottom:16px;">
    <div class="dashboard-left-col">
        <div class="flex gap-12" style="align-items:stretch;flex-wrap:wrap;">
            <?php if (visibleDash($wcfg, 'kpi_inspecciones')): ?>
            <div class="kpi-hero tv-hero" style="flex:1 1 200px;<?= estiloDash($wcfg, 'kpi_inspecciones') ?>">
                <div class="icon"><i class="bi bi-clipboard2-data-fill"></i></div>
                <div>
                    <div class="num" id="kpi-inspecciones">—</div>
                    <div class="lbl">Inspecciones realizadas</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (visibleDash($wcfg, 'kpi_personas')): ?>
            <div class="kpi-hero tv-hero" style="flex:1 1 200px;<?= estiloDash($wcfg, 'kpi_personas') ?>">
                <div class="icon"><i class="bi bi-person-hearts"></i></div>
                <div>
                    <div class="num" id="kpi-personas-totales">—</div>
                    <div class="lbl">Personas afectadas</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (visibleDash($wcfg, 'kpi_grid')): ?>
        <div style="<?= estiloDash($wcfg, 'kpi_grid') ?>padding:<?= $wcfg['kpi_grid']['color'] ? '14px' : '0' ?>;border-radius:14px;">
        <div class="tv-kpi-grid">
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-people-fill"></i></div><div><div class="num" id="kpi-familias">—</div><div class="lbl">Familias</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-fill"></i></div><div><div class="num" id="kpi-hombres">—</div><div class="lbl">Hombres</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-gender-female"></i></div><div><div class="num" id="kpi-mujeres">—</div><div class="lbl">Mujeres</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-emoji-smile-fill"></i></div><div><div class="num" id="kpi-ninos">—</div><div class="lbl">Niños</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-wheelchair"></i></div><div><div class="num" id="kpi-mreducida">—</div><div class="lbl">M. Reducida</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-walking"></i></div><div><div class="num" id="kpi-terceraedad">—</div><div class="lbl">3ra. Edad</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-heart-pulse-fill"></i></div><div><div class="num" id="kpi-gestantes">—</div><div class="lbl">Gestantes</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-heart-fill"></i></div><div><div class="num" id="kpi-mascotas">—</div><div class="lbl">Mascotas</div></div></div>
        </div>
        </div>
        <?php endif; ?>

        <?php if (visibleDash($wcfg, 'kpis_custom') && $kpisCustomDefs): ?>
        <div style="<?= estiloDash($wcfg, 'kpis_custom') ?>padding:<?= $wcfg['kpis_custom']['color'] ? '14px' : '0' ?>;border-radius:14px;">
        <div class="tv-kpi-grid">
            <?php foreach ($kpisCustomDefs as $k): ?>
            <div class="tv-kpi-card" style="<?= e(estiloWidgetDashboard($k)) ?>">
                <div class="icon"><i class="bi <?= e($k['icono'] ?: 'bi-graph-up-arrow') ?>"></i></div>
                <div><div class="num" id="kpi-custom-<?= e($k['id']) ?>">—</div><div class="lbl"><?= e($k['label']) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>
        <?php endif; ?>

        <?php if (visibleDash($wcfg, 'chart_decision') || visibleDash($wcfg, 'chart_parroquia')): ?>
        <div class="split-grid cols-11">
            <?php if (visibleDash($wcfg, 'chart_decision')): ?>
            <div class="card" style="<?= estiloDash($wcfg, 'chart_decision') ?>">
                <div class="card-header">
                    <h2 class="tv-section-title"><i class="bi bi-bar-chart-fill"></i> Estado de acceso a la edificación</h2>
                </div>
                <div class="card-body">
                    <div class="flex justify-between items-center" style="margin-bottom:8px;">
                        <span class="text-sm text-muted" style="font-size:13px;">Decisión final (semaforización)</span>
                    </div>
                    <div id="leyenda-decision" class="flex gap-12" style="flex-wrap:wrap;font-size:15px;margin-bottom:10px;"></div>
                    <div style="height:340px;">
                        <canvas id="chart-decision"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (visibleDash($wcfg, 'chart_parroquia')): ?>
            <div class="card" style="<?= estiloDash($wcfg, 'chart_parroquia') ?>">
                <div class="card-header" style="flex-wrap:wrap;gap:10px;">
                    <h2 class="tv-section-title" id="titulo-chart-parroquia"><i class="bi bi-geo-alt-fill"></i> Inspecciones por parroquia</h2>
                    <div class="flex items-center gap-8" style="flex-wrap:wrap;">
                        <select id="filtro-decision-parroquia" class="form-control" style="width:auto;min-width:170px;">
                            <option value="">Todas las decisiones (total)</option>
                        </select>
                        <button id="btn-orden-parroquia" type="button" class="btn btn-outline btn-sm" title="Cambiar orden">
                            <i class="bi bi-sort-numeric-down"></i> Mayor a menor
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="chart-parroquia-scroll" style="height:340px;overflow-y:auto;">
                        <div id="chart-parroquia-inner" style="height:340px;">
                            <canvas id="chart-parroquia"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (visibleDash($wcfg, 'mapa')): ?>
    <div class="card card-mapa" style="<?= estiloDash($wcfg, 'mapa') ?>">
        <div class="card-header">
            <h2 class="tv-section-title" id="titulo-mapa"><i class="bi bi-map-fill"></i> Mapa geográfico por parroquia</h2>
            <span class="text-sm text-muted" id="contador-mapa"></span>
        </div>
        <div class="mapa-wrap">
            <div id="map-inspecciones"></div>
            <div class="mapa-leyenda">
                <div class="item"><span class="dot" style="background:#2E7D32;"></span> Acceso Permitido</div>
                <div class="item"><span class="dot" style="background:#C9A227;"></span> Precaución al Entrar</div>
                <div class="item"><span class="dot" style="background:#A61C1C;"></span> Acceso No Permitido</div>
                <div class="item text-muted" id="nota-limites" style="font-size:10.5px;max-width:220px;"></div>
            </div>
            <div id="lista-edificios" class="mapa-lista"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<button class="btn btn-primary btn-presentacion" id="btn-modo-tv" title="Modo presentación para TV">
    <i class="bi bi-fullscreen"></i> Modo presentación
</button>

<!-- Modal ficha técnica -->
<div id="modal-ficha" class="modal-overlay" style="display:none;"></div>

<!-- Visor de fotos en grande (sobre la ficha técnica) -->
<div id="lightbox-foto" class="lightbox-overlay" style="display:none;"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
const API_URL   = '<?= APP_URL_BASE ?>dashboard/api_kpis.php';
const FICHA_URL = '<?= APP_URL_BASE ?>dashboard/api_ficha.php';

// El plugin se registra globalmente pero cada gráfico decide si lo usa
// mediante su propia opción "datalabels" (chart-decision sí, para mostrar
// el valor sobre cada barra como en la referencia de diseño; chart-parroquia
// no, por tener muchas barras y verse recargado).
if (typeof ChartDataLabels !== 'undefined') {
    Chart.register(ChartDataLabels);
}

let map, clusterLayer, seccionesLayer, chartDecision, chartParroquia;
let geojsonLimitesParroquias = undefined; // undefined = aún no se intentó cargar; null = no existe; objeto = cargado
let parroquiaSeleccionada = '';           // '' = sin filtro (todas)
let ultimaParroquiaEnMapa;                // controla cuándo animar el zoom (evita re-animar en cada refresh)
let ultimoDatosDashboard = null;          // última respuesta de la API, para poder re-renderizar sin refetch
let decisionRankingSeleccionada = '';     // '' = total (todas las decisiones); si no, ranking de esa decisión
let ordenRankingDesc = true;              // true = mayor a menor, false = menor a mayor
let decisionSeleccionada = '';            // filtro por decisión final (semáforo), aplica a KPIs, mapa y listas

function normalizarTexto(s) {
    return (s || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toLowerCase().trim()
        .replace(/^(la|el|los|las)\s+/, ''); // ignora artículos iniciales (ej. "La Candelaria" vs "Candelaria")
}

async function obtenerLimitesParroquias() {
    if (geojsonLimitesParroquias !== undefined) return geojsonLimitesParroquias;
    try {
        const res = await fetch('<?= APP_URL_BASE ?>assets/geo/parroquias_libertador.geojson', { cache: 'no-store' });
        if (!res.ok) { geojsonLimitesParroquias = null; return null; }
        const gj = await res.json();
        geojsonLimitesParroquias = (gj && Array.isArray(gj.features) && gj.features.length) ? gj : null;
    } catch (e) {
        geojsonLimitesParroquias = null;
    }
    return geojsonLimitesParroquias;
}

const CLAVES_NOMBRE_PARROQUIA = ['parroquia', 'PARROQUIA', 'nombre', 'NOMBRE', 'name', 'NAME', 'NAME_3', 'ADM3_ES', 'shapeName', 'adm3_name', 'adm3_ref_n'];
function nombreParroquiaDeFeature(feature) {
    const props = feature.properties || {};
    for (const clave of CLAVES_NOMBRE_PARROQUIA) {
        if (props[clave]) return props[clave];
    }
    return null;
}

function initMap() {
    if (!document.getElementById('map-inspecciones')) return; // widget "mapa" oculto por configuración
    map = L.map('map-inspecciones', { zoomControl: true }).setView([10.4880, -66.9200], 12);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics',
        maxZoom: 19,
    }).addTo(map);

    clusterLayer = L.markerClusterGroup({
        maxClusterRadius: 45,
        iconCreateFunction: function (cluster) {
            const count = cluster.getChildCount();
            const size = count > 50 ? 54 : count > 15 ? 46 : 36;
            return L.divIcon({
                html: '<div class="marker-cluster-custom" style="width:' + size + 'px;height:' + size + 'px;font-size:' + (size > 40 ? 14 : 12) + 'px;">' + count + '</div>',
                className: '',
                iconSize: [size, size],
            });
        }
    });
    seccionesLayer = L.layerGroup();
    map.addLayer(clusterLayer);
    map.addLayer(seccionesLayer);
}

function formatNum(n) {
    return new Intl.NumberFormat('es-VE').format(n || 0);
}

/** Actualiza el texto de un elemento solo si existe (el widget puede estar oculto por configuración). */
function setTxt(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function poblarFiltroDecisionParroquia(listaDecision) {
    const select = document.getElementById('filtro-decision-parroquia');
    if (!select || select.dataset.poblado) return; // widget oculto, o ya poblado (las 3 decisiones no cambian)
    listaDecision.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.label;
        opt.textContent = d.label;
        select.appendChild(opt);
    });
    select.dataset.poblado = '1';
}

// Dibuja/actualiza el gráfico "Inspecciones por parroquia". Si hay una
// decisión elegida en el filtro de ranking, muestra solo esa decisión
// (ranking de parroquias con más casos en esa etiqueta); si no, muestra
// el total por parroquia como siempre. Respeta el orden mayor/menor.
function renderChartParroquia(data) {
    if (!document.getElementById('chart-parroquia')) return; // widget "chart_parroquia" oculto por configuración
    let items;
    if (decisionRankingSeleccionada) {
        items = data.por_parroquia_decision
            .filter(p => p.decision === decisionRankingSeleccionada)
            .map(p => ({ parroquia: p.parroquia, total: p.total, color: p.color }));
    } else {
        items = data.por_parroquia.map(p => ({ parroquia: p.parroquia, total: p.total, color: null }));
    }

    items.sort((a, b) => ordenRankingDesc ? b.total - a.total : a.total - b.total);

    document.getElementById('titulo-chart-parroquia').innerHTML =
        '<i class="bi bi-geo-alt-fill"></i> Inspecciones por parroquia' +
        (decisionRankingSeleccionada ? ' · ' + decisionRankingSeleccionada : '') +
        (parroquiaSeleccionada ? ' (' + parroquiaSeleccionada + ' resaltada)' : '');

    const ctxP = document.getElementById('chart-parroquia');
    // Con los dos gráficos lado a lado, el card_parroquia es más angosto que
    // antes; se le da altura dinámica según la cantidad de parroquias (con
    // scroll interno) para que cada barra siga siendo legible.
    const inner = document.getElementById('chart-parroquia-inner');
    if (inner) inner.style.height = Math.max(340, items.length * 24) + 'px';
    const dataP = {
        labels: items.map(p => p.parroquia),
        datasets: [{
            data: items.map(p => p.total),
            backgroundColor: items.map(p => {
                if (p.parroquia === parroquiaSeleccionada) return '#f2a71b';
                return p.color || '#2d4488';
            }),
            borderRadius: 5,
        }]
    };
    if (chartParroquia) { chartParroquia.data = dataP; chartParroquia.update(); }
    else {
        chartParroquia = new Chart(ctxP, {
            type: 'bar', data: dataP,
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                onClick: (evt, elements) => {
                    if (!elements.length) return;
                    const nombre = chartParroquia.data.labels[elements[0].index];
                    seleccionarParroquia(nombre === parroquiaSeleccionada ? '' : nombre);
                },
                onHover: (evt, elements) => {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: { legend: { display: false }, datalabels: { display: false } },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 12.5 }, color: '#c7d2ea' },
                        grid: { color: 'rgba(255,255,255,.10)' },
                    },
                    y: {
                        ticks: { font: { size: 12.5 }, color: '#c7d2ea' },
                        grid: { display: false },
                    }
                }
            }
        });
    }
}

document.getElementById('filtro-decision-parroquia')?.addEventListener('change', function () {
    decisionRankingSeleccionada = this.value;
    if (ultimoDatosDashboard) renderChartParroquia(ultimoDatosDashboard);
});

document.getElementById('btn-orden-parroquia')?.addEventListener('click', function () {
    ordenRankingDesc = !ordenRankingDesc;
    this.innerHTML = ordenRankingDesc
        ? '<i class="bi bi-sort-numeric-down"></i> Mayor a menor'
        : '<i class="bi bi-sort-numeric-up"></i> Menor a mayor';
    if (ultimoDatosDashboard) renderChartParroquia(ultimoDatosDashboard);
});

function poblarFiltroParroquia(lista) {
    const select = document.getElementById('filtro-parroquia');
    if (select.dataset.poblado) return; // solo la primera vez (la lista de parroquias no cambia)
    const nombres = [...lista].map(p => p.parroquia).sort((a, b) => a.localeCompare(b, 'es'));
    for (const nombre of nombres) {
        const opt = document.createElement('option');
        opt.value = nombre;
        opt.textContent = nombre;
        select.appendChild(opt);
    }
    select.dataset.poblado = '1';
}

// Texto combinado de los filtros activos (parroquia y/o decisión), para
// usar en títulos y contadores del mapa/listas.
function descripcionFiltroActivo() {
    const partes = [];
    if (parroquiaSeleccionada) partes.push(parroquiaSeleccionada);
    if (decisionSeleccionada) partes.push(decisionSeleccionada);
    return partes.join(' · ');
}

function actualizarIndicadoresFiltro() {
    const activo = !!parroquiaSeleccionada;
    document.getElementById('filtro-parroquia').value = parroquiaSeleccionada;
    document.getElementById('btn-quitar-filtro').style.display = (activo || decisionSeleccionada) ? 'inline-flex' : 'none';
    const desc = descripcionFiltroActivo();
    const tituloMapa = document.getElementById('titulo-mapa');
    if (tituloMapa) {
        tituloMapa.innerHTML = '<i class="bi bi-map-fill"></i> Mapa geográfico' + (desc ? ' · ' + desc : ' por parroquia');
    }
}

function renderListaEdificios(lista) {
    const cont = document.getElementById('lista-edificios');
    if (!cont) return; // widget "mapa" oculto por configuración
    cont.innerHTML = '';
    const hayFiltro = !!(parroquiaSeleccionada || decisionSeleccionada);
    cont.classList.toggle('mapa-lista-hidden', !hayFiltro);
    if (!hayFiltro) {
        return;
    }

    const header = document.createElement('div');
    header.className = 'mapa-lista-header';

    const title = document.createElement('div');
    title.className = 'mapa-lista-title';
    title.textContent = `EDIFICIOS EN ${descripcionFiltroActivo().toUpperCase()}`;
    header.appendChild(title);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'mapa-lista-close';
    close.title = 'Quitar filtro';
    close.innerHTML = '<i class="bi bi-x-lg"></i>';
    close.addEventListener('click', () => { seleccionarParroquia(''); seleccionarDecision(''); });
    header.appendChild(close);

    cont.appendChild(header);

    const subtitle = document.createElement('div');
    subtitle.className = 'mapa-lista-subtitle';
    subtitle.textContent = lista && lista.length
        ? `${lista.length} inspección${lista.length === 1 ? '' : 'es'}`
        : 'No hay inspecciones registradas con este filtro.';
    cont.appendChild(subtitle);

    if (!lista || !lista.length) {
        const hint = document.createElement('div');
        hint.className = 'mapa-lista-empty';
        hint.textContent = 'Verifica si el filtro está correcto o intenta otra combinación.';
        cont.appendChild(hint);
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'mapa-lista-items';
    lista.forEach(item => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mapa-lista-item';
        btn.innerHTML = `
            <span class="mapa-lista-item-meta">
                <span class="mapa-lista-item-name">${item.nombre.toUpperCase()}</span>
                <span class="mapa-lista-item-status" style="background:${item.decision_color};">${item.decision}</span>
            </span>
        `;
        btn.addEventListener('click', () => abrirFicha(item.id));
        wrapper.appendChild(btn);
    });
    cont.appendChild(wrapper);
}

// Punto único de entrada para seleccionar/quitar el filtro: lo usan el
// <select>, el botón de quitar filtro, los clics en el gráfico de barras
// y los clics sobre el mapa (polígonos de parroquia).
function seleccionarParroquia(nombre) {
    parroquiaSeleccionada = nombre || '';
    cargarDashboard();
}

// Filtro por decisión final (semáforo): clic en una barra de "Estado de
// acceso a la edificación" o en la leyenda de colores. Actualiza KPIs,
// mapa y lista de edificios para mostrar solo esa decisión. Un segundo
// clic sobre la misma decisión la quita (toggle), igual que con parroquia.
function seleccionarDecision(nombre) {
    decisionSeleccionada = nombre || '';
    cargarDashboard();
}

document.getElementById('filtro-parroquia').addEventListener('change', function () {
    seleccionarParroquia(this.value);
});
document.getElementById('btn-quitar-filtro').addEventListener('click', function () {
    parroquiaSeleccionada = '';
    decisionSeleccionada = '';
    cargarDashboard();
});

async function cargarDashboard() {
    const params = new URLSearchParams();
    if (parroquiaSeleccionada) params.set('parroquia', parroquiaSeleccionada);
    if (decisionSeleccionada) params.set('decision', decisionSeleccionada);
    const qs = params.toString();
    const url = API_URL + (qs ? '?' + qs : '');
    const res = await fetch(url);
    const data = await res.json();

    poblarFiltroParroquia(data.por_parroquia);
    poblarFiltroDecisionParroquia(data.decision);
    actualizarIndicadoresFiltro();
    renderListaEdificios(data.inspecciones);
    ultimoDatosDashboard = data;

    document.getElementById('hora-actualizacion').textContent = data.actualizado;

    setTxt('kpi-inspecciones', formatNum(data.totales.inspecciones));
    const personasTotales = (Number(data.totales.hombres) || 0) + (Number(data.totales.mujeres) || 0) +
        (Number(data.totales.ninos) || 0) + (Number(data.totales.gestantes) || 0);
    setTxt('kpi-personas-totales', formatNum(personasTotales));
    setTxt('kpi-familias', formatNum(data.totales.familias));
    setTxt('kpi-hombres', formatNum(data.totales.hombres));
    setTxt('kpi-mujeres', formatNum(data.totales.mujeres));
    setTxt('kpi-ninos', formatNum(data.totales.ninos));
    setTxt('kpi-mreducida', formatNum(data.totales.movilidad_reducida));
    setTxt('kpi-terceraedad', formatNum(data.totales.adultos_tercera_edad));
    setTxt('kpi-gestantes', formatNum(data.totales.gestantes));
    setTxt('kpi-mascotas', formatNum(data.totales.mascotas));

    // ---- KPIs personalizados (definidos en Configuración del Sistema) ----
    if (data.kpis_custom) {
        Object.keys(data.kpis_custom).forEach(id => setTxt('kpi-custom-' + id, formatNum(data.kpis_custom[id])));
    }

    const leyenda = document.getElementById('leyenda-decision');
    if (leyenda) {
    leyenda.innerHTML = data.decision.map(d => `
        <span class="flex items-center gap-8 leyenda-decision-item${d.label === decisionSeleccionada ? ' activa' : ''}"
              data-decision="${d.label}" style="cursor:pointer;${decisionSeleccionada && d.label !== decisionSeleccionada ? 'opacity:.45;' : ''}">
            <span style="width:12px;height:12px;border-radius:3px;background:${d.color};display:inline-block;"></span>
            ${d.label}: <strong>${formatNum(d.total)}</strong>
        </span>
    `).join('');
    leyenda.querySelectorAll('[data-decision]').forEach(el => {
        el.addEventListener('click', () => {
            const nombre = el.dataset.decision;
            seleccionarDecision(nombre === decisionSeleccionada ? '' : nombre);
        });
    });
    }

    const ctxD = document.getElementById('chart-decision');
    if (ctxD) {
    const dataD = {
        labels: data.decision.map(d => d.label),
        datasets: [{
            data: data.decision.map(d => d.total),
            backgroundColor: data.decision.map(d =>
                (decisionSeleccionada && d.label !== decisionSeleccionada) ? d.color + '55' : d.color),
            borderRadius: 0,
            maxBarThickness: 90,
        }]
    };
    if (chartDecision) { chartDecision.data = dataD; chartDecision.update(); }
    else {
        chartDecision = new Chart(ctxD, {
            type: 'bar', data: dataD,
            options: {
                maintainAspectRatio: false,
                onClick: (evt, elements) => {
                    if (!elements.length) return;
                    const nombre = chartDecision.data.labels[elements[0].index];
                    seleccionarDecision(nombre === decisionSeleccionada ? '' : nombre);
                },
                onHover: (evt, elements) => {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        display: true,
                        color: '#fff',
                        anchor: 'end',
                        align: 'top',
                        font: { weight: 'bold', size: 15 },
                        formatter: (value) => formatNum(value),
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 13 }, color: '#c7d2ea' },
                        grid: { color: 'rgba(255,255,255,.10)' },
                    },
                    x: {
                        ticks: { font: { size: 13 }, color: '#c7d2ea' },
                        grid: { display: false },
                    }
                }
            }
        });
    }
    }

    renderChartParroquia(data);

    // ---- Mapa: secciones por parroquia (límites reales si están disponibles, si no, círculos aproximados) ----
    if (map) {
    seccionesLayer.clearLayers();
    const limites = await obtenerLimitesParroquias();

    // Lookup por nombre normalizado -> { total, color }
    const lookupParroquia = {};
    data.secciones_geo.forEach(s => { lookupParroquia[normalizarTexto(s.parroquia)] = s; });

    const esParroquiaSeleccionada = nombre => !!parroquiaSeleccionada &&
        normalizarTexto(nombre) === normalizarTexto(parroquiaSeleccionada);

    let boundsSeleccion = null; // se usa para hacer zoom si hay filtro activo

    if (limites) {
        L.geoJSON(limites, {
            style: function (feature) {
                const nombre = nombreParroquiaDeFeature(feature);
                const info = nombre ? lookupParroquia[normalizarTexto(nombre)] : null;
                const seleccionada = esParroquiaSeleccionada(nombre);
                return {
                    color: seleccionada ? '#6b7280' : '#9ca3af',
                    weight: seleccionada ? 4 : 2,
                    fillColor: '#d1d5db',
                    fillOpacity: parroquiaSeleccionada ? (seleccionada ? 0.32 : 0.08) : 0.12,
                };
            },
            onEachFeature: function (feature, layer) {
                const nombre = nombreParroquiaDeFeature(feature) || 'Parroquia';
                const info = lookupParroquia[normalizarTexto(nombre)];
                layer.bindTooltip(info ? formatNum(info.total) : '0', { permanent: true, direction: 'center', className: 'parroquia-label' });
                layer.on('click', () => seleccionarParroquia(esParroquiaSeleccionada(nombre) ? '' : nombre));
                layer.on('mouseover', () => layer.setStyle({ weight: 4 }));
                layer.on('mouseout', () => { if (!esParroquiaSeleccionada(nombre)) layer.setStyle({ weight: 2 }); });
                if (esParroquiaSeleccionada(nombre)) boundsSeleccion = layer.getBounds();
            }
        }).addTo(seccionesLayer);
    } else {
        const maxTotal = Math.max(1, ...data.secciones_geo.map(s => s.total));
        data.secciones_geo.forEach(s => {
            const seleccionada = esParroquiaSeleccionada(s.parroquia);
            const radius = 220 + (s.total / maxTotal) * 900; // metros
            const circulo = L.circle([s.lat, s.lng], {
                radius,
                color: seleccionada ? '#6b7280' : '#9ca3af',
                weight: seleccionada ? 3 : 1.5,
                fillColor: '#d1d5db',
                fillOpacity: parroquiaSeleccionada ? (seleccionada ? 0.32 : 0.05) : 0.16,
            }).addTo(seccionesLayer);
            circulo.on('click', () => seleccionarParroquia(seleccionada ? '' : s.parroquia));
            L.marker([s.lat, s.lng], {
                icon: L.divIcon({ className: 'parroquia-label', html: `<div>${formatNum(s.total)}</div>`, iconSize: null }),
                interactive: false,
            }).addTo(seccionesLayer);
            if (seleccionada) boundsSeleccion = circulo.getBounds();
        });
    }
    document.getElementById('nota-limites').textContent = limites
        ? 'Límites reales de parroquia cargados desde assets/geo/parroquias_libertador.geojson'
        : 'Mostrando secciones aproximadas (círculos). Vea assets/geo/LEEME.md para usar límites reales.';

    // Zoom/encuadre hacia la parroquia filtrada. Solo animamos cuando la
    // selección CAMBIÓ (no en cada refresh automático de 60s, para no
    // estar moviendo la cámara sola cada rato).
    if (ultimaParroquiaEnMapa !== parroquiaSeleccionada) {
        if (parroquiaSeleccionada && boundsSeleccion) {
            map.flyToBounds(boundsSeleccion, { padding: [40, 40], maxZoom: 16, duration: 0.7 });
        } else if (!parroquiaSeleccionada) {
            map.flyTo([10.4880, -66.9200], 12, { duration: 0.7 });
        }
        ultimaParroquiaEnMapa = parroquiaSeleccionada;
    }

    // ---- Mapa: marcadores individuales (agrupados en clusters). Se usa
    // la coordenada real (latitud/longitud) capturada en el formulario de
    // inspección para cada edificación — geolocalización exacta, sin
    // aproximar ni distribuir dentro de la parroquia. Los que no tengan
    // coordenada guardada simplemente no se dibujan en el mapa. ----
    clusterLayer.clearLayers();
    (data.puntos || []).forEach(p => {
            const lat = p.lat, lng = p.lng;
            if (lat == null || lng == null) return;
            const marcador = L.circleMarker([lat, lng], {
                radius: 7,
                weight: 1.5,
                color: '#1f2937',
                fillColor: p.decision_color,
                fillOpacity: 0.9,
            });
            marcador.bindTooltip(
                `<strong>${p.nombre}</strong><br>${p.parroquia}<br>${p.decision}`,
                { direction: 'top', offset: [0, -8] }
            );
            marcador.on('click', () => abrirFicha(p.id));
            clusterLayer.addLayer(marcador);
        });
    const descFiltro = descripcionFiltroActivo();
    document.getElementById('contador-mapa').textContent = descFiltro
        ? `${formatNum(data.totales.inspecciones)} inspecciones en ${descFiltro}`
        : `${formatNum(data.totales.inspecciones)} inspecciones totales`;
    } // fin if (map)
}

// ---- Ficha técnica (modal) ----
async function abrirFicha(id) {
    const res = await fetch(FICHA_URL + '?id=' + id);
    if (!res.ok) return;
    const f = await res.json();

    function filas(arr) {
        return arr.map(r => `<div class="ficha-row"><span class="k">${r.k}</span><span class="v">${r.v ?? '—'}</span></div>`).join('');
    }
    function galeria(fotos) {
        if (!fotos || !fotos.length) return '<p class="text-sm text-muted">Sin registro fotográfico.</p>';
        return fotos.map(g => `
            <div class="foto-categoria-titulo">${g.categoria_label} (${g.fotos.length})</div>
            <div class="foto-galeria">
                ${g.fotos.map(ph => `<a href="javascript:void(0)" onclick="abrirLightbox('${ph.url}')"><img src="${ph.url}" loading="lazy"></a>`).join('')}
            </div>
        `).join('');
    }

    const html = `
    <div class="modal-ficha">
        <div class="modal-head">
            <div>
                <h3>${f.nombre_edificio}</h3>
                <span class="codigo">${f.codigo}</span>
            </div>
            <button class="modal-close" onclick="cerrarFicha()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <span class="badge" style="background:${f.decision_color}22;color:${f.decision_color};font-size:12.5px;padding:7px 14px;margin-bottom:16px;display:inline-block;">${f.decision_final}</span>

            <div class="section-title" style="margin-top:6px;"><i class="bi bi-geo-alt-fill"></i> Ubicación</div>
            <div class="ficha-grid">${filas(f.ubicacion)}</div>

            <div class="section-title"><i class="bi bi-building"></i> Identificación de la edificación</div>
            <div class="ficha-grid">${filas(f.identificacion)}</div>

            <div class="section-title"><i class="bi bi-exclamation-diamond-fill"></i> Evaluación de riesgo</div>
            <div class="ficha-grid">${filas(f.riesgo)}</div>

            ${f.danos_estructurales.length ? `<div class="section-title"><i class="bi bi-bricks"></i> Daño en elementos estructurales</div><div class="ficha-grid">${filas(f.danos_estructurales)}</div>` : ''}
            ${f.danos_no_estructurales.length ? `<div class="section-title"><i class="bi bi-layout-wtf"></i> Daño en elementos no estructurales</div><div class="ficha-grid">${filas(f.danos_no_estructurales)}</div>` : ''}

            <div class="section-title"><i class="bi bi-people-fill"></i> Personas y animales afectados</div>
            <div class="ficha-grid">${filas(f.personas)}</div>

            <div class="section-title"><i class="bi bi-person-badge-fill"></i> Profesional responsable</div>
            <div class="ficha-grid">${filas(f.profesional)}</div>

            ${f.observaciones ? `<div class="section-title"><i class="bi bi-card-text"></i> Observaciones</div><p class="text-sm">${f.observaciones}</p>` : ''}
            ${f.recomendaciones ? `<div class="section-title"><i class="bi bi-lightbulb-fill"></i> Recomendaciones</div><p class="text-sm">${f.recomendaciones}</p>` : ''}

            <div class="section-title"><i class="bi bi-camera-fill"></i> Registro fotográfico ${f.fotos.length ? '(' + f.fotos.reduce((n,g) => n + g.fotos.length, 0) + ' fotos)' : ''}</div>
            ${galeria(f.fotos)}

            <div class="flex gap-8" style="margin-top:20px;">
                <a href="${f.url_ficha_completa}" class="btn btn-outline btn-sm"><i class="bi bi-arrow-up-right-square"></i> Abrir en el módulo de formulario</a>
            </div>
        </div>
    </div>`;

    const overlay = document.getElementById('modal-ficha');
    overlay.innerHTML = html;
    overlay.style.display = 'flex';
}
function cerrarFicha() {
    document.getElementById('modal-ficha').style.display = 'none';
}
document.getElementById('modal-ficha').addEventListener('click', function (e) {
    if (e.target === this) cerrarFicha();
});

// ---- Visor de fotos en grande (lightbox), sobre la ficha técnica ----
function abrirLightbox(url) {
    const overlay = document.getElementById('lightbox-foto');
    overlay.innerHTML = `
        <button class="modal-close lightbox-close" onclick="cerrarLightbox()"><i class="bi bi-x-lg"></i></button>
        <img src="${url}" alt="Foto ampliada">
    `;
    overlay.style.display = 'flex';
}
function cerrarLightbox() {
    document.getElementById('lightbox-foto').style.display = 'none';
    document.getElementById('lightbox-foto').innerHTML = '';
}
document.getElementById('lightbox-foto').addEventListener('click', function (e) {
    if (e.target === this) cerrarLightbox();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrarLightbox();
});

// ---- Modo presentación (TV) ----
document.getElementById('btn-modo-tv').addEventListener('click', function () {
    document.body.classList.toggle('modo-tv');
    const isTv = document.body.classList.contains('modo-tv');
    this.innerHTML = isTv
        ? '<i class="bi bi-fullscreen-exit"></i> Salir de presentación'
        : '<i class="bi bi-fullscreen"></i> Modo presentación';
    if (isTv && document.documentElement.requestFullscreen) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else if (!isTv && document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
    }
    setTimeout(() => { if (map) map.invalidateSize(); }, 250);
});

// Recalcula el tamaño del mapa cuando cambia el layout (sidebar, resize)
window.addEventListener('sismos:layout-change', () => {
    if (map) map.invalidateSize();
});

initMap();
cargarDashboard();
setInterval(cargarDashboard, 60000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
