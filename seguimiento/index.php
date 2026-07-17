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

$filtros = [
    'q'           => trim($_GET['q'] ?? ''),
    'estado'      => trim($_GET['estado'] ?? ''),
    'estado_obra' => trim($_GET['estado_obra'] ?? ''),
    'ente_id'     => trim($_GET['ente_id'] ?? ''),
    'solo_mias'   => !empty($_GET['solo_mias']),
];

$kpis        = segKpis();
$edificios   = segListaEdificios($filtros);
$entes       = segEntes(usuarioEsMaster() ? null : estadoDelUsuario());
$decisiones  = catalogoDecisionFinal();
$estadosObra = segEstadosObra();
$fasesCat    = segFasesRecuperacion();
$puedeEditar = puede('seguimiento', 'editar');

// ---------------------------------------------------------------------
// Puntos para el mapa.
//   1) Si la inspección tiene coordenadas GPS propias y válidas, se usan.
//   2) Si NO las tiene (nulas, 0,0) o tiene una coordenada "por defecto"
//      (la misma repetida en muchas inspecciones, señal de que no es una
//      ubicación real sino un valor puesto automáticamente), se le genera
//      un punto aleatorio dentro del polígono de su parroquia.
//   3) Si no hay forma de ubicarla (sin parroquia), no se muestra.
// ---------------------------------------------------------------------

// Primero se detectan las coordenadas repetidas en masa: si un mismo par
// lat/lng aparece en muchas inspecciones, no es una ubicación real.
$umbralDuplicadas = 5;   // a partir de aquí se considera valor por defecto
$conteoCoords = [];
foreach ($edificios as $ed) {
    $lat = $ed['latitud'] ?? null;
    $lng = $ed['longitud'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') continue;
    $k = round((float)$lat, 5) . ',' . round((float)$lng, 5);
    $conteoCoords[$k] = ($conteoCoords[$k] ?? 0) + 1;
}
$coordsPorDefecto = [];
foreach ($conteoCoords as $k => $n) {
    if ($n >= $umbralDuplicadas) $coordsPorDefecto[$k] = true;
}

// Anclas por parroquia: inspecciones con coordenada propia fiable. Sirven de
// referencia de dónde hay edificaciones reales, para no colocar los puntos
// aproximados en zonas deshabitadas (montañas) de parroquias muy extensas.
$anclas = [];
foreach ($edificios as $ed) {
    $la = $ed['latitud'] ?? null; $ln = $ed['longitud'] ?? null;
    if ($la === null || $ln === null || $la === '' || $ln === '') continue;
    if ((float)$la == 0.0 && (float)$ln == 0.0) continue;
    $k = round((float)$la, 5) . ',' . round((float)$ln, 5);
    if (isset($coordsPorDefecto[$k])) continue;
    $pq = segNorm((string)($ed['estado'] ?? '')) . '|' . segNorm((string)($ed['parroquia'] ?? ''));
    $anclas[$pq][] = [(float)$la, (float)$ln];
}

$puntos       = [];
$sinUbicacion = 0;   // ni coordenadas ni parroquia ubicable
$aproximados  = 0;   // ubicadas por parroquia (no por GPS propio)

foreach ($edificios as $ed) {
    $lat = $ed['latitud'] ?? null;
    $lng = $ed['longitud'] ?? null;

    // ¿Tiene coordenadas propias utilizables?
    $tieneGps = ($lat !== null && $lng !== null && $lat !== '' && $lng !== ''
                 && !((float)$lat == 0.0 && (float)$lng == 0.0));

    // Si su coordenada es una de las repetidas en masa, no es real: se descarta.
    if ($tieneGps) {
        $k = round((float)$lat, 5) . ',' . round((float)$lng, 5);
        if (isset($coordsPorDefecto[$k])) $tieneGps = false;
    }

    $aprox = false;
    if (!$tieneGps) {
        // Se ubica dentro de su parroquia.
        $pqKey = segNorm((string)($ed['estado'] ?? '')) . '|' . segNorm((string)($ed['parroquia'] ?? ''));
        $pt = segUbicarEnParroquia(
            (string)($ed['estado'] ?? ''),
            (string)($ed['parroquia'] ?? ''),
            (int)$ed['inspeccion_id'],         // semilla: siempre cae en el mismo punto
            $anclas[$pqKey] ?? []
        );
        if ($pt === null) { $sinUbicacion++; continue; }   // sin parroquia -> no se muestra
        [$lat, $lng] = $pt;
        $aprox = true;
        $aproximados++;
    }

    $fase = segFaseDe($ed['estado_obra'] ?? null, $ed['avance_pct'] ?? 0);
    $meta = $decisiones[$ed['decision_final']] ?? ['color' => '#767c94', 'corto' => '—'];

    $puntos[] = [
        'id'          => (int)$ed['inspeccion_id'],
        'codigo'      => $ed['codigo'],
        'nombre'      => $ed['nombre_edificio'],
        'lat'         => (float)$lat,
        'lng'         => (float)$lng,
        'aprox'       => $aprox,     // true = ubicación aproximada por parroquia
        'color'       => $meta['color'],
        'decision'    => $meta['corto'],
        'estado'      => $ed['estado'] ?: '—',
        'municipio'   => $ed['municipio'] ?: '—',
        'parroquia'   => $ed['parroquia'] ?: '—',
        'uso'         => $ed['uso_edificacion'] ?: '—',
        'pisos'       => (int)($ed['num_pisos'] ?? 0),
        'personas'    => (int)($ed['personas'] ?? 0),
        'fecha'       => $ed['fecha_inspeccion'] ?: '—',
        'ente'        => $ed['ente_nombre'] ?: null,
        'estado_obra' => $ed['estado_obra'] ?: null,
        'avance'      => round((float)($ed['avance_pct'] ?? 0)),
        'fase'        => $fase,
        'ficha_url'   => APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . (int)$ed['inspeccion_id'],
        'levantamiento_url' => APP_URL_BASE . 'seguimiento/levantamiento.php?inspeccion=' . (int)$ed['inspeccion_id'],
    ];
}

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<style>
/* ---- Mapa de seguimiento ---- */
#seg-map { height: 620px; width: 100%; border-radius: 10px; z-index: 1; }
.seg-map-wrap { position: relative; }

/* Panel lateral con la ficha técnica del punto seleccionado */
.seg-panel {
    position: absolute; top: 12px; right: 12px; width: 330px; max-width: calc(100% - 24px);
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
        <div><div class="seg-kpi-num"><?= (int)$kpis['total_edificios'] ?></div><div class="seg-kpi-lbl">Edificaciones</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#fff4e0;color:#C9A227;"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['en_ejecucion'] ?></div><div class="seg-kpi-lbl">En ejecución</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#e5f7ee;color:#2E7D32;"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['culminadas'] ?></div><div class="seg-kpi-lbl">Culminadas</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#f1f2f6;color:#767c94;"><i class="bi bi-clipboard-x"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['sin_seguimiento'] ?></div><div class="seg-kpi-lbl">Sin seguimiento</div></div>
    </div>
    <div class="seg-kpi seg-kpi-wide">
        <div class="seg-kpi-ico" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-graph-up-arrow"></i></div>
        <div style="flex:1;">
            <div class="seg-kpi-lbl">Avance promedio</div>
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
        <form method="get" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="margin:0;">
                <label class="text-sm">Buscar</label>
                <input type="text" name="q" class="form-control" style="width:230px;" placeholder="Edificio o código…" value="<?= e($filtros['q']) ?>">
            </div>
            <?php if (usuarioEsMaster()): ?>
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado</label>
                <select name="estado" class="form-control" style="width:170px;">
                    <option value="">Todos</option>
                    <?php foreach (catalogoEstados() as $est): ?>
                        <option value="<?= e($est) ?>" <?= $filtros['estado'] === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado de obra</label>
                <select name="estado_obra" class="form-control" style="width:160px;">
                    <option value="">Todas</option>
                    <option value="__sin__" <?= $filtros['estado_obra'] === '__sin__' ? 'selected' : '' ?>>Sin seguimiento</option>
                    <?php foreach (array_keys($estadosObra) as $eo): ?>
                        <option value="<?= e($eo) ?>" <?= $filtros['estado_obra'] === $eo ? 'selected' : '' ?>><?= e($eo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Ente</label>
                <select name="ente_id" class="form-control" style="width:180px;">
                    <option value="">Todos</option>
                    <?php foreach ($entes as $ente): ?>
                        <option value="<?= (int)$ente['id'] ?>" <?= (string)$filtros['ente_id'] === (string)$ente['id'] ? 'selected' : '' ?>><?= e($ente['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="check-row" style="margin:0 4px 6px;">
                <input type="checkbox" name="solo_mias" value="1" <?= $filtros['solo_mias'] ? 'checked' : '' ?>>
                <span class="text-sm">Solo asignadas a mí</span>
            </label>
            <button class="btn btn-outline"><i class="bi bi-funnel"></i> Filtrar</button>
            <a href="<?= APP_URL_BASE ?>seguimiento/entes.php" class="btn btn-outline"><i class="bi bi-building-gear"></i> Entes</a>
        </form>
    </div>
</div>

<!-- Mapa -->
<div class="card">
    <div class="card-header">
        <h2><i class="bi bi-geo-alt-fill"></i> Mapa de recuperación (<?= count($puntos) ?>)</h2>
        <span class="text-sm text-muted">
            <?php if ($aproximados > 0): ?>
                <i class="bi bi-geo"></i> <?= $aproximados ?> ubicadas por parroquia (aprox.)
            <?php endif; ?>
            <?php if ($sinUbicacion > 0): ?>
                &nbsp;·&nbsp; <?= $sinUbicacion ?> sin ubicación (no se muestran)
            <?php endif; ?>
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
        <?php if (!$puntos): ?>
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

                    <!-- Acción principal: levantamiento técnico (paso inicial) -->
                    <a href="#" id="sp-levantamiento" class="btn btn-primary" style="width:100%;justify-content:center;">
                        <i class="bi bi-building-gear"></i> Levantamiento técnico
                    </a>
                    <!-- Seguimiento de avance (remodelación Antes/Durante/Después) -->
                    <a href="#" id="sp-remodelacion" class="btn btn-outline" style="width:100%;margin-top:8px;justify-content:center;">
                        <i class="bi bi-clock-history"></i> Seguimiento de avance
                    </a>
                    <!-- Ficha de seguimiento (después del levantamiento) -->
                    <a href="#" id="sp-ficha" class="btn btn-outline" style="width:100%;margin-top:8px;justify-content:center;">
                        <i class="bi bi-clipboard-data"></i> Ficha de seguimiento
                    </a>
                    <!-- Seguimiento de remodelación (fase durante/después) -->
                    <a href="#" id="sp-remodelacion" class="btn btn-outline" style="width:100%;margin-top:8px;justify-content:center;">
                        <i class="bi bi-hammer"></i> Seguimiento de remodelación
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const PUNTOS       = <?= json_encode($puntos, JSON_UNESCAPED_UNICODE) ?>;
const FASES        = <?= json_encode($fasesCat, JSON_UNESCAPED_UNICODE) ?>;
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;
const APP_URL_BASE = '<?= APP_URL_BASE ?>';
const PARROQUIA_URL = APP_URL_BASE + 'dashboard/api_parroquia.php';

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
    document.getElementById('sp-ficha').href = p.ficha_url;
    document.getElementById('sp-levantamiento').href = p.levantamiento_url;
    document.getElementById('sp-remodelacion').href = APP_URL_BASE + 'seguimiento/remodelacion.php?inspeccion=' + p.id;
    document.getElementById('sp-remodelacion').href = APP_URL_BASE + 'seguimiento/remodelacion.php?inspeccion=' + p.id;

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
        `<div style="display:flex;gap:7px;margin:12px 0;">
            ${card('Rojo', pc.rojo||0, '#A61C1C')}
            ${card('Amarillo', pc.amarillo||0, '#C9A227')}
            ${card('Verde', pc.verde||0, '#2E7D32')}
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

(function initMapa() {
    // Centro y zoom inicial: Caracas (Distrito Capital).
    const CARACAS = [10.5061, -66.9146];

    map = L.map('seg-map', { zoomControl: true }).setView(CARACAS, 13);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Esri'
    }).addTo(map);

    // Dibujar los polígonos de las parroquias del Distrito Capital.
    // Al hacer clic en una parroquia, se abre el panel de su encargado.
    fetch(APP_URL_BASE + 'assets/geo/parroquias/distrito_capital.geojson')
        .then(r => r.json())
        .then(geo => {
            const capaParr = L.geoJSON(geo, {
                style: { color:'#C9A227', weight:1.5, fillColor:'#22366F', fillOpacity:0.06 },
                onEachFeature: (feature, layer) => {
                    const nombre = feature.properties.parroquia || '';
                    const estado = feature.properties.estado || 'Distrito Capital';
                    layer.on('mouseover', () => layer.setStyle({ fillOpacity:0.20, weight:2.5 }));
                    layer.on('mouseout', () => layer.setStyle({ fillOpacity:0.06, weight:1.5 }));
                    layer.on('click', () => abrirPanelParroquia(estado, nombre));
                    layer.bindTooltip(nombre, { sticky:true });
                }
            }).addTo(map);
            // Si no hay puntos, encuadrar el mapa a las parroquias de Caracas.
            if (!PUNTOS.length) {
                try { map.fitBounds(capaParr.getBounds().pad(0.05)); } catch(e) {}
            }
        }).catch(()=>{});

    // Sin agrupamiento: todos los puntos se dibujan individualmente.
    const capa = L.featureGroup();

    PUNTOS.forEach(p => {
        const m = L.marker([p.lat, p.lng], { icon: iconoPunto(p), title: p.nombre });
        m.on('click', () => abrirPanel(p));
        marcadores[p.id] = m;
        capa.addLayer(m);
    });

    if (PUNTOS.length) {
        capa.addTo(map);
        map.fitBounds(capa.getBounds().pad(0.15));
    }

    document.getElementById('seg-panel-close').addEventListener('click', () => {
        document.getElementById('seg-panel').classList.remove('open');
        seleccionado = null;
    });
})();
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
