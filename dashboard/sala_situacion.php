<?php
/**
 * SALA DE SITUACIÓN Y RECONSTRUCCIÓN (módulo unificado).
 *
 * Vista macro para el gobernador/directiva: KPIs, semáforo, materiales
 * consolidados y desglose por parroquia — todo de un vistazo.
 *
 * Se apoya SOLO en funciones y datos que ya existen:
 *   - dashResumenGeneral()      (totales, semáforo, personas)
 *   - dashPorParroquia()        (desglose territorial)
 *   - segKpis()                 (embudo de obra)
 *   - segConsolidadoMateriales() (materiales reales: friso, pintura…)
 * No toca la base de datos ni la lógica de negocio.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../seguimiento/dashboard_gubernamental.php';

requierePermiso('seguimiento', 'ver');

$resumen    = dashResumenGeneral();
$parroquias = dashPorParroquia();
$kpis       = function_exists('segKpis') ? segKpis() : [];
$con        = function_exists('segConsolidadoMateriales') ? segConsolidadoMateriales() : [];
$conteoParr = function_exists('segConteoPorParroquia') ? segConteoPorParroquia() : [];

$activeModule = 'sala_situacion';
$pageTitle = 'Sala de situación';

$totalInmuebles = (int)($resumen['total_edificaciones'] ?? 0);
$v = (int)($resumen['verde'] ?? 0);
$a = (int)($resumen['amarillo'] ?? 0);
$r = (int)($resumen['rojo'] ?? 0);
$totSem = max(1, $v + $a + $r);

// Conteo de fases con la definición acordada (separa los dos momentos de Fase 2).
$cf = function_exists('segConteoFases') ? segConteoFases() : [];
$faseLevant   = (int)($cf['fase2_levantamiento'] ?? 0);   // Fase 2 · con levantamiento
$faseReconstr = (int)($cf['fase2_reconstruccion'] ?? 0);  // Fase 2 · en reconstrucción
$culminadas   = (int)($cf['fase3'] ?? 0);                 // Fase 3 · recuperadas
$enEjecucion  = $faseLevant + $faseReconstr;              // Fase 2 total (intervención)
$faseInspecc  = (int)($cf['fase1'] ?? 0);                 // Fase 1 · inspeccionadas
$sinEmpezar   = $faseInspecc;

// Materiales reales del consolidado (con lo que exista).
$friso   = (float)($con['friso'] ?? 0);
$pintura = (float)($con['pintura'] ?? 0);
$mats    = $con['materiales'] ?? [];   // [ ['material','cantidad','unidad'], ... ]

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
  .ss-wrap { max-width: 1160px; margin: 0 auto; padding: 4px 6px 40px; }
  .ss-top { display: flex; align-items: center; gap: 12px; margin: 6px 2px 16px; }
  .ss-top .ic { width: 40px; height: 40px; border-radius: 11px; background: #22366F; color: #fff; display: flex; align-items: center; justify-content: center; }
  .ss-top h1 { font-size: 21px; font-weight: 800; color: #22366F; margin: 0; }
  .ss-top .sub { font-size: 12.5px; color: #5b6478; }
  .ss-top .pdf { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; background: #22366F; color: #fff; text-decoration: none; border-radius: 10px; padding: 10px 15px; font-size: 13px; }

  .ss-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 11px; margin-bottom: 13px; }
  .ss-kpi { border-radius: 13px; padding: 15px 16px; color: #fff; position: relative; }
  .ss-kpi .n { font-size: 27px; font-weight: 800; line-height: 1; }
  .ss-kpi .l { font-size: 11px; opacity: .92; margin-top: 4px; }

  .ss-fila { display: flex; gap: 13px; margin-bottom: 13px; align-items: flex-start; }
  .ss-col { flex: 1; min-width: 0; }
  .ss-panel { background: #fff; border: 1px solid #e6e9f0; border-radius: 13px; padding: 15px; }
  .ss-h { font-size: 12.5px; font-weight: 700; color: #22366F; margin: 0 0 12px; display: flex; align-items: center; gap: 7px; }
  .ss-h .tag { margin-left: auto; font-size: 10.5px; font-weight: 600; color: #9aa1b4; }

  .ss-sem { display: flex; flex-direction: column; gap: 6px; }
  .ss-s { border-radius: 9px; padding: 8px 12px; display: flex; align-items: center; gap: 10px; }
  .ss-s .num { font-size: 19px; font-weight: 800; line-height: 1; }
  .ss-s .tx { flex: 1; }
  .ss-s .lb { font-size: 11.5px; font-weight: 700; }
  .ss-s .pr { font-size: 10px; opacity: .8; }

  .ss-fases { display: flex; gap: 8px; }
  .ss-fase { flex: 1; border-radius: 10px; padding: 13px 8px; text-align: center; }
  .ss-fase .n { font-size: 22px; font-weight: 800; }
  .ss-fase .l { font-size: 10.5px; margin-top: 3px; font-weight: 600; }

  .ss-mat-cab { background: #C9A227; color: #22366F; padding: 10px 14px; border-radius: 10px 10px 0 0; font-size: 13px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; }
  .ss-mat-caja { border: 2px solid #C9A227; border-top: 0; border-radius: 0 0 10px 10px; background: #fffdf5; padding: 12px; }
  .ss-mat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 9px; }
  .ss-mat { background: #fff; border: 1px solid #EAE2C4; border-radius: 11px; padding: 12px; }
  .ss-mat .mn { font-size: 20px; font-weight: 800; color: #22366F; line-height: 1; }
  .ss-mat .ml { font-size: 10.5px; color: #5b6478; margin-top: 4px; }

  .ss-parr { width: 100%; border-collapse: collapse; }
  .ss-parr th { text-align: left; font-size: 10.5px; color: #9aa1b4; font-weight: 700; padding: 7px 8px; text-transform: uppercase; letter-spacing: .3px; }
  .ss-parr td { font-size: 13px; padding: 8px; border-top: 1px solid #f2f4f8; }
  .ss-dot { width: 22px; height: 22px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; }
  .ss-mini { display: inline-flex; gap: 3px; }
  .ss-fbtn { font-size: 11px; font-weight: 600; padding: 6px 11px; border-radius: 8px; border: 1px solid #e6e9f0; background: #fff; color: #5b6478; cursor: pointer; }
  .ss-fbtn.on { background: #22366F; color: #fff; border-color: #22366F; }

  @media (max-width: 860px) {
    .ss-kpis { grid-template-columns: repeat(2, 1fr); }
    .ss-fila { flex-direction: column; }
    .ss-top .pdf { margin-left: 0; }
  }
</style>

<div class="ss-wrap">

  <div class="ss-top">
    <div class="ic"><i class="bi bi-grid-1x2-fill"></i></div>
    <div>
      <h1>Sala de situación y reconstrucción</h1>
      <div class="sub">Estado general de la gestión · <?= date('d/m/Y') ?></div>
    </div>
    <a href="<?= APP_URL_BASE ?>seguimiento/pdf_ejecutivo.php" target="_blank" class="pdf">
      <i class="bi bi-file-earmark-pdf"></i> Ficha de prensa PDF
    </a>
  </div>

  <!-- KPIs -->
  <div class="ss-kpis">
    <div class="ss-kpi" style="background:#22366F;">
      <div class="n"><?= number_format($totalInmuebles, 0, ',', '.') ?></div>
      <div class="l">Edificaciones bajo control</div>
    </div>
    <div class="ss-kpi" style="background:#7A3E8E;">
      <div class="n"><?= number_format((int)($resumen['total_personas'] ?? 0), 0, ',', '.') ?></div>
      <div class="l">Personas impactadas</div>
    </div>
    <div class="ss-kpi" style="background:#2D4488;">
      <div class="n"><?= number_format($enEjecucion, 0, ',', '.') ?></div>
      <div class="l">En intervención</div>
    </div>
    <div class="ss-kpi" style="background:#1D6E56;cursor:pointer;" onclick="kpiAbrir('recuperadas')">
      <div class="n"><?= number_format($culminadas, 0, ',', '.') ?></div>
      <div class="l">Recuperadas <i class="bi bi-list-ul" style="margin-left:2px;"></i></div>
    </div>
  </div>

  <!-- MAPA POR FASE -->
  <div class="ss-panel" style="margin-bottom:13px;">
    <div class="ss-h">
      <i class="bi bi-geo-alt-fill"></i> Mapa por fase
      <div style="margin-left:auto; display:flex; gap:6px;">
        <button class="ss-fbtn on" data-fase="todas" onclick="ssFase(this,'todas')">Todas</button>
        <button class="ss-fbtn" data-fase="1" onclick="ssFase(this,'1')">Fase 1 · Inspección</button>
        <button class="ss-fbtn" data-fase="2" onclick="ssFase(this,'2')">Fase 2 · Intervención</button>
        <button class="ss-fbtn" data-fase="3" onclick="ssFase(this,'3')">Fase 3 · Recuperadas</button>
      </div>
    </div>
    <div style="display:flex; gap:12px; align-items:stretch;">
      <div id="ss-map" style="flex:1; height:420px; border-radius:11px; overflow:hidden; border:1px solid #e6e9f0;"></div>
      <div id="ss-detalle" style="width:260px; flex-shrink:0; border:1px solid #e6e9f0; border-radius:11px; padding:14px; overflow-y:auto; height:420px; background:#fafbfd;">
        <div id="ss-detalle-vacio" style="color:#9aa1b4; font-size:12.5px; text-align:center; padding-top:60px;">
          <i class="bi bi-hand-index" style="font-size:22px; display:block; margin-bottom:8px;"></i>
          Toca una parroquia en el mapa para ver su detalle.
        </div>
        <div id="ss-detalle-cont" style="display:none;"></div>
      </div>
    </div>
    <div id="ss-map-leyenda" style="font-size:11px; color:#5b6478; margin-top:8px;">
      Cada burbuja muestra el conteo de edificaciones de la parroquia según la fase seleccionada.
    </div>
    <div id="ss-map-leyenda-f2" style="display:none; font-size:11px; color:#5b6478; margin-top:8px; align-items:center; gap:14px; flex-wrap:wrap;">
      <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#22366F;display:inline-block;"></span> Con levantamiento técnico</span>
      <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#C9A227;display:inline-block;"></span> En reconstrucción (con avance)</span>
    </div>
  </div>

  <div class="ss-fila">
    <!-- Semáforo -->
    <div class="ss-col">
      <div class="ss-panel">
        <div class="ss-h"><i class="bi bi-stoplights"></i> Semáforo de habitabilidad</div>
        <div class="ss-sem">
          <div class="ss-s" style="background:#E7F4EC;">
            <div class="num" style="color:#2E7D32;"><?= number_format($v,0,',','.') ?></div>
            <div class="tx"><div class="lb" style="color:#2E7D32;">Habitables (verde)</div><div class="pr" style="color:#2E7D32;"><?= round($v*100/$totSem) ?>% · <?= number_format((int)($resumen['personas_verde']??0),0,',','.') ?> personas</div></div>
          </div>
          <div class="ss-s" style="background:#FDF7E7;">
            <div class="num" style="color:#A66A00;"><?= number_format($a,0,',','.') ?></div>
            <div class="tx"><div class="lb" style="color:#A66A00;">Precaución (amarillo)</div><div class="pr" style="color:#A66A00;"><?= round($a*100/$totSem) ?>% · <?= number_format((int)($resumen['personas_amarillo']??0),0,',','.') ?> personas</div></div>
          </div>
          <div class="ss-s" style="background:#FCEBEB;">
            <div class="num" style="color:#A61C1C;"><?= number_format($r,0,',','.') ?></div>
            <div class="tx"><div class="lb" style="color:#A61C1C;">Inseguras (rojo)</div><div class="pr" style="color:#A61C1C;"><?= round($r*100/$totSem) ?>% · <?= number_format((int)($resumen['personas_rojo']??0),0,',','.') ?> personas</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Estatus de fases -->
    <div class="ss-col">
      <div class="ss-panel" style="margin-bottom:13px;">
        <div class="ss-h"><i class="bi bi-bar-chart-steps"></i> Estatus de las 3 fases</div>
        <div class="ss-fases">
          <div class="ss-fase" style="background:#FDF7E7;"><div class="n" style="color:#A66A00;"><?= number_format($faseInspecc,0,',','.') ?></div><div class="l" style="color:#A66A00;">Fase 1 · Inspeccionadas</div></div>
          <div class="ss-fase" style="background:#E9EEF9;"><div class="n" style="color:#22366F;"><?= number_format($enEjecucion,0,',','.') ?></div><div class="l" style="color:#22366F;">Fase 2 · En intervención</div></div>
          <div class="ss-fase" style="background:#E7F4EC;"><div class="n" style="color:#2E7D32;"><?= number_format($culminadas,0,',','.') ?></div><div class="l" style="color:#2E7D32;">Fase 3 · Recuperadas</div></div>
        </div>
        <!-- Fase 2 desglosada en sus dos momentos -->
        <div style="display:flex;gap:8px;margin-top:9px;">
          <div onclick="kpiAbrir('levantamiento')" style="flex:1;border:1px solid #e6e9f0;border-radius:9px;padding:9px 11px;cursor:pointer;">
            <div style="font-size:18px;font-weight:800;color:#22366F;"><?= number_format($faseLevant,0,',','.') ?></div>
            <div style="font-size:10.5px;color:#5b6478;"><i class="bi bi-rulers"></i> Con levantamiento</div>
            <div style="font-size:10px;color:#22366F;font-weight:600;margin-top:3px;"><i class="bi bi-list-ul"></i> Ver lista</div>
          </div>
          <div onclick="kpiAbrir('reconstruccion')" style="flex:1;border:1px solid #C9A22733;border-radius:9px;padding:9px 11px;background:#FFFDF5;cursor:pointer;">
            <div style="font-size:18px;font-weight:800;color:#A66A00;"><?= number_format($faseReconstr,0,',','.') ?></div>
            <div style="font-size:10.5px;color:#5b6478;"><i class="bi bi-hammer"></i> En reconstrucción</div>
            <div style="font-size:10px;color:#A66A00;font-weight:600;margin-top:3px;"><i class="bi bi-list-ul"></i> Ver lista</div>
          </div>
        </div>
      </div>
      <!-- Materiales reales -->
      <div class="ss-mat-cab"><i class="bi bi-bricks"></i> Material requerido</div>
      <div class="ss-mat-caja">
        <div class="ss-mat-grid">
          <?php if ($friso > 0): ?>
          <div class="ss-mat"><div class="mn"><?= number_format($friso,0,',','.') ?></div><div class="ml">m² de friso</div></div>
          <?php endif; ?>
          <?php if ($pintura > 0): ?>
          <div class="ss-mat"><div class="mn"><?= number_format($pintura,0,',','.') ?></div><div class="ml">galones de pintura</div></div>
          <?php endif; ?>
          <?php foreach (array_slice($mats, 0, 4) as $m): ?>
          <div class="ss-mat"><div class="mn"><?= number_format((float)($m['cantidad']??0),0,',','.') ?></div><div class="ml"><?= e($m['material'] ?? '') ?> <?= e($m['unidad'] ?? '') ?></div></div>
          <?php endforeach; ?>
          <?php if ($friso <= 0 && $pintura <= 0 && !$mats): ?>
          <div style="grid-column:1/3;color:#9aa1b4;font-size:12px;padding:8px;">Aún no hay materiales calculados (se llenan al cerrar levantamientos).</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Parroquias -->
  <div class="ss-panel">
    <div class="ss-h"><i class="bi bi-geo-alt"></i> Parroquias que requieren más atención <span class="tag">ordenadas por rojos</span></div>
    <table class="ss-parr">
      <thead><tr><th>Parroquia</th><th>Inmuebles</th><th>Semáforo</th><th>Personas</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($parroquias, 0, 12) as $p): ?>
        <tr>
          <td style="font-weight:600;"><?= e($p['parroquia']) ?></td>
          <td><?= number_format((int)$p['total'],0,',','.') ?></td>
          <td>
            <span class="ss-mini">
              <?php if ($p['verde']): ?><span class="ss-dot" style="background:#2E7D32;"><?= (int)$p['verde'] ?></span><?php endif; ?>
              <?php if ($p['amarillo']): ?><span class="ss-dot" style="background:#C9A227;"><?= (int)$p['amarillo'] ?></span><?php endif; ?>
              <?php if ($p['rojo']): ?><span class="ss-dot" style="background:#A61C1C;"><?= (int)$p['rojo'] ?></span><?php endif; ?>
            </span>
          </td>
          <td><?= number_format((int)$p['personas'],0,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Conteo real por parroquia (de segConteoPorParroquia).
const SS_CONTEO = <?= json_encode(array_values($conteoParr), JSON_UNESCAPED_UNICODE) ?>;
const SS_BASE = <?= json_encode(APP_URL_BASE) ?>;

// Normaliza nombres para casar el geojson con la base (sin acentos, minúsculas).
function ssNorm(s){
  return String(s||'').toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-z0-9]/g,' ').trim().replace(/\s+/g,' ');
}

// Índice: parroquia -> conteos. Y set de estados presentes (para cargar geojson).
const SS_IDX = {};
const SS_ESTADOS = new Set();
SS_CONTEO.forEach(c => {
  SS_IDX[ssNorm(c.parroquia)] = c;
  if (c.estado) SS_ESTADOS.add(c.estado);
});

// Qué número mostrar según la fase elegida.
function ssValor(c, fase){
  if (!c) return 0;
  if (fase === '1') return parseInt(c.fase1) || 0;                                   // inspeccionadas
  if (fase === '2') return (parseInt(c.fase2_levant)||0) + (parseInt(c.fase2_reconstr)||0); // en intervención
  if (fase === '3') return parseInt(c.fase3) || 0;                                   // recuperadas
  return parseInt(c.total) || 0;  // 'todas'
}
// Color de la burbuja por fase.
function ssColor(fase){
  if (fase === '1') return '#2E7D32';
  if (fase === '2') return '#22366F';
  if (fase === '3') return '#1D6E56';
  return '#2D4488';
}

let ssMap = null, ssCapa = null, ssBurbujas = null, ssFaseActual = 'todas';
const SS_ARCHIVO_ESTADO = {
  'Distrito Capital':'distrito_capital','Miranda':'miranda','La Guaira':'la_guaira',
  'Vargas':'la_guaira','Aragua':'aragua','Carabobo':'carabobo','Lara':'lara'
};

function ssInitMapa(){
  ssMap = L.map('ss-map', { zoomControl: true }).setView([10.5061,-66.9146], 11);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Esri' }).addTo(ssMap);
  ssCapa = L.featureGroup().addTo(ssMap);
  ssBurbujas = L.featureGroup().addTo(ssMap);

  // Cargar los geojson de los estados presentes en la data.
  const estados = SS_ESTADOS.size ? Array.from(SS_ESTADOS) : ['Distrito Capital'];
  estados.forEach(est => {
    const arch = SS_ARCHIVO_ESTADO[est] || ssNorm(est).replace(/ /g,'_');
    fetch(SS_BASE + 'assets/geo/parroquias/' + arch + '.geojson')
      .then(r => r.ok ? r.json() : null)
      .then(geo => { if (geo) ssPintarGeo(geo); })
      .catch(()=>{});
  });
}

function ssPintarGeo(geo){
  L.geoJSON(geo, {
    style: { color:'#C9A227', weight:1.2, fillColor:'#22366F', fillOpacity:0.06 },
    onEachFeature: (feature, layer) => {
      layer.on('mouseover', () => layer.setStyle({ fillOpacity:0.18, weight:2 }));
      layer.on('mouseout',  () => layer.setStyle({ fillOpacity:0.06, weight:1.2 }));
      layer.on('click', () => {
        // Acercar el mapa a la parroquia y abrir su detalle.
        if (layer.getBounds) ssMap.fitBounds(layer.getBounds(), { padding: [30, 30], maxZoom: 14 });
        ssDetalleParroquia(layer._parr);
      });
      ssCapa.addLayer(layer);
      layer._parr = feature.properties.parroquia || feature.properties.NOMBRE || '';
    }
  });
  // Guardar refs de capas para repintar burbujas al cambiar de fase.
  ssRedibujar();
}

function ssRedibujar(){
  ssBurbujas.clearLayers();
  ssCapa.eachLayer(layer => {
    const nombre = layer._parr;
    if (!nombre || !layer.getBounds) return;
    const c = SS_IDX[ssNorm(nombre)];
    if (!c) return;
    const centro = layer.getBounds().getCenter();

    // En Fase 2 mostramos DOS burbujas separadas: con levantamiento vs en
    // reconstrucción. En las demás fases, una sola con el total de la fase.
    if (ssFaseActual === '2') {
      const nLev = parseInt(c.fase2_levant) || 0;
      const nRec = parseInt(c.fase2_reconstr) || 0;
      // Se separan un poco en longitud para que no se encimen.
      if (nLev > 0) ssBurbuja(centro.lat, centro.lng - 0.006, nLev, '#22366F', 'Levantamiento');
      if (nRec > 0) ssBurbuja(centro.lat, centro.lng + 0.006, nRec, '#C9A227', 'Reconstrucción');
      // Etiqueta de la parroquia, centrada debajo.
      if (nLev > 0 || nRec > 0) ssEtiquetaParr(centro.lat, centro.lng, nombre);
    } else {
      const val = ssValor(c, ssFaseActual);
      if (val > 0) ssBurbuja(centro.lat, centro.lng, val, ssColor(ssFaseActual), nombre, true);
    }
  });
}

// Dibuja una burbuja con su número. Si mostrarNombre, añade la etiqueta debajo.
function ssBurbuja(lat, lng, val, color, titulo, mostrarNombre){
  const size = 30 + Math.min(28, Math.round(val/40));
  const nombreHtml = mostrarNombre
    ? `<div style="text-align:center;font-size:10px;color:#fff;text-shadow:0 1px 2px #000;font-weight:600;">${titulo}</div>`
    : '';
  const b = L.marker([lat, lng], { icon: L.divIcon({
    className: 'ss-burbuja',
    html: `<div title="${titulo}" style="width:${size}px;height:${size}px;background:${color};border:2px solid #fff;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;box-shadow:0 2px 6px rgba(0,0,0,.3);">${val}</div>${nombreHtml}`,
    iconSize: [size, size], iconAnchor: [size/2, size/2]
  })});
  ssBurbujas.addLayer(b);
}

// Etiqueta con el nombre de la parroquia (para Fase 2, debajo de las dos burbujas).
function ssEtiquetaParr(lat, lng, nombre){
  const b = L.marker([lat, lng], { icon: L.divIcon({
    className: 'ss-burbuja',
    html: `<div style="text-align:center;font-size:10px;color:#fff;text-shadow:0 1px 2px #000;font-weight:600;white-space:nowrap;margin-top:22px;">${nombre}</div>`,
    iconSize: [1, 1], iconAnchor: [0, 0]
  })});
  ssBurbujas.addLayer(b);
}

// Parroquia seleccionada actualmente (para refrescar al cambiar de fase).
let ssParrSel = null;

function ssDetalleParroquia(nombre){
  if (!nombre) return;
  ssParrSel = nombre;
  const c = SS_IDX[ssNorm(nombre)];
  const vacio = document.getElementById('ss-detalle-vacio');
  const cont = document.getElementById('ss-detalle-cont');
  if (vacio) vacio.style.display = 'none';
  cont.style.display = 'block';

  if (!c){
    cont.innerHTML = '<div style="font-size:14px;font-weight:700;color:#22366F;">'+nombre+'</div>'+
      '<div style="color:#9aa1b4;font-size:12px;margin-top:8px;">Sin datos registrados en esta parroquia.</div>';
    return;
  }

  const total = parseInt(c.total)||0;
  const enObra = parseInt(c.en_obra)||0;
  const rojos = parseInt(c.rojos)||0;
  const amar = parseInt(c.amarillos)||0;
  const verdes = parseInt(c.verdes)||0;
  const derr = parseInt(c.derrumbados)||0;
  const f1 = parseInt(c.fase1)||0;
  const f2lev = parseInt(c.fase2_levant)||0;
  const f2rec = parseInt(c.fase2_reconstr)||0;
  const f3 = parseInt(c.fase3)||0;
  const valFase = ssValor(c, ssFaseActual);
  const etiquetaFase = ssFaseActual==='2' ? 'En intervención'
                     : ssFaseActual==='3' ? 'Recuperadas'
                     : ssFaseActual==='1' ? 'Inspeccionadas'
                     : 'Total edificaciones';

  cont.innerHTML =
    '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">'+
      '<i class="bi bi-geo-alt-fill" style="color:#22366F;font-size:18px;"></i>'+
      '<div style="font-size:15px;font-weight:800;color:#22366F;line-height:1.1;">'+nombre+'</div>'+
    '</div>'+

    '<div style="background:#22366F;color:#fff;border-radius:9px;padding:11px;margin-bottom:12px;">'+
      '<div style="font-size:26px;font-weight:800;line-height:1;">'+valFase+'</div>'+
      '<div style="font-size:11px;opacity:.9;margin-top:3px;">'+etiquetaFase+'</div>'+
    '</div>'+

    '<div style="font-size:10.5px;color:#9aa1b4;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;">Semáforo de habitabilidad</div>'+
    ssBarraSem('Habitables', verdes, '#2E7D32')+
    ssBarraSem('Precaución', amar, '#C9A227')+
    ssBarraSem('Inseguras', rojos, '#A61C1C')+
    (derr>0 ? ssBarraSem('Derrumbadas', derr, '#2B2B2B') : '')+

    '<div style="border-top:1px solid #e6e9f0;margin:12px 0;"></div>'+
    '<div style="font-size:10.5px;color:#9aa1b4;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;">Por fase</div>'+
    ssBarraSem('Fase 1 · Inspeccionadas', f1, '#A66A00')+
    ssBarraSem('Fase 2 · Con levantamiento', f2lev, '#22366F')+
    ssBarraSem('Fase 2 · En reconstrucción', f2rec, '#C9A227')+
    ssBarraSem('Fase 3 · Recuperadas', f3, '#1D6E56')+
    '<div style="display:flex;justify-content:space-between;font-size:12.5px;padding:8px 0 2px;border-top:1px solid #f0f2f6;margin-top:6px;">'+
      '<span style="color:#5b6478;">Total edificaciones</span><b style="color:#22366F;">'+total+'</b></div>';
}

function ssBarraSem(lbl, val, color){
  return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;">'+
    '<span style="width:9px;height:9px;border-radius:50%;background:'+color+';flex-shrink:0;"></span>'+
    '<span style="flex:1;color:#5b6478;">'+lbl+'</span>'+
    '<b style="color:'+color+';">'+val+'</b></div>';
}

function ssFase(btn, fase){
  ssFaseActual = fase;
  document.querySelectorAll('.ss-fbtn').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  ssRedibujar();
  // Alternar la leyenda: en Fase 2 se explican las dos burbujas.
  const lg1 = document.getElementById('ss-map-leyenda');
  const lg2 = document.getElementById('ss-map-leyenda-f2');
  if (lg1 && lg2) {
    const esF2 = (fase === '2');
    lg1.style.display = esF2 ? 'none' : 'block';
    lg2.style.display = esF2 ? 'flex' : 'none';
  }
  // Si hay una parroquia abierta, refrescar su detalle con la nueva fase.
  if (ssParrSel) ssDetalleParroquia(ssParrSel);
}

document.addEventListener('DOMContentLoaded', ssInitMapa);
</script>

<?php include __DIR__ . '/_kpi_modal.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
