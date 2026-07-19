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
.fs-pasos { display:flex; gap:10px; flex-wrap:wrap; }
.fs-paso { flex:1; min-width:170px; display:flex; align-items:center; gap:9px;
           background:#fff; border-radius:9px; padding:10px 12px; font-size:13.5px; color:#2a3140; }
.fs-num { flex-shrink:0; width:26px; height:26px; border-radius:50%; background:#22366F;
          color:#fff; font-weight:800; font-size:14px; display:flex; align-items:center; justify-content:center; }
.fs-apto-fila:hover { background: #f4f7fd; }
.btn-foto-mini {
    background: #fff; border: 1px solid #dbe0ec; color: #2d4488;
    border-radius: 7px; padding: 3px 9px; font-size: 11px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
}
.btn-foto-mini:hover { background: #eef2fb; }

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
    .foto-item img { width:100px; height:100px; object-fit:cover; border-radius:8px;
                     border:1px solid #d8dce6; cursor:zoom-in; transition:transform .12s; }
    .foto-item img:hover { transform:scale(1.06); border-color:#22366F; }
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

    <!-- Fotos generales del edificio -->
    <div class="fs-card" id="fs-fotos-edificio" style="padding:15px 20px;display:none;">
        <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
            <i class="bi bi-images"></i> Fotos del edificio
            <span id="fs-nfotos-ed" style="background:#eef2fb;color:#22366F;border-radius:10px;
                  padding:1px 9px;font-size:12px;font-weight:700;"></span>
        </div>
        <div id="fs-fotos-ed-lista" class="fotos-fila"></div>
    </div>

    <!-- Fotos generales del edificio -->
    <div class="fs-card" style="padding:15px 20px;">
        <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
            <i class="bi bi-images"></i> Fotos del edificio
        </div>
        <div id="fs-fotos-edificio">
            <span class="text-muted text-sm">Cargando…</span>
        </div>
    </div>

    <!-- Metros cuadrados a reparar -->
    <div class="fs-card" style="padding:15px 20px;">
        <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
            <i class="bi bi-rulers"></i> Metros cuadrados y materiales
        </div>
        <div id="fs-m2-total">
            <span class="text-muted text-sm">Calculando…</span>
        </div>
        <p class="text-sm text-muted" style="margin:9px 0 0;">
            Se suman los metros registrados en el levantamiento por cada ambiente,
            elemento del piso y área común.
        </p>
    </div>

    <!-- Fecha de entrega y días restantes -->
    <?php
    $plan  = $edificioId ? recPlan($edificioId) : null;
    $plazo = recEstadoPlazo($plan, 0);   // el avance real se ajusta desde el JS
    ?>
    <div class="fs-card" style="padding:15px 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="font-weight:700;color:#22366F;">
                <i class="bi bi-calendar-range"></i> Plazo de la obra
            </div>
            <?php if ($puedeCargar): ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="abrirPlazo()">
                <i class="bi bi-pencil"></i> <?= $plazo ? 'Cambiar fechas' : 'Definir fechas' ?>
            </button>
            <?php endif; ?>
        </div>

        <div id="fs-plazo-info" style="margin-top:11px;">
        <?php if ($plazo): ?>
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
                <div style="background:<?= $plazo['color'] ?>12;border:1px solid <?= $plazo['color'] ?>44;
                            border-radius:10px;padding:11px 16px;min-width:170px;">
                    <div style="font-size:22px;font-weight:800;color:<?= $plazo['color'] ?>;line-height:1;">
                        <i class="bi <?= $plazo['icono'] ?>"></i>
                        <span id="fs-dias-txt"><?= e($plazo['texto']) ?></span>
                    </div>
                    <div style="font-size:11.5px;color:#5b6478;margin-top:4px;">
                        Entrega: <strong><?= date('d/m/Y', strtotime($plazo['fecha_fin'])) ?></strong>
                    </div>
                </div>

                <?php if (!empty($plazo['fecha_inicio'])): ?>
                <div style="font-size:12.5px;color:#5b6478;">
                    <div><strong>Inicio:</strong> <?= date('d/m/Y', strtotime($plazo['fecha_inicio'])) ?></div>
                    <?php if (!empty($plazo['dias_totales'])): ?>
                    <div><strong>Duración:</strong> <?= (int)$plazo['dias_totales'] ?> días</div>
                    <?php endif; ?>
                    <?php if ($plazo['avance_esperado'] !== null): ?>
                    <div><strong>Avance esperado hoy:</strong> <?= (int)$plazo['avance_esperado'] ?>%</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="background:#f7f9fd;border-radius:9px;padding:11px 14px;font-size:13px;color:#5b6478;">
                <i class="bi bi-calendar-x"></i> Sin fecha de entrega definida.
                <?php if ($puedeCargar): ?>
                Toque <strong>Definir fechas</strong> para establecer el plazo.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Barra de avance general -->
    <div class="fs-card" style="padding:16px 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
            <div style="font-weight:700;color:#22366F;"><i class="bi bi-graph-up-arrow"></i> Avance general de la remodelación</div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="ObrasFotos.verGaleria()"
                        title="Fotos guardadas en este teléfono">
                    <i class="bi bi-images"></i> Mis fotos
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="descargarParaCampo()"
                        title="Guardar en el teléfono para trabajar sin señal">
                    <i class="bi bi-cloud-arrow-down-fill"></i> <span id="btn-descarga-txt">Llevar sin señal</span>
                </button>
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

    <!-- Guía de uso, visible para cualquiera -->
    <?php if ($puedeCargar): ?>
    <div class="fs-card" style="background:#f4f7fd;padding:14px 18px;">
        <div style="font-weight:700;color:#22366F;margin-bottom:8px;font-size:15px;">
            <i class="bi bi-signpost-split-fill"></i> Cómo registrar el avance
        </div>
        <div class="fs-pasos">
            <div class="fs-paso">
                <span class="fs-num">1</span>
                <span>Toque un <strong>piso</strong> para abrirlo.</span>
            </div>
            <div class="fs-paso">
                <span class="fs-num">2</span>
                <span>Toque un <strong>apartamento</strong> para ver sus ambientes.</span>
            </div>
            <div class="fs-paso">
                <span class="fs-num">3</span>
                <span>Tome la <strong>foto</strong> del trabajo hecho.</span>
            </div>
            <div class="fs-paso">
                <span class="fs-num">4</span>
                <span>Mueva la <strong>barra</strong> según lo avanzado.</span>
            </div>
        </div>
        <div style="font-size:12.5px;color:#5b6478;margin-top:10px;padding-top:8px;border-top:1px solid #dde3ef;">
            <i class="bi bi-info-circle"></i>
            El porcentaje se suma solo: los ambientes forman el del apartamento,
            los apartamentos el del piso, y los pisos el del edificio.
        </div>
    </div>
    <?php endif; ?>

    <!-- Pisos y apartamentos -->
    <div id="fs-pisos"><p class="text-muted">Cargando pisos…</p></div>
</div>

<!-- Dos entradas: cámara para tomar ahora, galería para elegir una foto ya tomada. -->
<input type="file" id="fs-file-camara" accept="image/*" capture="environment" style="display:none;" onchange="_onDuranteElegida(this, true)">
<input type="file" id="fs-file-galeria" accept="image/*" style="display:none;" onchange="_onDuranteElegida(this, false)">

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
const PLAZO_INICIO = <?= json_encode($plan['fecha_inicio_estimada'] ?? '') ?>;
const PLAZO_FIN    = <?= json_encode($plan['fecha_fin_estimada'] ?? '') ?>;
let _duranteDestino = null;

let _arbol = null;   // árbol de pisos/apartamentos con porcentajes

// Carga instantánea: solo pisos y porcentajes (sin fotos).
async function cargarFicha() {
    let d = null;

    // Con señal: traer del servidor y guardar copia para trabajar sin señal.
    if (navigator.onLine) {
        try {
            const res = await fetch(URL_BASE + 'arbol_avance.php?inspeccion=' + INSPECCION_ID);
            d = await res.json();
            if (d.ok && window.ObrasOffline) {
                // Guardado automático de lo que abrió, por si vuelve sin señal.
                ObrasOffline.descargarFicha(INSPECCION_ID).catch(() => {});
            }
        } catch (e) { d = null; }
    }

    // Sin señal o falló: usar la copia guardada en el teléfono.
    if (!d || !d.ok) {
        if (window.ObrasOffline) {
            try {
                const guardada = await ObrasOffline.obtenerFicha(INSPECCION_ID);
                if (guardada && guardada.arbol) {
                    d = guardada.arbol;
                    d.ok = true;
                    mostrarAvisoCopiaLocal(guardada.guardado_en);
                }
            } catch (e) { /* sin copia */ }
        }
    }

    if (!d || !d.ok) {
        // Limpiar los bloques que quedarían en "Cargando…" para siempre.
        const m2c = document.getElementById('fs-m2-total');
        if (m2c) m2c.innerHTML = '<span class="text-muted text-sm">'
            + 'No se pudo calcular: ' + ((d && d.mensaje) || 'error al cargar') + '</span>';
        const fec = document.getElementById('fs-fotos-edificio');
        if (fec) {
            const tarjeta = fec.closest('.fs-card');
            if (tarjeta) tarjeta.style.display = 'none';
        }

        document.getElementById('fs-pisos').innerHTML =
            '<div style="background:#fff6f6;border:1px solid #A61C1C33;border-radius:9px;padding:14px;">'
            + '<strong style="color:#A61C1C;"><i class="bi bi-wifi-off"></i> Sin señal y sin copia guardada</strong>'
            + '<div style="font-size:13px;color:#5b6478;margin-top:5px;">'
            + 'Esta edificación no se ha descargado al teléfono. Conéctese a internet '
            + 'y ábrala una vez para poder trabajarla sin señal.</div></div>';
        return;
    }
    _arbol = d;
    pintarBarraGlobal(d.avance_edificio);
    pintarPisos(d.pisos);
}

/** Ventana para definir o cambiar las fechas de la obra. */
function abrirPlazo() {
    const capa = document.createElement('div');
    capa.id = 'fs-plazo-modal';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2300;'
        + 'display:flex;align-items:center;justify-content:center;padding:16px;';
    capa.innerHTML =
        '<div style="background:#fff;border-radius:13px;max-width:400px;width:100%;padding:20px 22px;">'
        + '<div style="font-weight:700;color:#22366F;font-size:17px;margin-bottom:4px;">Plazo de la obra</div>'
        + '<div style="font-size:12.5px;color:#5b6478;margin-bottom:14px;">'
        + 'El sistema calculará solo los días restantes.</div>'
        + '<div class="field"><label class="text-sm">Fecha de inicio</label>'
        + '<input type="date" id="pl-inicio" class="form-control" value="' + (PLAZO_INICIO || '') + '"></div>'
        + '<div class="field"><label class="text-sm">Fecha de entrega *</label>'
        + '<input type="date" id="pl-fin" class="form-control" value="' + (PLAZO_FIN || '') + '"></div>'
        + '<button onclick="guardarPlazo()" class="btn btn-primary" '
        + 'style="width:100%;justify-content:center;margin:10px 0 8px;">'
        + '<i class="bi bi-check-lg"></i> Guardar</button>'
        + '<button onclick="document.getElementById(\'fs-plazo-modal\').remove()" '
        + 'style="width:100%;background:transparent;border:1px solid #dbe0ec;border-radius:8px;'
        + 'padding:10px;color:#55617f;cursor:pointer;font-size:14px;">Cancelar</button>'
        + '</div>';
    document.body.appendChild(capa);
}

async function guardarPlazo() {
    const ini = document.getElementById('pl-inicio').value;
    const fin = document.getElementById('pl-fin').value;
    if (!fin) { alert('Indique la fecha de entrega.'); return; }
    if (ini && fin < ini) { alert('La fecha de entrega no puede ser anterior al inicio.'); return; }

    const payload = {
        inspeccion_id: INSPECCION_ID, accion: 'plazo',
        fecha_inicio_estimada: ini, fecha_fin_estimada: fin,
    };

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
            'Plazo de la obra');
        alert('Sin señal: las fechas quedaron guardadas en el teléfono.');
        document.getElementById('fs-plazo-modal').remove();
        return;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials: 'same-origin'
        });
        const d = await res.json();
        if (!d.ok) { alert(d.mensaje || 'No se pudo guardar.'); return; }
        location.reload();
    } catch (e) {
        alert('Sin conexión. Intente de nuevo.');
    }
}

// Descarga manual: el sistematizador prepara la edificación antes de salir.
async function descargarParaCampo() {
    if (!window.ObrasOffline) { alert('Este navegador no permite trabajar sin señal.'); return; }
    if (!navigator.onLine) { alert('Necesita señal para descargar la ficha.'); return; }
    const txt = document.getElementById('btn-descarga-txt');
    const original = txt.textContent;
    try {
        await ObrasOffline.descargarFicha(INSPECCION_ID, m => { txt.textContent = m; });
        txt.textContent = 'Guardada en el teléfono';
        alert('Listo. Esta edificación ya se puede trabajar sin señal.');
        setTimeout(() => { txt.textContent = original; }, 3000);
    } catch (e) {
        txt.textContent = original;
        alert('No se pudo descargar: ' + (e.message || 'error'));
    }
}

// Avisa que se está viendo la copia guardada, no datos en vivo.
function mostrarAvisoCopiaLocal(fecha) {
    const cont = document.getElementById('fs-pisos');
    if (!cont || document.getElementById('aviso-copia')) return;
    const f = fecha ? new Date(fecha).toLocaleString('es-VE') : 'hace un momento';
    const div = document.createElement('div');
    div.id = 'aviso-copia';
    div.style.cssText = 'background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;'
        + 'padding:11px 14px;margin-bottom:12px;font-size:13px;color:#8a6d1a;';
    div.innerHTML = '<i class="bi bi-phone-fill"></i> <strong>Trabajando sin señal.</strong> '
        + 'Está viendo la copia guardada en el teléfono (' + f + '). '
        + 'Todo lo que registre se subirá al recuperar la conexión.';
    cont.parentNode.insertBefore(div, cont);
}

/**
 * Fotos generales del edificio: etiqueta, azotea, tanques.
 * No cuelgan de ningún ambiente, por eso antes no se veían.
 */
function pintarFotosEdificio(fotos) {
    const cont = document.getElementById('fs-fotos-edificio');
    if (!cont) return;
    if (!fotos.length) {
        cont.closest('.fs-card').style.display = 'none';
        return;
    }
    cont.innerHTML = '<div class="fotos-fila">'
        + fotos.map(f => fotoHTML(f.ruta, f.parte || 'General', f.parte || '', f.fecha || '')).join('')
        + '</div>';
}

// Muestra el total de metros cuadrados a reparar del edificio.
function pintarMetrosTotal(m2, comunes, porTipo, materiales, porTrabajo) {
    const cont = document.getElementById('fs-m2-total');
    if (!cont) return;

    if (!m2) {
        cont.innerHTML = '<div style="background:#fffbf0;border:1px solid #C9A22755;'
            + 'border-radius:9px;padding:11px 14px;font-size:13px;color:#8a6d1a;">'
            + '<i class="bi bi-info-circle-fill"></i> '
            + 'No hay metros cuadrados registrados. Se anotan en el levantamiento técnico, '
            + 'al marcar un ambiente como <strong>"necesita reparación"</strong>.</div>';
        return;
    }

    // Total y desglose por tipo de superficie.
    let html = '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
        + '<div style="background:#eef2fb;border-radius:10px;padding:12px 18px;">'
        + '<div style="font-size:26px;font-weight:800;color:#22366F;line-height:1;">'
        + m2.toLocaleString('es-VE') + ' m²</div>'
        + '<div style="font-size:11px;color:#5b6478;text-transform:uppercase;margin-top:3px;">'
        + 'Total a reparar</div></div>';

    const tipos = Object.keys(porTipo || {});
    if (tipos.length) {
        html += '<div style="display:flex;gap:7px;flex-wrap:wrap;">';
        tipos.forEach(t => {
            html += '<div style="background:#f7f9fd;border:1px solid #e5e8f0;border-radius:9px;'
                + 'padding:8px 13px;">'
                + '<div style="font-size:16px;font-weight:700;color:#2d4488;">'
                + porTipo[t].toLocaleString('es-VE') + ' m²</div>'
                + '<div style="font-size:11px;color:#5b6478;">' + t + '</div></div>';
        });
        html += '</div>';
    }
    html += '</div>';

    // Trabajos registrados: de ahí salen los materiales.
    const trab = Object.keys(porTrabajo || {});
    if (trab.length) {
        html += '<div style="border-top:1px solid #eef0f5;padding-top:12px;margin-bottom:12px;">'
            + '<div style="font-size:11.5px;text-transform:uppercase;color:#55617f;'
            + 'font-weight:700;letter-spacing:.4px;margin-bottom:9px;">'
            + '<i class="bi bi-tools"></i> Trabajos a realizar</div>'
            + '<div style="display:flex;gap:7px;flex-wrap:wrap;">';
        trab.forEach(t => {
            html += '<div style="background:#eef2fb;border-radius:9px;padding:8px 13px;">'
                + '<div style="font-size:15px;font-weight:700;color:#22366F;">'
                + porTrabajo[t].toLocaleString('es-VE') + ' m²</div>'
                + '<div style="font-size:11.5px;color:#5b6478;">' + t + '</div></div>';
        });
        html += '</div></div>';
    }

    // Materiales estimados.
    const mats = Object.keys(materiales || {});
    if (mats.length) {
        html += '<div style="border-top:1px solid #eef0f5;padding-top:12px;">'
            + '<div style="font-size:11.5px;text-transform:uppercase;color:#55617f;'
            + 'font-weight:700;letter-spacing:.4px;margin-bottom:9px;">'
            + '<i class="bi bi-box-seam"></i> Materiales estimados</div>'
            + '<div style="display:flex;gap:7px;flex-wrap:wrap;">';
        mats.forEach(m => {
            html += '<div style="background:#fff;border:1px solid #e5e8f0;border-radius:9px;'
                + 'padding:8px 13px;min-width:120px;">'
                + '<div style="font-size:15px;font-weight:700;color:#22366F;">'
                + materiales[m].toLocaleString('es-VE') + '</div>'
                + '<div style="font-size:11px;color:#5b6478;">' + m + '</div></div>';
        });
        html += '</div>'
            + '<div style="font-size:11.5px;color:#767c94;margin-top:8px;">'
            + 'Cálculo aproximado según los metros registrados. '
            + 'Verifique en obra antes de solicitar.</div></div>';
    } else if (tipos.length) {
        html += '<div style="border-top:1px solid #eef0f5;padding-top:11px;font-size:12.5px;color:#5b6478;">'
            + 'No hay recetas de materiales definidas para estos tipos de superficie.</div>';
    }

    cont.innerHTML = html;
}

/** Fotos generales del edificio: fachada, etiqueta, azotea, tanques. */
function pintarFotosEdificio(fotos) {
    const caja = document.getElementById('fs-fotos-edificio');
    const cont = document.getElementById('fs-fotos-ed-lista');
    const num  = document.getElementById('fs-nfotos-ed');
    if (!caja || !cont) return;

    if (!fotos.length) { caja.style.display = 'none'; return; }

    caja.style.display = '';
    if (num) num.textContent = fotos.length;
    cont.innerHTML = fotos.map(f =>
        fotoHTML(f.ruta, f.descripcion || f.parte || 'Edificio',
                 f.parte || '', f.fecha || '')
    ).join('');
}

function pintarBarraGlobal(pct) {
    const bg = document.getElementById('barra-global');
    bg.style.width = pct + '%';
    bg.textContent = pct + '%';
}

function colorPct(p) {
    if (p >= 100) return '#2E7D32';
    if (p > 0)    return '#a8871f';
    return '#5b6478';
}

// Texto claro del avance, para quien no interpreta bien los porcentajes.
function textoPct(p) {
    if (p >= 100) return 'Terminado';
    if (p >= 75)  return 'Casi listo';
    if (p >= 25)  return 'En proceso';
    if (p > 0)    return 'Comenzado';
    return 'Sin comenzar';
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
                ${piso.m2 ? `<span style="font-size:12px;color:#5b6478;background:#f1f3f8;
                    border-radius:7px;padding:3px 9px;font-weight:600;">${piso.m2} m²</span>` : ''}
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

    // Fotos de los elementos del piso (escaleras, pasillos…), si las hay.
    const pisoDatos = (_arbol.pisos || []).find(p => p.piso_id === pisoId);
    if (pisoDatos && (pisoDatos.fotos_elementos || []).length && cont && !cont.dataset.fotosPuestas) {
        cont.dataset.fotosPuestas = '1';
        const fe = pisoDatos.fotos_elementos;
        const bloque = document.createElement('div');
        bloque.style.cssText = 'background:#f7f9fd;border-radius:9px;padding:11px 13px;margin-bottom:11px;';
        bloque.innerHTML =
            '<div style="font-size:11.5px;text-transform:uppercase;color:#55617f;'
            + 'font-weight:700;letter-spacing:.4px;margin-bottom:7px;">'
            + '<i class="bi bi-images"></i> Fotos del piso (' + fe.length + ')</div>'
            + '<div class="fotos-fila">'
            + fe.map(f => fotoHTML(f.ruta, f.elemento || 'Elemento',
                                   f.parte || '', f.fecha || '')).join('')
            + '</div>';
        cont.appendChild(bloque);
    }
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
    if (!piso) return;

    // Fotos de los elementos del piso (escaleras, pasillos, fachada…).
    // Antes no se mostraban en ningún sitio de la ficha.
    let cabecera = '';
    if (piso.fotos_elementos && piso.fotos_elementos.length) {
        cabecera = '<div style="background:#f7f9fd;border-radius:9px;padding:11px 13px;margin-bottom:11px;">'
            + '<div style="font-size:11.5px;text-transform:uppercase;color:#55617f;'
            + 'font-weight:700;letter-spacing:.3px;margin-bottom:8px;">'
            + '<i class="bi bi-images"></i> Fotos del piso ('
            + piso.fotos_elementos.length + ')</div>'
            + '<div class="fotos-fila">'
            + piso.fotos_elementos.map(f =>
                fotoHTML(f.ruta, f.elemento || f.descripcion || 'Piso',
                         f.parte || '', f.fecha || '')).join('')
            + '</div></div>';
    }

    if (!piso.apartamentos.length) {
        cont.innerHTML = cabecera
            + '<div style="color:#9aa1b4;padding:8px 0;">Sin apartamentos en este piso.</div>';
        return;
    }
    cont.innerHTML = piso.apartamentos.map(ap => filaApartamento(ap, pisoId)).join('');
}

function filaApartamento(ap, pisoId) {
    const jefe = ap.jefe_nombre
        ? `<div style="font-size:11px;color:#767c94;">${ap.jefe_nombre}${ap.jefe_telefono ? ' · ' + ap.jefe_telefono : ''}</div>`
        : '';
    const nAmb = (ap.ambientes || []).length;
    return `
        <div class="fs-apto-bloque" style="border:1px solid #eef0f5;border-radius:10px;margin-bottom:8px;overflow:hidden;">
            <div class="fs-apto-fila" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#fafbfe;cursor:pointer;"
                 onclick="toggleApto(${ap.id})">
                <i class="bi bi-chevron-right" id="chev-apto-${ap.id}" style="transition:transform .2s;color:#97a0b8;"></i>
                <div class="fs-apto-info" style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#2a3140;font-size:13px;">
                        <i class="bi bi-door-open"></i> Apartamento ${ap.identificador}
                    </div>
                    ${jefe}
                </div>
                <span style="font-size:11px;color:#97a0b8;">${nAmb} ambiente(s)</span>
                ${ap.m2 ? `<span style="font-size:11.5px;color:#5b6478;background:#f1f3f8;
                    border-radius:7px;padding:2px 8px;font-weight:600;">${ap.m2} m²</span>` : ''}
                <div style="width:110px;background:#eef0f6;border-radius:20px;height:14px;overflow:hidden;">
                    <div id="barra-apto-${ap.id}" style="width:${ap.avance}%;background:${colorPct(ap.avance)};height:100%;transition:width .3s;"></div>
                </div>
                <span style="font-weight:800;color:${colorPct(ap.avance)};min-width:44px;text-align:right;"
                      id="pct-apto-${ap.id}">${ap.avance}%</span>
            </div>
            <div class="fs-amb-lista hidden" id="amb-lista-${ap.id}" style="padding:4px 12px 10px;"></div>
        </div>`;
}

// Despliega los ambientes de un apartamento.
function toggleApto(aptoId) {
    const cont = document.getElementById('amb-lista-' + aptoId);
    const chev = document.getElementById('chev-apto-' + aptoId);
    if (!cont) return;
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

    let ap = null, pisoId = null;
    (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a => {
        if (a.id === aptoId) { ap = a; pisoId = p.piso_id; }
    }));
    if (!ap) return;
    if (!ap.ambientes || !ap.ambientes.length) {
        // Sin ambientes registrados: se permite cargar el avance del apartamento
        // completo, para que el sistematizador no quede bloqueado en campo.
        cont.innerHTML = filaApartamentoSinAmbientes(ap, pisoId);
        return;
    }
    cont.innerHTML = ap.ambientes.map(am => filaAmbiente(am, aptoId, pisoId)).join('');
}

// Respaldo: apartamento sin ambientes registrados. Permite cargar igual,
// tomando la foto y el avance a nivel del apartamento completo.
function filaApartamentoSinAmbientes(ap, pisoId) {
    let control;
    if (!PUEDE_CARGAR) {
        control = `<div style="width:130px;background:#eef0f6;border-radius:20px;height:12px;overflow:hidden;">
                     <div style="width:${ap.avance}%;background:${colorPct(ap.avance)};height:100%;"></div>
                   </div>`;
    } else if (ap.tiene_foto_durante) {
        control = `<input type="range" min="0" max="100" step="5" value="${ap.avance}" style="width:150px;"
                      oninput="document.getElementById('pct-apto-${ap.id}').textContent=this.value+'%';document.getElementById('txt-apto-sa-${ap.id}').textContent=textoPct(+this.value)"
                      onchange="guardarAvance(${ap.id}, this.value, ${pisoId})">`;
    } else {
        control = `<button type="button" class="btn btn-outline btn-sm" onclick="subirDurante(${ap.id})">
                     <i class="bi bi-camera"></i> Subir foto del durante
                   </button>`;
    }
    return `
        <div style="background:#fffbf0;border:1px solid #C9A22744;border-radius:9px;padding:11px 13px;">
            <div style="font-size:12.5px;color:#8a6d1a;margin-bottom:9px;">
                <i class="bi bi-info-circle-fill"></i>
                Este apartamento no tiene ambientes detallados en el levantamiento.
                Puede registrar el avance del apartamento completo.
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:140px;font-size:14px;font-weight:600;color:#2a3140;">
                    Avance del apartamento
                </div>
                ${control}
                <div style="min-width:78px;text-align:right;">
                    <div style="font-weight:800;font-size:15px;color:${colorPct(ap.avance)};">${ap.avance}%</div>
                    <div style="font-size:11.5px;color:#5b6478;" id="txt-apto-sa-${ap.id}">${textoPct(ap.avance)}</div>
                </div>
            </div>
        </div>`;
}

// Fila de un ambiente: foto del ANTES + foto del DURANTE + % de avance.
function filaAmbiente(am, aptoId, pisoId) {
    const rep = am.necesita_reparacion
        ? '<span style="background:#A61C1C18;color:#A61C1C;font-size:10px;padding:1px 6px;border-radius:10px;">Requiere reparación</span>'
        : '';

    // La foto del ANTES siempre visible (viene del levantamiento).
    const antes = am.fotos_antes > 0
        ? `<button type="button" class="btn-foto-mini" onclick="verFotosAmbiente(${am.id}, '${am.etiqueta}', 'antes')">
             <i class="bi bi-image"></i> Antes (${am.fotos_antes})
           </button>`
        : '<span style="font-size:11px;color:#c4c9d6;"><i class="bi bi-image"></i> Sin foto del antes</span>';

    const durante = am.fotos_durante > 0
        ? `<button type="button" class="btn-foto-mini" style="border-color:#2E7D3255;color:#2E7D32;" onclick="verFotosAmbiente(${am.id}, '${am.etiqueta}', 'durante')">
             <i class="bi bi-camera-fill"></i> Durante (${am.fotos_durante})
           </button>`
        : '';

    // Control de avance: exige foto del durante.
    let control;
    if (!PUEDE_CARGAR) {
        control = `<div style="width:120px;background:#eef0f6;border-radius:20px;height:12px;overflow:hidden;">
                     <div style="width:${am.avance}%;background:${colorPct(am.avance)};height:100%;"></div>
                   </div>`;
    } else if (am.tiene_foto_durante) {
        control = `<input type="range" min="0" max="100" step="5" value="${am.avance}" style="width:140px;"
                      oninput="document.getElementById('pct-amb-${am.id}').textContent=this.value+'%';document.getElementById('txt-amb-${am.id}').textContent=textoPct(+this.value)"
                      onchange="guardarAvanceAmbiente(${am.id}, this.value, ${aptoId}, ${pisoId})">`;
    } else {
        control = `<button type="button" class="btn btn-outline btn-sm" onclick="subirDuranteAmbiente(${am.id})">
                     <i class="bi bi-camera"></i> Subir foto del durante
                   </button>`;
    }

    return `
        <div class="fs-amb-fila" style="display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #f4f6fa;">
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;color:#2a3140;font-weight:700;">${am.etiqueta} ${rep}</div>
                <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;align-items:center;">${antes}${durante}<span id="estado-amb-${am.id}"></span></div>
            </div>
            ${control}
            <div style="min-width:78px;text-align:right;">
                <div style="font-weight:800;color:${colorPct(am.avance)};font-size:15px;"
                     id="pct-amb-${am.id}">${am.avance}%</div>
                <div style="font-size:11.5px;color:#5b6478;" id="txt-amb-${am.id}">${textoPct(am.avance)}</div>
            </div>
        </div>`;
}

// Guarda el % de un ambiente y sube el promedio al apartamento/piso/edificio.
async function guardarAvanceAmbiente(ambienteId, valor, aptoId, pisoId) {
    const pct = parseInt(valor);
    const lbl = document.getElementById('pct-amb-' + ambienteId);
    if (lbl) { lbl.textContent = pct + '%'; lbl.style.color = colorPct(pct); }
    const txt = document.getElementById('txt-amb-' + ambienteId);
    if (txt) txt.textContent = textoPct(pct);

    // Actualizar en memoria
    let ap = null;
    (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a => {
        if (a.id === aptoId) { ap = a; }
    }));
    if (ap) {
        const am = (ap.ambientes || []).find(x => x.id === ambienteId);
        if (am) am.avance = pct;
        // % del apartamento = promedio de sus ambientes
        if (ap.ambientes && ap.ambientes.length) {
            const suma = ap.ambientes.reduce((s, x) => s + x.avance, 0);
            ap.avance = Math.round(suma / ap.ambientes.length);
        }
        const lblAp = document.getElementById('pct-apto-' + aptoId);
        const barAp = document.getElementById('barra-apto-' + aptoId);
        if (lblAp) { lblAp.textContent = ap.avance + '%'; lblAp.style.color = colorPct(ap.avance); }
        if (barAp) { barAp.style.width = ap.avance + '%'; barAp.style.background = colorPct(ap.avance); }
    }
    recalcularEnPantalla(pisoId);

    // Sin señal: guardar en el teléfono y avisar que subirá después.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance',
            URL_BASE + 'guardar_avance_ambiente.php',
            { ambiente_id: ambienteId, porcentaje: pct, edificio_id: EDIFICIO_ID },
            'Avance de ambiente #' + ambienteId + ' → ' + pct + '%');
        marcarGuardando(ambienteId, 'pendiente');
        return;
    }

    // Aviso visual mientras guarda: el sistematizador debe SABER si quedo guardado.
    marcarGuardando(ambienteId, 'guardando');
    try {
        const res = await fetch(URL_BASE + 'guardar_avance_ambiente.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ ambiente_id: ambienteId, porcentaje: pct, edificio_id: EDIFICIO_ID })
        });
        const d = await res.json();
        if (d.sesion_expirada) { marcarGuardando(ambienteId, 'error'); alert(d.mensaje); return; }
        if (!d.ok) {
            marcarGuardando(ambienteId, 'error');
            alert(d.mensaje || 'No se pudo guardar el avance.');
            return;
        }
        marcarGuardando(ambienteId, 'ok');
    } catch (e) {
        // Se cayó la señal a mitad: no se pierde, queda en cola.
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance',
                URL_BASE + 'guardar_avance_ambiente.php',
                { ambiente_id: ambienteId, porcentaje: pct, edificio_id: EDIFICIO_ID },
                'Avance de ambiente #' + ambienteId + ' → ' + pct + '%');
            marcarGuardando(ambienteId, 'pendiente');
        } else {
            marcarGuardando(ambienteId, 'error');
            alert('No hay conexión.\n\nEl avance NO se guardó. Verifique su señal e intente de nuevo.');
        }
    }
}

// La foto quedó en el teléfono: se habilita igual la barra de avance,
// para que el sistematizador no quede bloqueado esperando señal.
function marcarFotoPendiente(nivel, refId) {
    if (!_arbol) return;
    let aptoId = null, pisoId = null;
    if (nivel === 'ambiente') {
        (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a =>
            (a.ambientes || []).forEach(am => {
                if (am.id === refId) {
                    am.tiene_foto_durante = true;
                    am.fotos_durante = (am.fotos_durante || 0) + 1;
                    aptoId = a.id; pisoId = p.piso_id;
                }
            })));
        if (aptoId) {
            const cont = document.getElementById('amb-lista-' + aptoId);
            let ap = null;
            (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a => { if (a.id === aptoId) ap = a; }));
            if (cont && ap) cont.innerHTML = ap.ambientes.map(am => filaAmbiente(am, aptoId, pisoId)).join('');
        }
    } else {
        (_arbol.pisos || []).forEach(p => p.apartamentos.forEach(a => {
            if (a.id === refId) { a.tiene_foto_durante = true; pisoId = p.piso_id; }
        }));
        if (pisoId) {
            const cont = document.getElementById('piso-aptos-' + pisoId);
            const piso = _arbol.pisos.find(p => p.piso_id === pisoId);
            if (cont && piso) cont.innerHTML = piso.apartamentos.map(a => filaApartamento(a, pisoId)).join('');
        }
    }
    cerrarModalFotos();
}

// Capa a pantalla completa mientras sube una foto (evita toques dobles).
function mostrarSubiendo(activo) {
    let capa = document.getElementById('fs-subiendo');
    if (activo) {
        if (!capa) {
            capa = document.createElement('div');
            capa.id = 'fs-subiendo';
            capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.55);z-index:2000;'
                + 'display:flex;align-items:center;justify-content:center;';
            capa.innerHTML = '<div style="background:#fff;border-radius:12px;padding:22px 28px;text-align:center;max-width:280px;">'
                + '<div style="font-size:34px;color:#22366F;"><i class="bi bi-cloud-arrow-up-fill"></i></div>'
                + '<div style="font-weight:700;color:#22366F;margin-top:6px;font-size:16px;">Subiendo la foto…</div>'
                + '<div style="font-size:13px;color:#5b6478;margin-top:4px;">Espere un momento. No cierre esta pantalla.</div>'
                + '</div>';
            document.body.appendChild(capa);
        }
        capa.style.display = 'flex';
    } else if (capa) {
        capa.style.display = 'none';
    }
}

// Indicador de guardado junto al ambiente.
function marcarGuardando(ambienteId, estado) {
    const cont = document.getElementById('estado-amb-' + ambienteId);
    if (!cont) return;
    if (estado === 'guardando') {
        cont.innerHTML = '<span style="color:#5b6478;font-size:11.5px;"><i class="bi bi-arrow-repeat"></i> Guardando…</span>';
    } else if (estado === 'ok') {
        cont.innerHTML = '<span style="color:#2E7D32;font-size:11.5px;font-weight:600;"><i class="bi bi-check-circle-fill"></i> Guardado</span>';
        setTimeout(() => { if (cont) cont.innerHTML = ''; }, 2500);
    } else if (estado === 'pendiente') {
        cont.innerHTML = '<span style="color:#8a6d1a;font-size:11.5px;font-weight:700;"><i class="bi bi-phone-fill"></i> Guardado en el teléfono</span>';
    } else {
        cont.innerHTML = '<span style="color:#A61C1C;font-size:11.5px;font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> NO se guardó</span>';
    }
}

// Subir foto del "durante" de un ambiente.
function subirDuranteAmbiente(ambienteId) {
    elegirOrigenDurante({ nivel: 'ambiente', id: ambienteId });
}

// Ver fotos de un ambiente (antes o durante).
async function verFotosAmbiente(ambienteId, etiqueta, parte) {
    const cont = document.getElementById('fs-modal-fotos');
    const cuerpo = document.getElementById('fs-modal-body');
    document.getElementById('fs-modal-tit').textContent = etiqueta + ' · ' + (parte === 'antes' ? 'Antes' : 'Durante');
    cuerpo.innerHTML = '<p class="text-muted">Cargando…</p>';
    cont.style.display = 'flex';
    try {
        const res = await fetch(URL_BASE + 'listar_fotos_ambiente.php?ambiente=' + ambienteId);
        const d = await res.json();
        if (!d.ok) { cuerpo.innerHTML = '<p class="text-muted">No se pudieron cargar.</p>'; return; }
        // Todo lo que no sea "durante" pertenece al estado inicial: las
        // fotos del levantamiento se guardan con la parte física
        // (Pared, Techo, Piso…), no con la palabra "antes".
        const lista = (d.fotos || []).filter(f => {
            const p = (f.parte || '').toLowerCase();
            return parte === 'durante' ? p === 'durante' : p !== 'durante';
        });
        if (!lista.length) { cuerpo.innerHTML = '<p class="text-muted">Sin fotos registradas.</p>'; return; }
        cuerpo.innerHTML = `<div class="fotos-fila">${lista.map(f =>
            fotoHTML(f.ruta, f.descripcion || etiqueta, f.parte_detalle || '', f.fecha || '')
        ).join('')}</div>`;
    } catch (e) {
        cuerpo.innerHTML = '<p class="text-muted">Error al cargar.</p>';
    }
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
    pintarMetrosTotal(_arbol.m2_total || 0, _arbol.m2_comunes || 0,
                      _arbol.m2_por_tipo || {}, _arbol.materiales || {},
                      _arbol.por_trabajo || {});
    pintarFotosEdificio(_arbol.fotos_edificio || []);
    pintarFotosEdificio(_arbol.fotos_edificio || []);
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
        (ap.fotos_antes || []).forEach(f => { antes += fotoHTML(f.ruta, 'Apartamento', f.parte_detalle || '', f.fecha || ''); });
        (ap.ambientes || []).forEach(am => (am.fotos_antes || []).forEach(f => {
            antes += fotoHTML(f.ruta, am.tipo + ' ' + am.numero, f.parte_detalle || '', f.fecha || ''); }));
        if (!antes) antes = '<div style="color:#9aa1b4;font-size:12px;">Sin fotos del levantamiento</div>';

        let durante = '';
        (ap.fotos_durante || []).forEach(f => { durante += fotoHTML(f.ruta, 'Durante', f.parte_detalle || '', f.fecha || ''); });
        (ap.ambientes || []).forEach(am => (am.fotos_durante || []).forEach(f => {
            durante += fotoHTML(f.ruta, am.tipo + ' ' + am.numero, f.parte_detalle || '', f.fecha || ''); }));
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

/**
 * Miniatura de una foto. Muestra qué parte se fotografió y permite
 * ampliarla: antes no se veía el detalle ni qué lado era.
 */
function fotoHTML(ruta, cap, parte, fecha) {
    const etiqueta = parte
        ? `<span style="background:#22366F;color:#fff;font-size:10px;font-weight:700;
             padding:2px 7px;border-radius:9px;position:absolute;top:6px;left:6px;">${parte}</span>`
        : '';
    const cuando = fecha
        ? `<div style="font-size:10px;color:#767c94;">${fecha}</div>` : '';
    const alt = (cap || 'Foto').replace(/"/g, '&quot;');
    return `<div class="foto-item" style="position:relative;">
        <img src="${ruta}" alt="${alt}" style="cursor:zoom-in;"
             onclick="ampliarFoto('${ruta}', '${alt}', '${(parte||'').replace(/'/g,"")}')">
        ${etiqueta}
        <div class="cap">${cap || ''}</div>
        ${cuando}
    </div>`;
}

/** Visor a pantalla completa, con zoom y desplazamiento. */
function ampliarFoto(ruta, titulo, parte) {
    const capa = document.createElement('div');
    capa.id = 'visor-foto';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(10,14,24,.94);z-index:3000;'
        + 'display:flex;flex-direction:column;';

    capa.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;
                    background:rgba(0,0,0,.35);color:#fff;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:15px;">${titulo || 'Foto'}</div>
                ${parte ? `<div style="font-size:12px;opacity:.8;">Parte: ${parte}</div>` : ''}
            </div>
            <button onclick="zoomFoto(-1)" title="Alejar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:19px;cursor:pointer;">−</button>
            <button onclick="zoomFoto(1)" title="Acercar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:19px;cursor:pointer;">+</button>
            <a href="${ruta}" download title="Descargar"
               style="background:rgba(255,255,255,.15);color:#fff;width:38px;height:38px;
                      border-radius:9px;display:flex;align-items:center;justify-content:center;
                      text-decoration:none;"><i class="bi bi-download"></i></a>
            <button onclick="cerrarVisor()" title="Cerrar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="visor-cont" style="flex:1;overflow:auto;display:flex;align-items:center;
                                    justify-content:center;padding:14px;">
            <img id="visor-img" src="${ruta}" style="max-width:100%;max-height:100%;
                 transition:transform .18s;transform-origin:center;">
        </div>
        <div style="padding:9px 16px;background:rgba(0,0,0,.35);color:#ffffffaa;
                    font-size:12px;text-align:center;flex-shrink:0;">
            Toque + o − para acercar · Toque fuera de la imagen para cerrar
        </div>`;

    capa.addEventListener('click', e => {
        if (e.target === capa || e.target.id === 'visor-cont') cerrarVisor();
    });
    document.body.appendChild(capa);
    window._zoomFoto = 1;

    // Cerrar con la tecla Escape.
    document.addEventListener('keydown', _escVisor);
}

function _escVisor(e) { if (e.key === 'Escape') cerrarVisor(); }

function cerrarVisor() {
    const v = document.getElementById('visor-foto');
    if (v) v.remove();
    document.removeEventListener('keydown', _escVisor);
}

function zoomFoto(dir) {
    const img = document.getElementById('visor-img');
    if (!img) return;
    window._zoomFoto = Math.min(4, Math.max(0.5, (window._zoomFoto || 1) + dir * 0.35));
    img.style.transform = 'scale(' + window._zoomFoto + ')';
    img.style.cursor = window._zoomFoto > 1 ? 'grab' : 'zoom-in';
}

function subirDurante(aptoId) {
    elegirOrigenDurante({ nivel: 'apartamento', id: aptoId });
}

/** Pregunta si toma la foto o la elige de la galería. */
function elegirOrigenDurante(destino) {
    _duranteDestino = destino;
    const capa = document.createElement('div');
    capa.id = 'origen-durante';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2300;'
        + 'display:flex;align-items:flex-end;justify-content:center;';
    capa.innerHTML =
        '<div style="background:#fff;border-radius:14px 14px 0 0;width:100%;max-width:440px;padding:18px 18px 22px;">'
        + '<div style="font-weight:700;color:#22366F;font-size:16px;margin-bottom:14px;text-align:center;">'
        + '¿Cómo quiere agregar la foto?</div>'
        + '<button type="button" onclick="_camaraDurante()" '
        + 'style="width:100%;display:flex;align-items:center;gap:12px;background:#22366F;color:#fff;'
        + 'border:0;border-radius:10px;padding:14px 16px;font-size:15px;font-weight:600;'
        + 'cursor:pointer;margin-bottom:10px;">'
        + '<i class="bi bi-camera-fill" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Tomar foto ahora<br>'
        + '<span style="font-size:12px;font-weight:400;opacity:.85;">Se guarda en su teléfono</span></span></button>'
        + '<button type="button" onclick="_galeriaDurante()" '
        + 'style="width:100%;display:flex;align-items:center;gap:12px;background:#fff;color:#22366F;'
        + 'border:2px solid #dbe0ec;border-radius:10px;padding:14px 16px;font-size:15px;font-weight:600;'
        + 'cursor:pointer;margin-bottom:10px;">'
        + '<i class="bi bi-images" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Elegir de la galería<br>'
        + '<span style="font-size:12px;font-weight:400;color:#5b6478;">Una foto que ya tomó</span></span></button>'
        + '<button type="button" onclick="_cerrarOrigenDurante()" '
        + 'style="width:100%;background:transparent;border:0;color:#5b6478;padding:10px;'
        + 'font-size:14px;cursor:pointer;">Cancelar</button>'
        + '</div>';
    document.body.appendChild(capa);
}
function _cerrarOrigenDurante() {
    const c = document.getElementById('origen-durante');
    if (c) c.remove();
}
function _camaraDurante() {
    _cerrarOrigenDurante();
    document.getElementById('fs-file-camara').click();
}
function _galeriaDurante() {
    _cerrarOrigenDurante();
    document.getElementById('fs-file-galeria').click();
}

async function _onDuranteElegida(input, desdeCamara) {
    if (!input.files || !input.files[0] || !_duranteDestino) { input.value=''; return; }
    const destino = _duranteDestino;
    const nivel = destino.nivel || 'apartamento';
    const refId = destino.id || destino;
    const archivo = input.files[0];

    // RESPALDO INMEDIATO: la foto se guarda en el teléfono ANTES de
    // intentar subirla. Si la subida falla, no se pierde la evidencia.
    let idLocal = null;
    if (window.ObrasFotos) {
        idLocal = await ObrasFotos.respaldar(archivo, {
            inspeccion_id: INSPECCION_ID, nivel: nivel, ref_id: refId,
            parte: 'durante', origen: desdeCamara ? 'camara' : 'galeria',
            descripcion: 'Durante · ' + nivel + ' #' + refId,
        });
    }

    const fd = new FormData();
    fd.append('nivel', nivel);
    fd.append('ref_id', refId);
    fd.append('parte', 'durante');
    fd.append('foto', archivo);
    input.value = '';

    // Sin señal: la foto se guarda en el teléfono y sube después.
    if (window.ObrasOffline && !navigator.onLine) {
        const archivo = fd.get('foto');
        await ObrasOffline.encolar('foto',
            URL_BASE + 'subir_foto_rec.php',
            { nivel: nivel, ref_id: refId, parte: 'durante',
              foto: archivo, nombre_archivo: archivo && archivo.name || 'foto.jpg' },
            'Foto de ' + nivel + ' #' + refId);
        // Marcar en pantalla para que pueda seguir trabajando.
        marcarFotoPendiente(nivel, refId);
        alert('Sin señal: la foto quedó guardada en el teléfono.\n\nSe subirá sola cuando vuelva la conexión.');
        _duranteDestino = null;
        return;
    }

    // Aviso de subida: las fotos pesan y en campo la señal es lenta.
    mostrarSubiendo(true);
    let data;
    try {
        const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
        data = await res.json();
    } catch (e) {
        mostrarSubiendo(false);
        if (window.ObrasOffline) {
            const archivo = fd.get('foto');
            await ObrasOffline.encolar('foto',
                URL_BASE + 'subir_foto_rec.php',
                { nivel: nivel, ref_id: refId, parte: 'durante',
                  foto: archivo, nombre_archivo: archivo && archivo.name || 'foto.jpg' },
                'Foto de ' + nivel + ' #' + refId);
            marcarFotoPendiente(nivel, refId);
            alert('Se perdió la señal: la foto quedó guardada en el teléfono.\n\nSe subirá sola al recuperar la conexión.');
        } else {
            alert('No se pudo subir la foto: sin conexión.\n\nLa foto NO se guardó. Intente de nuevo.');
        }
        _duranteDestino = null;
        return;
    }
    mostrarSubiendo(false);
    if (data.sesion_expirada) { alert(data.mensaje); _duranteDestino = null; return; }
    if (!data.ok) { alert(data.mensaje || 'No se pudo subir.'); _duranteDestino = null; return; }

    // Subió bien: la copia local ya no es urgente.
    if (idLocal && window.ObrasFotos) ObrasFotos.marcarSubida(idLocal);

    // Marcar que ya tiene foto y redibujar la parte afectada.
    if (nivel === 'ambiente') {
        let aptoId = null, pisoId = null;
        (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a =>
            (a.ambientes || []).forEach(am => {
                if (am.id === refId) {
                    am.tiene_foto_durante = true;
                    am.fotos_durante = (am.fotos_durante || 0) + 1;
                    aptoId = a.id; pisoId = p.piso_id;
                }
            })));
        if (aptoId) {
            const cont = document.getElementById('amb-lista-' + aptoId);
            let ap = null;
            (_arbol.pisos || []).forEach(p => (p.apartamentos || []).forEach(a => { if (a.id === aptoId) ap = a; }));
            if (cont && ap) cont.innerHTML = ap.ambientes.map(am => filaAmbiente(am, aptoId, pisoId)).join('');
        }
    } else {
        let pisoAfectado = null;
        (_arbol.pisos || []).forEach(p => p.apartamentos.forEach(a => {
            if (a.id === refId) { a.tiene_foto_durante = true; pisoAfectado = p.piso_id; }
        }));
        if (pisoAfectado) {
            const cont = document.getElementById('piso-aptos-' + pisoAfectado);
            const piso = _arbol.pisos.find(p => p.piso_id === pisoAfectado);
            if (cont && piso) cont.innerHTML = piso.apartamentos.map(a => filaApartamento(a, pisoAfectado)).join('');
        }
    }
    cerrarModalFotos();
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
