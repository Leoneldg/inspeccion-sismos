<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('dashboard', 'ver');

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Panorama general de inspecciones estructurales post-sismo';
$activeModule = 'dashboard';

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />

<div class="flex items-center justify-between gap-12" style="flex-wrap:wrap;margin-bottom:18px;">
    <div class="flex items-center gap-12">
        <span class="badge badge-gris"><i class="bi bi-arrow-repeat"></i> Actualizado <span id="hora-actualizacion">—</span></span>
        <span class="text-sm text-muted">Actualización automática cada 60s</span>
    </div>
    <?php if (puede('formulario', 'crear')): ?>
    <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-accent btn-sm">
        <i class="bi bi-plus-lg"></i> Nueva inspección
    </a>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns: 1.4fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="kpi-hero tv-hero">
        <div class="icon"><i class="bi bi-clipboard2-data-fill"></i></div>
        <div>
            <div class="num" id="kpi-inspecciones">—</div>
            <div class="lbl">Inspecciones realizadas</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="padding:16px 22px;">
            <div class="flex justify-between items-center" style="margin-bottom:8px;">
                <span class="text-sm text-muted" style="font-size:13px;">Decisión final (semaforización)</span>
            </div>
            <div id="leyenda-decision" class="flex gap-12" style="flex-wrap:wrap;font-size:15px;"></div>
        </div>
    </div>
</div>

<div class="tv-kpi-grid" style="margin-bottom:20px;">
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-people-fill"></i></div><div><div class="num" id="kpi-familias">—</div><div class="lbl">Familias</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-fill"></i></div><div><div class="num" id="kpi-hombres">—</div><div class="lbl">Hombres</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-dress"></i></div><div><div class="num" id="kpi-mujeres">—</div><div class="lbl">Mujeres</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-emoji-smile-fill"></i></div><div><div class="num" id="kpi-ninos">—</div><div class="lbl">Niños</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-wheelchair"></i></div><div><div class="num" id="kpi-mreducida">—</div><div class="lbl">M. Reducida</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-person-walking"></i></div><div><div class="num" id="kpi-terceraedad">—</div><div class="lbl">3ra. Edad</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-heart-pulse-fill"></i></div><div><div class="num" id="kpi-gestantes">—</div><div class="lbl">Gestantes</div></div></div>
    <div class="tv-kpi-card"><div class="icon"><i class="bi bi-heart-fill"></i></div><div><div class="num" id="kpi-mascotas">—</div><div class="lbl">Mascotas</div></div></div>
</div>

<div style="display:grid;grid-template-columns: 1fr 1.4fr;gap:16px;align-items:start;">
    <div class="card">
        <div class="card-header">
            <h2 class="tv-section-title"><i class="bi bi-bar-chart-fill"></i> Estado de acceso a la edificación</h2>
        </div>
        <div class="card-body">
            <div style="height:380px;">
                <canvas id="chart-decision"></canvas>
            </div>
        </div>
    </div>

    <div class="card card-mapa">
        <div class="card-header">
            <h2 class="tv-section-title"><i class="bi bi-map-fill"></i> Mapa geográfico por parroquia</h2>
            <span class="text-sm text-muted" id="contador-mapa"></span>
        </div>
        <div class="mapa-wrap">
            <div id="map-inspecciones"></div>
            <div class="mapa-leyenda">
                <div class="item"><span class="dot" style="background:#22c55e;"></span> Acceso Permitido</div>
                <div class="item"><span class="dot" style="background:#eab308;"></span> Precaución al Entrar</div>
                <div class="item"><span class="dot" style="background:#ef4444;"></span> Acceso No Permitido</div>
                <div class="item text-muted" id="nota-limites" style="font-size:10.5px;max-width:220px;"></div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-header">
        <h2 class="tv-section-title"><i class="bi bi-geo-alt-fill"></i> Inspecciones por parroquia</h2>
    </div>
    <div class="card-body">
        <div style="height:480px;">
            <canvas id="chart-parroquia"></canvas>
        </div>
    </div>
</div>

<button class="btn btn-primary btn-presentacion" id="btn-modo-tv" title="Modo presentación para TV">
    <i class="bi bi-fullscreen"></i> Modo presentación
</button>

<!-- Modal ficha técnica -->
<div id="modal-ficha" class="modal-overlay" style="display:none;"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const API_URL   = '<?= APP_URL_BASE ?>dashboard/api_kpis.php';
const FICHA_URL = '<?= APP_URL_BASE ?>dashboard/api_ficha.php';

let map, clusterLayer, seccionesLayer, chartDecision, chartParroquia;
let geojsonLimitesParroquias = undefined; // undefined = aún no se intentó cargar; null = no existe; objeto = cargado

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

async function cargarDashboard() {
    const res = await fetch(API_URL);
    const data = await res.json();

    document.getElementById('hora-actualizacion').textContent = data.actualizado;

    document.getElementById('kpi-inspecciones').textContent = formatNum(data.totales.inspecciones);
    document.getElementById('kpi-familias').textContent     = formatNum(data.totales.familias);
    document.getElementById('kpi-hombres').textContent      = formatNum(data.totales.hombres);
    document.getElementById('kpi-mujeres').textContent      = formatNum(data.totales.mujeres);
    document.getElementById('kpi-ninos').textContent        = formatNum(data.totales.ninos);
    document.getElementById('kpi-mreducida').textContent    = formatNum(data.totales.movilidad_reducida);
    document.getElementById('kpi-terceraedad').textContent  = formatNum(data.totales.adultos_tercera_edad);
    document.getElementById('kpi-gestantes').textContent    = formatNum(data.totales.gestantes);
    document.getElementById('kpi-mascotas').textContent     = formatNum(data.totales.mascotas);

    const leyenda = document.getElementById('leyenda-decision');
    leyenda.innerHTML = data.decision.map(d => `
        <span class="flex items-center gap-8">
            <span style="width:12px;height:12px;border-radius:3px;background:${d.color};display:inline-block;"></span>
            ${d.label}: <strong>${formatNum(d.total)}</strong>
        </span>
    `).join('');

    const ctxD = document.getElementById('chart-decision');
    const dataD = {
        labels: data.decision.map(d => d.label),
        datasets: [{ data: data.decision.map(d => d.total), backgroundColor: data.decision.map(d => d.color), borderRadius: 6, maxBarThickness: 70 }]
    };
    if (chartDecision) { chartDecision.data = dataD; chartDecision.update(); }
    else {
        chartDecision = new Chart(ctxD, {
            type: 'bar', data: dataD,
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0, font: { size: 13 } } }, x: { ticks: { font: { size: 13 } } } }
            }
        });
    }

    const ctxP = document.getElementById('chart-parroquia');
    const dataP = {
        labels: data.por_parroquia.map(p => p.parroquia),
        datasets: [{ data: data.por_parroquia.map(p => p.total), backgroundColor: '#2d4488', borderRadius: 5 }]
    };
    if (chartParroquia) { chartParroquia.data = dataP; chartParroquia.update(); }
    else {
        chartParroquia = new Chart(ctxP, {
            type: 'bar', data: dataP,
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0, font: { size: 12.5 } } },
                    y: { ticks: { font: { size: 12.5 } } }
                }
            }
        });
    }

    // ---- Mapa: secciones por parroquia (límites reales si están disponibles, si no, círculos aproximados) ----
    seccionesLayer.clearLayers();
    const limites = await obtenerLimitesParroquias();

    // Lookup por nombre normalizado -> { total, color }
    const lookupParroquia = {};
    data.secciones_geo.forEach(s => { lookupParroquia[normalizarTexto(s.parroquia)] = s; });

    if (limites) {
        L.geoJSON(limites, {
            style: function (feature) {
                const nombre = nombreParroquiaDeFeature(feature);
                const info = nombre ? lookupParroquia[normalizarTexto(nombre)] : null;
                return {
                    color: info ? info.color : '#767c94',
                    weight: 2,
                    fillColor: info ? info.color : '#767c94',
                    fillOpacity: info ? 0.22 : 0.06,
                };
            },
            onEachFeature: function (feature, layer) {
                const nombre = nombreParroquiaDeFeature(feature) || 'Parroquia';
                const info = lookupParroquia[normalizarTexto(nombre)];
                layer.bindTooltip(nombre + (info ? ' · ' + info.total : ' · 0'), { sticky: true, className: 'parroquia-label' });
            }
        }).addTo(seccionesLayer);
    } else {
        const maxTotal = Math.max(1, ...data.secciones_geo.map(s => s.total));
        data.secciones_geo.forEach(s => {
            const radius = 220 + (s.total / maxTotal) * 900; // metros
            L.circle([s.lat, s.lng], {
                radius, color: s.color, weight: 1.5, fillColor: s.color, fillOpacity: 0.16,
            }).addTo(seccionesLayer);
            L.marker([s.lat, s.lng], {
                icon: L.divIcon({ className: '', html: `<div class="parroquia-label">${s.parroquia} · ${s.total}</div>`, iconSize: null }),
                interactive: false,
            }).addTo(seccionesLayer);
        });
    }
    document.getElementById('nota-limites').textContent = limites
        ? 'Límites reales de parroquia cargados desde assets/geo/parroquias_libertador.geojson'
        : 'Mostrando secciones aproximadas (círculos). Vea assets/geo/LEEME.md para usar límites reales.';

    // ---- Mapa: marcadores individuales (agrupados en clusters) ----
    clusterLayer.clearLayers();
    data.puntos.forEach(p => {
        const icon = L.divIcon({
            className: '',
            html: `<div style="width:16px;height:16px;border-radius:50%;background:${p.color};border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>`,
            iconSize: [16, 16],
        });
        const marker = L.marker([p.lat, p.lng], { icon });
        const fotoHtml = p.portada
            ? `<img src="${p.portada}" style="width:100%;height:90px;object-fit:cover;border-radius:6px;margin:6px 0;">`
            : '';
        marker.bindPopup(`
            <div style="min-width:200px;">
                <strong>${p.nombre}</strong><br>
                <span style="font-family:monospace;font-size:11px;color:#767c94;">${p.codigo}</span> · ${p.parroquia}<br>
                <span style="color:${p.color};font-weight:700;">${p.decision}</span>
                ${fotoHtml}
                <div style="font-size:11.5px;color:#767c94;margin-bottom:8px;">
                    ${p.fecha} ${p.fotos ? '· <i class="bi bi-camera-fill"></i> ' + p.fotos + ' foto(s)' : '· sin fotos'}
                </div>
                <button onclick="abrirFicha(${p.id})" style="width:100%;background:#172759;color:white;border:none;padding:8px;border-radius:6px;font-weight:600;cursor:pointer;font-size:12.5px;">
                    Ver ficha técnica
                </button>
            </div>
        `);
        clusterLayer.addLayer(marker);
    });
    document.getElementById('contador-mapa').textContent = data.puntos.length + ' inspecciones georreferenciadas de ' + data.totales.inspecciones + ' totales';
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
            <div class="foto-categoria-titulo">${g.categoria_label}</div>
            <div class="foto-galeria">
                ${g.fotos.map(ph => `<a href="${ph.url}" target="_blank"><img src="${ph.url}" loading="lazy"></a>`).join('')}
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

            <div class="section-title"><i class="bi bi-camera-fill"></i> Registro fotográfico</div>
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
    setTimeout(() => map.invalidateSize(), 250);
});

initMap();
cargarDashboard();
setInterval(cargarDashboard, 60000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
