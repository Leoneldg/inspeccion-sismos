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
                    <a class="btn-fase" id="sp-btn-fase" href="#"></a>

                    <!-- Selección de ente (fase 2). Oculto hasta que se pulsa el botón. -->
                    <div id="sp-entes" style="display:none;margin-top:10px;">
                        <label class="text-sm" style="display:block;margin-bottom:5px;color:#55617f;font-weight:600;">
                            Seleccione el ente responsable de la recuperación
                        </label>
                        <select id="sp-ente-sel" class="form-control" style="width:100%;">
                            <option value="">— Elegir ente —</option>
                        </select>
                        <div style="display:flex;gap:7px;margin-top:8px;">
                            <button class="btn btn-primary btn-sm" id="sp-ente-ok" style="flex:1;justify-content:center;">
                                <i class="bi bi-check-lg"></i> Asignar
                            </button>
                            <button class="btn btn-outline btn-sm" id="sp-ente-cancel" style="justify-content:center;">
                                Cancelar
                            </button>
                        </div>
                    </div>

                    <!-- Mensaje de confirmación / error -->
                    <div id="sp-msg" style="display:none;margin-top:10px;padding:10px 11px;border-radius:8px;font-size:13px;line-height:1.4;"></div>

                    <a href="#" id="sp-ficha" class="btn btn-outline btn-sm" style="width:100%;margin-top:8px;justify-content:center;">
                        <i class="bi bi-clipboard-data"></i> Ver ficha completa
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
const ENTES        = <?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => $x['nombre']], $entes), JSON_UNESCAPED_UNICODE) ?>;
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;
const URL_ENTE     = '<?= APP_URL_BASE ?>seguimiento/asignar_ente.php';

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

/* ---------- Configura el botón grande según el estado de la edificación ----------
   - Si aún NO tiene ente asignado  -> el botón despliega el listado de entes
     ahí mismo en el mapa (asignación rápida, sin salir a la ficha).
   - Si YA tiene ente asignado      -> el botón lleva a la ficha completa,
     que es donde el responsable carga el plan y las fotos de seguimiento. */
function pintarBoton(p) {
    const btn    = document.getElementById('sp-btn-fase');
    const bloque = document.getElementById('sp-entes');
    const msg    = document.getElementById('sp-msg');

    // Cada vez que se abre un punto, se parte de cero.
    bloque.style.display = 'none';
    msg.style.display    = 'none';
    btn.style.display    = 'flex';

    // Sin permiso de edición: solo consulta.
    if (!PUEDE_EDITAR) {
        btn.href = p.ficha_url;
        btn.style.background = '#767c94';
        btn.innerHTML = '<i class="bi bi-clipboard-data"></i><span>Ver ficha completa</span>';
        btn.onclick = null;
        return;
    }

    const tieneEnte = !!p.ente;

    if (!tieneEnte) {
        // --- Falta asignar el ente: el botón abre el listado ---
        btn.href = '#';
        btn.style.background = '#2d4488';
        btn.innerHTML = '<i class="bi bi-building-add"></i><span>Asignar ente de recuperación'
                      + '<span class="btn-fase-sub">Seleccione el ente responsable</span></span>';
        btn.onclick = (ev) => {
            ev.preventDefault();
            abrirSelectorEntes(p);
        };
    } else if (p.fase === 3) {
        // --- Culminada ---
        btn.href = p.ficha_url;
        btn.style.background = '#2E7D32';
        btn.innerHTML = '<i class="bi bi-check-circle-fill"></i><span>Recuperación culminada'
                      + '<span class="btn-fase-sub">' + p.ente + ' · Ver ficha</span></span>';
        btn.onclick = null;
    } else {
        // --- Ya tiene ente: en fase de recuperación. Ícono de mantenimiento. ---
        btn.href = p.ficha_url;
        btn.style.background = '#2d4488';
        btn.innerHTML = '<i class="bi bi-tools"></i><span>En fase de recuperación'
                      + '<span class="btn-fase-sub">' + p.ente + ' · Abrir ficha y cargar seguimiento</span></span>';
        btn.onclick = null;
    }
}

/* ---------- Despliega el listado de entes para asignar ---------- */
function abrirSelectorEntes(p) {
    const bloque = document.getElementById('sp-entes');
    const sel    = document.getElementById('sp-ente-sel');
    const msg    = document.getElementById('sp-msg');

    // Llenar el listado de entes.
    sel.innerHTML = '<option value="">— Elegir ente —</option>'
        + ENTES.map(en => `<option value="${en.id}">${en.nombre}</option>`).join('');
    sel.value = '';

    msg.style.display = 'none';
    bloque.style.display = 'block';
    document.getElementById('sp-btn-fase').style.display = 'none';

    document.getElementById('sp-ente-cancel').onclick = () => {
        bloque.style.display = 'none';
        document.getElementById('sp-btn-fase').style.display = 'flex';
    };
    document.getElementById('sp-ente-ok').onclick = () => asignarEnte(p, sel.value);
}

/* ---------- Envía la asignación del ente al servidor ---------- */
async function asignarEnte(p, enteId) {
    const msg = document.getElementById('sp-msg');
    const ok  = document.getElementById('sp-ente-ok');

    if (!enteId) {
        mostrarMsg('Seleccione un ente de la lista.', false);
        return;
    }

    const textoOriginal = ok.innerHTML;
    ok.disabled  = true;
    ok.innerHTML = '<i class="bi bi-arrow-repeat"></i> Asignando…';

    try {
        const res = await fetch(URL_ENTE, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ inspeccion_id: p.id, ente_id: enteId }).toString()
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.mensaje || 'No se pudo asignar el ente.');

        // Actualiza el punto en memoria: ya tiene ente y entra en recuperación.
        p.ente = data.ente_nombre;
        if (p.fase === 0) p.fase = 2;                       // pasa a fase de recuperación
        if (!p.estado_obra || p.estado_obra === 'Sin iniciar') p.estado_obra = 'En ejecución';

        // Repinta el marcador (ahora lleva el ícono de mantenimiento).
        const m = marcadores[p.id];
        if (m) m.setIcon(iconoPunto(p));

        // Repinta el panel y muestra la confirmación.
        abrirPanel(p);
        mostrarMsg(data.mensaje, true);

    } catch (err) {
        ok.disabled  = false;
        ok.innerHTML = textoOriginal;
        mostrarMsg(err.message, false);
    }
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

    pintarBoton(p);
    document.getElementById('seg-panel').classList.add('open');
}

/* ---------- Inicialización del mapa ---------- */
(function initMapa() {
    if (!PUNTOS.length) return;

    map = L.map('seg-map', { zoomControl: true });
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Esri'
    }).addTo(map);

    // Sin agrupamiento: todos los puntos se dibujan individualmente, para que
    // se vea cada edificación y no un número que hay que desplegar.
    const capa = L.featureGroup();

    PUNTOS.forEach(p => {
        const m = L.marker([p.lat, p.lng], { icon: iconoPunto(p), title: p.nombre });
        m.on('click', () => abrirPanel(p));
        marcadores[p.id] = m;
        capa.addLayer(m);
    });

    capa.addTo(map);
    map.fitBounds(capa.getBounds().pad(0.15));

    document.getElementById('seg-panel-close').addEventListener('click', () => {
        document.getElementById('seg-panel').classList.remove('open');
        seleccionado = null;
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
