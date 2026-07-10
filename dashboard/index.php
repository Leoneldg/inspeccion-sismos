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
/** Orden representativo de una fila que agrupa varios widgets (el menor de ellos),
 *  para que compita en igualdad de condiciones con kpi_grid/kpis_custom, que sí
 *  tienen su propio "order" individual. Sin esto, una fila sin "order" explícito
 *  (valor por defecto 0) siempre se dibuja antes que cualquier widget con
 *  order > 0, sin importar su posición real en el HTML. */
function ordenFila(array $wcfg, array $ids): int {
    $ordenes = array_map(fn($id) => (int)($wcfg[$id]['orden'] ?? 0), $ids);
    return $ordenes ? min($ordenes) : 0;
}

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Panorama general de inspecciones estructurales post-sismo';
$activeModule = 'dashboard';

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

<div class="dashboard-tv-header">
    <div class="flex items-center gap-12">
        <button type="button" class="btn-menu-tv" id="btn-menu-tv" aria-label="Abrir menú" title="Abrir menú">
            <i class="bi bi-list"></i>
        </button>
        <i class="bi bi-building-fill" style="font-size:22px;"></i>
        <span class="dashboard-tv-title"><?= e(mb_strtoupper($pageTitle === 'Dashboard' ? 'Inspección de Edificaciones Post-Sismo' : $pageTitle)) ?></span>
    </div>
    <div class="flex items-center gap-12" style="flex-wrap:wrap;">
        <span class="text-sm" style="color:#9fb0d6;"><i class="bi bi-arrow-repeat"></i> Actualizado <span id="hora-actualizacion">—</span></span>
        <?php
            // Tiene "vista nacional" quien es master O quien (siendo no-master)
            // no tiene un estado asignado. Sólo un estadal con estado fijo NO
            // ve el selector de estado.
            require_once __DIR__ . '/../includes/territorial.php';
            $esMasterDash = usuarioEsMaster() || estadoDelUsuario() === null;
        ?>
        <!-- Migas de navegación territorial (nacional → estado → municipio) -->
        <span id="breadcrumb-territorio" class="breadcrumb-territorio" style="display:none;"></span>
        <?php if ($esMasterDash): ?>
        <!-- Selector de estado: solo para usuarios master (acceso nacional) -->
        <select id="filtro-estado" class="form-control" style="width:auto;min-width:170px;">
            <option value="">🇻🇪 Todo el país</option>
            <?php
                require_once __DIR__ . '/../includes/territorial.php';
                foreach (catalogoEstados() as $__e) {
                    echo '<option value="' . e($__e) . '">' . e($__e) . '</option>';
                }
            ?>
        </select>
        <?php endif; ?>
        <select id="filtro-parroquia" class="form-control" style="width:auto;min-width:190px;">
            <option value="">Seleccione una unidad</option>
        </select>
        <select id="filtro-uso" class="form-control" style="width:auto;min-width:170px;">
            <option value="">Todos los usos</option>
            <option value="__SIN_USO__">— Sin uso (vacío) —</option>
            <?php foreach (catalogoUsoEdificacion() as $__u): ?>
            <option value="<?= e($__u) ?>"><?= e($__u) ?></option>
            <?php endforeach; ?>
        </select>
        <button id="btn-quitar-filtro" class="btn btn-outline btn-sm" style="display:none;">
            <i class="bi bi-x-lg"></i> Quitar filtro
        </button>
        <?php if (puede('import_export', 'ver') || puede('dashboard', 'ver')): ?>
        <button id="btn-descargar-lista" type="button" class="btn btn-outline btn-sm" title="Descarga en Excel la lista con los filtros que tengas activos ahora">
            <i class="bi bi-file-earmark-excel"></i> Descargar Excel
        </button>
        <button id="btn-descargar-lista-pdf" type="button" class="btn btn-outline btn-sm" title="Descarga en PDF la lista con los filtros que tengas activos ahora">
            <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
        </button>
        <?php endif; ?>
        <?php if (puede('formulario', 'crear')): ?>
        <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-accent btn-sm">
            <i class="bi bi-plus-lg"></i> Nueva inspección
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-tv-body">

<div class="split-grid cols-10-14 align-start dashboard-chart-map" style="margin-bottom:16px;">
    <div class="dashboard-left-col">
        <div class="flex gap-12" style="align-items:stretch;flex-wrap:wrap;order:<?= ordenFila($wcfg, ['kpi_inspecciones','kpi_personas']) ?>;">
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
                <div class="icon"><i class="bi bi-people-fill"></i></div>
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
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-standing-dress"></i></div><div><div class="num" id="kpi-mujeres">—</div><div class="lbl">Mujeres</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-emoji-smile-fill"></i></div><div><div class="num" id="kpi-ninos">—</div><div class="lbl">Niños</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-wheelchair"></i></div><div><div class="num" id="kpi-mreducida">—</div><div class="lbl">M. Reducida</div></div></div>
            <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-walking"></i></div><div><div class="num" id="kpi-terceraedad">—</div><div class="lbl">3ra. Edad</div></div></div>
            <div class="tv-kpi-card"><div class="icon">
                <svg viewBox="0 0 16 16" fill="currentColor" width="1em" height="1em" aria-hidden="true">
                    <circle cx="8" cy="4" r="2.3"/>
                    <path d="M3 8.6C3 6.6 5.2 6 8 6s5 .6 5 2.6S10.8 15 8 15 3 10.6 3 8.6Z"/>
                </svg>
            </div><div><div class="num" id="kpi-gestantes">—</div><div class="lbl">Gestantes</div></div></div>
            <div class="tv-kpi-card"><div class="icon">
                <svg viewBox="0 0 16 16" fill="currentColor" width="1em" height="1em" aria-hidden="true">
                    <ellipse cx="8" cy="11.2" rx="4" ry="3.2"/>
                    <ellipse cx="3.1" cy="6.6" rx="1.6" ry="2.1" transform="rotate(-20 3.1 6.6)"/>
                    <ellipse cx="6.3" cy="3.5" rx="1.5" ry="2" transform="rotate(-8 6.3 3.5)"/>
                    <ellipse cx="9.7" cy="3.5" rx="1.5" ry="2" transform="rotate(8 9.7 3.5)"/>
                    <ellipse cx="12.9" cy="6.6" rx="1.6" ry="2.1" transform="rotate(20 12.9 6.6)"/>
                </svg>
            </div><div><div class="num" id="kpi-mascotas">—</div><div class="lbl">Mascotas</div></div></div>
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
        <div class="split-grid cols-11" style="order:<?= ordenFila($wcfg, ['chart_decision','chart_parroquia']) ?>;">
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
</div>

<!-- Modal ficha técnica -->
<div id="modal-ficha" class="modal-overlay" style="display:none;"></div>

<!-- Visor de fotos en grande (sobre la ficha técnica) -->
<div id="lightbox-foto" class="lightbox-overlay" style="display:none;"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>window.APP_URL_BASE = '<?= APP_URL_BASE ?>';</script>
<script src="<?= APP_URL_BASE ?>dashboard/nacional.js?v=<?= ASSET_VERSION ?>"></script>
<script>
const API_URL   = '<?= APP_URL_BASE ?>dashboard/api_kpis.php';
const FICHA_URL = '<?= APP_URL_BASE ?>dashboard/api_ficha.php';
const EXPORTAR_URL = '<?= APP_URL_BASE ?>dashboard/exportar_lista.php';
const EXPORTAR_PDF_URL = '<?= APP_URL_BASE ?>dashboard/exportar_lista_pdf.php';

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
let usoSeleccionado = '';                 // '' = sin filtro (todos los usos)
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

// Carga los límites geográficos del NIVEL actual (país → estado → municipio)
// delegando en el controlador nacional. Ya no es un archivo fijo de Caracas.
async function obtenerLimitesParroquias() {
    return await window.DashboardNacional.limitesActuales();
}

// La "unidad base" del nivel actual la dicta la API (estado/municipio/parroquia).
function unidadBaseActual() {
    return (ultimoDatosDashboard && ultimoDatosDashboard.unidad_base) || 'parroquia';
}
function nombreParroquiaDeFeature(feature) {
    return window.DashboardNacional.nombreUnidad(feature, unidadBaseActual());
}

function initMap() {
    if (!document.getElementById('map-inspecciones')) return; // widget "mapa" oculto por configuración
    // Vista inicial: país completo para master, o Caracas si el usuario es estadal DC.
    const vistaPais = <?= $esMasterDash ? 'true' : 'false' ?>;
    const centroInicial = vistaPais ? [8.0, -66.0] : [10.4880, -66.9200];
    const zoomInicial = vistaPais ? 6 : 12;
    map = L.map('map-inspecciones', { zoomControl: true }).setView(centroInicial, zoomInicial);
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
    // Se re-puebla en cada nivel (la lista de unidades cambia: estados →
    // municipios → parroquias). Preserva la selección actual si sigue existiendo.
    const prev = select.value;
    const nombres = [...(lista || [])].map(p => p.parroquia).sort((a, b) => (a || '').localeCompare(b || '', 'es'));
    // Etiqueta del placeholder según nivel
    const nivel = (ultimoDatosDashboard && ultimoDatosDashboard.nivel) || 'parroquia';
    const etiqueta = nivel === 'nacional' ? 'Seleccione un estado'
                   : nivel === 'municipio' ? 'Seleccione un municipio'
                   : 'Seleccione una parroquia';
    select.innerHTML = '<option value="">' + etiqueta + '</option>';
    for (const nombre of nombres) {
        const opt = document.createElement('option');
        opt.value = nombre;
        opt.textContent = nombre;
        select.appendChild(opt);
    }
    if (nombres.includes(prev)) select.value = prev;
    select.dataset.poblado = '1';
}

// Texto combinado de los filtros activos (parroquia y/o decisión), para
// usar en títulos y contadores del mapa/listas.
function descripcionFiltroActivo() {
    const partes = [];
    if (parroquiaSeleccionada) partes.push(parroquiaSeleccionada);
    if (usoSeleccionado) partes.push(usoSeleccionado === '__SIN_USO__' ? 'Sin uso' : usoSeleccionado);
    if (decisionSeleccionada) partes.push(decisionSeleccionada);
    return partes.join(' · ');
}

function actualizarIndicadoresFiltro() {
    const activo = !!parroquiaSeleccionada;
    document.getElementById('filtro-parroquia').value = parroquiaSeleccionada;
    const selUso = document.getElementById('filtro-uso');
    if (selUso) selUso.value = usoSeleccionado;
    document.getElementById('btn-quitar-filtro').style.display = (activo || usoSeleccionado || decisionSeleccionada) ? 'inline-flex' : 'none';
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
    const hayFiltro = !!(parroquiaSeleccionada || usoSeleccionado || decisionSeleccionada);
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
    close.addEventListener('click', () => {
        seleccionarParroquia('');
        seleccionarDecision('');
        usoSeleccionado = '';
        const selUso = document.getElementById('filtro-uso');
        if (selUso) selUso.value = '';
        cargarDashboard();
    });
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

// Clic sobre una sección del mapa. El comportamiento depende del NIVEL:
//   - Vista nacional: entra al estado.
//   - Dentro de un estado (no DC, nivel municipio): entra al municipio
//     (drill-down a sus parroquias).
//   - Nivel parroquia (DC o dentro de un municipio): filtra por esa parroquia
//     (toggle, como siempre).
function clicSeccion(nombre) {
    if (!nombre) return;
    const nivel = (ultimoDatosDashboard && ultimoDatosDashboard.nivel) || 'parroquia';
    const N = window.DashboardNacional;
    if (nivel === 'nacional') {
        N.entrarEstado(nombre);
        parroquiaSeleccionada = '';
        cargarDashboard();
    } else if (nivel === 'municipio') {
        N.entrarMunicipio(nombre);
        parroquiaSeleccionada = '';
        cargarDashboard();
    } else { // parroquia
        seleccionarParroquia(nombre === parroquiaSeleccionada ? '' : nombre);
    }
}

// Dibuja las migas de navegación territorial (país › estado › municipio).
function renderBreadcrumb(data) {
    const cont = document.getElementById('breadcrumb-territorio');
    if (!cont) return;
    const N = window.DashboardNacional;
    const partes = [];
    const esMaster = data.es_master;
    if (esMaster) {
        partes.push('<a href="#" data-nav="pais"><i class="bi bi-globe-americas"></i> País</a>');
    }
    if (data.estado_filtro) {
        // El nombre del estado: si es master, permite volver al nivel estado
        if (data.municipio_filtro) {
            partes.push('<a href="#" data-nav="estado">' + data.estado_filtro + '</a>');
            partes.push('<span>' + data.municipio_filtro + '</span>');
        } else {
            partes.push('<span>' + data.estado_filtro + '</span>');
        }
    }
    if (partes.length <= (esMaster ? 1 : 0)) { cont.style.display = 'none'; cont.innerHTML = ''; return; }
    cont.style.display = 'inline-flex';
    cont.innerHTML = partes.join('<i class="bi bi-chevron-right sep"></i>');
    cont.querySelectorAll('a[data-nav]').forEach(a => {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            const nav = this.dataset.nav;
            if (nav === 'pais') N.volverNacional();
            else if (nav === 'estado') N.volverEstado();
            parroquiaSeleccionada = '';
            const selEstado = document.getElementById('filtro-estado');
            if (selEstado && nav === 'pais') selEstado.value = '';
            cargarDashboard();
        });
    });
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
document.getElementById('filtro-uso').addEventListener('change', function () {
    usoSeleccionado = this.value;
    cargarDashboard();
});
// Selector de estado (solo master): entra al estado elegido o vuelve al país.
(function () {
    const selEstado = document.getElementById('filtro-estado');
    if (!selEstado) return;
    selEstado.addEventListener('change', function () {
        const N = window.DashboardNacional;
        if (this.value) N.entrarEstado(this.value); else N.volverNacional();
        parroquiaSeleccionada = '';
        const fp = document.getElementById('filtro-parroquia');
        if (fp) { fp.value = ''; fp.dataset.poblado = ''; }
        cargarDashboard();
    });
})();
document.getElementById('btn-quitar-filtro').addEventListener('click', function () {
    parroquiaSeleccionada = '';
    usoSeleccionado = '';
    const selUso = document.getElementById('filtro-uso');
    if (selUso) selUso.value = '';
    decisionSeleccionada = '';
    cargarDashboard();
});

// Descarga (Excel o PDF) exactamente lo que se está viendo en pantalla
// ahora mismo: usa los mismos filtros que cargarDashboard() para armar la URL.
function paramsDescargaActual() {
    const params = new URLSearchParams();
    window.DashboardNacional.paramsTerritorio(params); // estado / municipio
    if (parroquiaSeleccionada) params.set('parroquia', parroquiaSeleccionada);
    if (usoSeleccionado) params.set('uso', usoSeleccionado);
    if (decisionSeleccionada) params.set('decision', decisionSeleccionada);
    return params.toString();
}
document.getElementById('btn-descargar-lista')?.addEventListener('click', function () {
    const qs = paramsDescargaActual();
    window.location.href = EXPORTAR_URL + (qs ? '?' + qs : '');
});
document.getElementById('btn-descargar-lista-pdf')?.addEventListener('click', function () {
    const qs = paramsDescargaActual();
    window.location.href = EXPORTAR_PDF_URL + (qs ? '?' + qs : '');
});

async function cargarDashboard() {
    const params = new URLSearchParams();
    window.DashboardNacional.paramsTerritorio(params); // estado / municipio
    if (parroquiaSeleccionada) params.set('parroquia', parroquiaSeleccionada);
    if (usoSeleccionado) params.set('uso', usoSeleccionado);
    if (decisionSeleccionada) params.set('decision', decisionSeleccionada);
    const qs = params.toString();
    const url = API_URL + (qs ? '?' + qs : '');
    const res = await fetch(url);
    const data = await res.json();

    window.DashboardNacional.sincronizar(data);
    renderBreadcrumb(data);
    // La lista del <select> de unidades cambia según el nivel: re-poblar.
    poblarFiltroParroquia(data.por_parroquia);
    poblarFiltroDecisionParroquia(data.decision);
    actualizarIndicadoresFiltro();
    renderListaEdificios(data.inspecciones);
    ultimoDatosDashboard = data;

    document.getElementById('hora-actualizacion').textContent = data.actualizado;

    setTxt('kpi-inspecciones', formatNum(data.totales.inspecciones));
    const personasTotales = (Number(data.totales.hombres) || 0) + (Number(data.totales.mujeres) || 0) +
        (Number(data.totales.ninos) || 0) + (Number(data.totales.adultos_tercera_edad) || 0) +
        (Number(data.totales.gestantes) || 0);
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

    // ---- Mapa: secciones geográficas del NIVEL actual ----
    // Nivel nacional  -> una "burbuja de total" por estado.
    // Nivel estado    -> una por municipio (o parroquia en DC/La Guaira).
    // Nivel parroquia -> una por parroquia.
    // Cada sección muestra su TOTAL de inspecciones en un puntero. Al hacer
    // clic se entra (drill-down) o se filtra, según el nivel.
    if (map) {
    seccionesLayer.clearLayers();
    const nivelActual = data.nivel || 'parroquia';
    const esNivelNacional = nivelActual === 'nacional';
    const limites = await obtenerLimitesParroquias();

    // Lookup por nombre normalizado -> { total, color, lat, lng }
    const lookupParroquia = {};
    data.secciones_geo.forEach(s => { lookupParroquia[normalizarTexto(s.parroquia)] = s; });

    const esParroquiaSeleccionada = nombre => !!parroquiaSeleccionada &&
        normalizarTexto(nombre) === normalizarTexto(parroquiaSeleccionada);

    let boundsSeleccion = null; // se usa para hacer zoom si hay filtro activo

    // 1) Polígonos de límites (contexto visual). No llevan la etiqueta de
    //    total encima para no competir con las burbujas; solo dan la forma.
    const centroidesPoligono = {}; // nombre normalizado -> [lat,lng] (centro del polígono)
    if (limites) {
        L.geoJSON(limites, {
            style: function (feature) {
                const nombre = nombreParroquiaDeFeature(feature);
                const seleccionada = esParroquiaSeleccionada(nombre);
                return {
                    color: seleccionada ? '#1f4bd8' : '#9ca3af',
                    weight: seleccionada ? 4 : 1.5,
                    fillColor: seleccionada ? '#3b6bf5' : '#cbd5e1',
                    fillOpacity: seleccionada ? 0.28 : 0.10,
                };
            },
            onEachFeature: function (feature, layer) {
                const nombre = nombreParroquiaDeFeature(feature) || '';
                try { centroidesPoligono[normalizarTexto(nombre)] = layer.getBounds().getCenter(); } catch (e) {}
                layer.on('click', () => clicSeccion(nombre));
                layer.on('mouseover', () => layer.setStyle({ weight: 3, fillOpacity: 0.22 }));
                layer.on('mouseout', () => { if (!esParroquiaSeleccionada(nombre)) layer.setStyle({ weight: 1.5, fillOpacity: 0.10 }); });
                if (esParroquiaSeleccionada(nombre)) boundsSeleccion = layer.getBounds();
            }
        }).addTo(seccionesLayer);
    }

    // 2) Burbujas de TOTAL por sección (el "puntero indicador de total").
    //    Se ubican en el centroide del polígono si existe, si no en el
    //    promedio de coordenadas de las inspecciones de esa sección.
    const maxTotal = Math.max(1, ...data.secciones_geo.map(s => s.total));
    data.secciones_geo.forEach(s => {
        const clave = normalizarTexto(s.parroquia);
        let pos = centroidesPoligono[clave];
        if (!pos && s.lat != null && s.lng != null) pos = [s.lat, s.lng];
        if (!pos) return; // sin ubicación conocida
        const seleccionada = esParroquiaSeleccionada(s.parroquia);
        // Tamaño de la burbuja proporcional al total (escala suave).
        const size = 30 + Math.round((s.total / maxTotal) * 26);
        const color = s.color || '#2d4488';
        const burbuja = L.marker(pos, {
            icon: L.divIcon({
                className: 'seccion-total-marker' + (seleccionada ? ' activa' : ''),
                html: '<div class="seccion-burbuja" style="width:' + size + 'px;height:' + size + 'px;'
                    + 'background:' + color + ';border-color:' + (seleccionada ? '#1f4bd8' : '#fff') + ';">'
                    + '<span>' + formatNum(s.total) + '</span></div>'
                    + '<div class="seccion-nombre">' + s.parroquia + '</div>',
                iconSize: [size, size],
                iconAnchor: [size / 2, size / 2],
            }),
        });
        burbuja.on('click', () => clicSeccion(s.parroquia));
        burbuja.addTo(seccionesLayer);
    });

    document.getElementById('nota-limites').textContent = esNivelNacional
        ? 'Vista nacional: total de inspecciones por estado. Haga clic en un estado para ver su detalle.'
        : (limites ? ('Detalle por ' + (data.unidad_base || 'unidad') + '. Haga clic para filtrar.') : 'Secciones aproximadas.');

    // Zoom/encuadre por nivel.
    const claveEncuadre = (data.estado_filtro || 'PAIS') + '|' + (data.municipio_filtro || '') + '|' + parroquiaSeleccionada;
    if (ultimaParroquiaEnMapa !== claveEncuadre) {
        if (parroquiaSeleccionada && boundsSeleccion) {
            map.flyToBounds(boundsSeleccion, { padding: [40, 40], maxZoom: 16, duration: 0.7 });
        } else if (limites && limites.features && limites.features.length) {
            try {
                const capa = L.geoJSON(limites);
                map.flyToBounds(capa.getBounds(), { padding: [30, 30], maxZoom: data.estado_filtro ? 13 : 7, duration: 0.7 });
            } catch (e) { /* geometría inválida */ }
        } else if (!data.estado_filtro) {
            map.flyTo([8.0, -66.0], 6, { duration: 0.7 });
        }
        ultimaParroquiaEnMapa = claveEncuadre;
    }

    // ---- Marcadores individuales de cada inspección (clusters).
    //    En la vista NACIONAL no se dibujan (serían miles de puntos sin
    //    contexto); aparecen al entrar a un estado. ----
    clusterLayer.clearLayers();
    if (!esNivelNacional) {
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
    }
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
                <a href="<?= APP_URL_BASE ?>dashboard/export_pdf.php?id=${id}" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-printer-fill"></i> Imprimir / Descargar PDF</a>
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

// ---- El dashboard vive siempre a pantalla completa (sin sidebar/topbar
// normales). El botón hamburguesa del encabezado oscuro reabre el menú
// lateral como panel deslizante encima, igual que en móvil. ----
document.getElementById('btn-menu-tv')?.addEventListener('click', function () {
    document.getElementById('sidebar')?.classList.add('open');
    document.getElementById('sidebar-backdrop')?.classList.add('show');
});
document.getElementById('sidebar-backdrop')?.addEventListener('click', function () {
    document.getElementById('sidebar')?.classList.remove('open');
    this.classList.remove('show');
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
