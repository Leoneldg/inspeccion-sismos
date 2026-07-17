<?php
/**
 * Levantamiento técnico del edificio (wizard por pasos).
 * Flujo pensado para el recorrido físico real del inspector:
 *   Paso 1: datos del edificio (pisos, aptos por piso, áreas comunes).
 *   Paso 2: recorrido PISO POR PISO. En cada piso: elementos comunes
 *           (ascensor, escaleras…) + los apartamentos de ese piso.
 *   Paso 3: cierre (azotea, tanques, impermeabilización) + resumen final.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeEditar = puede('seguimiento', 'editar');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) {
    flash('error', 'Edificio no especificado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp) {
    flash('error', 'El edificio no existe.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$ed = recEdificio($inspeccionId);
$pisos = recPisos((int)$ed['id']);
$tiposElem = recTiposElementoPiso();

$pageTitle    = 'Levantamiento: ' . $insp['nombre_edificio'];
$pageSubtitle = trim(($insp['parroquia'] ?? '') . ' · ' . ($insp['municipio'] ?? ''), ' ·');
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>

<style>
    .wz-wrap { max-width:920px; margin:0 auto; }
    .wz-steps { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
    .wz-step { flex:1; min-width:120px; padding:10px 14px; border-radius:10px; background:#f2f5fc;
               border:1px solid #e6e9f2; color:#767c94; font-size:13px; cursor:pointer; }
    .wz-step.activo { background:#eaf0ff; border-color:#22366F; color:#22366F; font-weight:600; }
    .wz-step.hecho { background:#e5f7ee; color:#2E7D32; }
    .wz-step .n { display:inline-block; width:22px; height:22px; border-radius:50%; background:#fff;
                  text-align:center; line-height:22px; font-weight:700; margin-right:6px; font-size:12px; }
    .wz-panel { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:22px 24px; }
    .wz-panel h3 { margin:0 0 4px; color:#22366F; font-size:16px; }
    .wz-panel .sub { color:#767c94; font-size:13px; margin:0 0 18px; }
    .hidden { display:none; }
    .campo-estado { margin:12px 0; }
    .campo-estado .tit { display:block; font-weight:600; font-size:13px; color:#2a3140; margin-bottom:5px; }
    .seg-radio { display:inline-flex; align-items:center; gap:5px; margin-right:14px; font-size:13px; color:#2a3140; cursor:pointer; }
    /* Piso: cada uno es una sección grande y colapsable */
    .piso-card { border:1px solid #d9e0ee; border-radius:12px; margin-bottom:14px; overflow:hidden; }
    .piso-head { padding:14px 18px; background:#22366F; color:#fff; cursor:pointer; font-weight:600;
                 display:flex; align-items:center; justify-content:space-between; }
    .piso-head .estado { font-size:11px; font-weight:500; opacity:.85; }
    .piso-body { padding:16px 18px; }
    .bloque-tit { font-size:13px; font-weight:700; color:#22366F; margin:6px 0 10px; text-transform:uppercase; letter-spacing:.4px; }
    .elem-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:8px 0; border-bottom:1px solid #f0f2f7; }
    .elem-nom { font-weight:600; color:#2a3140; min-width:130px; }
    .apto-card { border:1px solid #e6e9f2; border-radius:10px; margin:10px 0; overflow:hidden; }
    .apto-head { padding:10px 14px; background:#f2f5fc; cursor:pointer; font-weight:600; color:#22366F; }
    .apto-body { padding:12px 14px; }
    .subiendo { font-size:12px; color:#767c94; }
</style>

<div class="wz-wrap">

<!-- Indicador de pasos -->
<div class="wz-steps">
    <div class="wz-step <?= $ed['completado'] ? 'hecho' : 'activo' ?>" id="tab-1" onclick="irPaso(1)">
        <span class="n">1</span> Datos del edificio
    </div>
    <div class="wz-step <?= $ed['completado'] ? 'activo' : '' ?>" id="tab-2" onclick="irPaso(2)">
        <span class="n">2</span> Recorrido piso por piso
    </div>
    <div class="wz-step" id="tab-3" onclick="irPaso(3)">
        <span class="n">3</span> Cierre (azotea y resumen)
    </div>
</div>

<!-- ============ PASO 1: DATOS DEL EDIFICIO ============ -->
<div class="wz-panel" id="paso-1">
    <h3>Datos del edificio</h3>
    <p class="sub">Al llegar, corrobore la información básica. Esto genera los pisos que va a recorrer.</p>
    <form id="form-edificio" onsubmit="return guardarEdificio(event)">
        <div class="flex gap-8" style="flex-wrap:wrap;">
            <div class="field" style="flex:1;min-width:180px;">
                <label class="text-sm">Cantidad de pisos *</label>
                <input type="number" id="num_pisos" class="form-control" min="1" max="200"
                       value="<?= $ed['num_pisos'] !== null ? (int)$ed['num_pisos'] : '' ?>" required>
            </div>
            <div class="field" style="flex:1;min-width:180px;">
                <label class="text-sm">Apartamentos por piso</label>
                <input type="number" id="aptos_por_piso" class="form-control" min="0" max="100"
                       value="<?= $ed['aptos_por_piso'] !== null ? (int)$ed['aptos_por_piso'] : '' ?>">
            </div>
        </div>

        <div class="campo-estado">
            <label class="tit">¿Tiene áreas comunes generales?</label>
            <label class="seg-radio"><input type="checkbox" id="tiene_areas_comunes" <?= $ed['tiene_areas_comunes'] ? 'checked' : '' ?>> Sí</label>
        </div>
        <div class="field" id="wrap-areas" style="<?= $ed['tiene_areas_comunes'] ? '' : 'display:none;' ?>">
            <label class="text-sm">¿Cuáles?</label>
            <input type="text" id="areas_comunes_desc" class="form-control" value="<?= e($ed['areas_comunes_desc'] ?? '') ?>" placeholder="Ej: lobby, estacionamiento, salón de fiestas">
        </div>

        <?php if ($puedeEditar): ?>
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right"></i> Guardar y empezar el recorrido</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ============ PASO 2: RECORRIDO PISO POR PISO ============ -->
<div class="wz-panel hidden" id="paso-2">
    <h3>Recorrido piso por piso</h3>
    <p class="sub">Suba piso por piso. En cada uno registre lo común (ascensor, escaleras…) y luego entre a sus apartamentos.</p>

    <?php if (!$pisos): ?>
        <p class="text-muted">Primero complete los datos del edificio (Paso 1) para generar los pisos.</p>
    <?php else: ?>
        <?php foreach ($pisos as $piso):
            $elems = recElementosPiso((int)$piso['id']);
        ?>
        <div class="piso-card" data-piso="<?= (int)$piso['id'] ?>">
            <div class="piso-head" onclick="togglePiso(this)">
                <span><i class="bi bi-building"></i> Piso <?= (int)$piso['numero_piso'] ?></span>
                <span class="estado"><i class="bi bi-chevron-down"></i></span>
            </div>
            <div class="piso-body hidden">

                <!-- Áreas comunes del piso -->
                <div class="bloque-tit"><i class="bi bi-diagram-3"></i> Áreas comunes del piso</div>
                <label class="seg-radio"><input type="checkbox" class="piso-areas" <?= $piso['tiene_areas_comunes'] ? 'checked' : '' ?>> Este piso tiene áreas comunes</label>
                <input type="text" class="form-control piso-areas-desc" placeholder="¿Cuáles?" value="<?= e($piso['areas_comunes_desc'] ?? '') ?>" style="margin-top:6px;">

                <!-- Elementos comunes del piso -->
                <div class="bloque-tit" style="margin-top:16px;"><i class="bi bi-gear"></i> Elementos del piso</div>
                <?php foreach ($tiposElem as $tk => $tlbl):
                    $el = $elems[$tk] ?? null;
                ?>
                <div class="elem-row" data-tipo="<?= $tk ?>" <?= ($el && $el['id']) ? 'data-elem-id="'.(int)$el['id'].'"' : '' ?>>
                    <span class="elem-nom"><?= e($tlbl) ?></span>
                    <label class="seg-radio"><input type="checkbox" class="el-presente" <?= ($el && $el['presente']) ? 'checked' : '' ?>> Tiene</label>
                    <select class="form-control el-estado" style="width:auto;">
                        <option value="">Estado…</option>
                        <?php foreach (['Bueno','Regular','Requiere reparación','No funciona'] as $es): ?>
                        <option value="<?= $es ?>" <?= ($el && $el['estado'] === $es) ? 'selected' : '' ?>><?= $es ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="seg-radio"><input type="checkbox" class="el-reparar" <?= ($el && $el['necesita_reparacion']) ? 'checked' : '' ?>> Reparación</label>
                    <button type="button" class="btn btn-outline btn-sm" onclick="subirFotoElemento(this, <?= (int)$piso['id'] ?>, '<?= $tk ?>')">
                        <i class="bi bi-camera"></i> Foto
                    </button>
                    <div class="elem-fotos" style="display:flex;gap:6px;flex-wrap:wrap;width:100%;"></div>
                </div>
                <?php endforeach; ?>

                <?php if ($puedeEditar): ?>
                <div style="margin-top:12px;">
                    <button type="button" class="btn btn-primary btn-sm" onclick="guardarPiso(this, <?= (int)$piso['id'] ?>)">
                        <i class="bi bi-check-lg"></i> Guardar elementos del piso
                    </button>
                    <span class="piso-msg text-sm" style="margin-left:8px;"></span>
                </div>
                <?php endif; ?>

                <!-- Apartamentos de este piso -->
                <div class="bloque-tit" style="margin-top:20px;"><i class="bi bi-door-open"></i> Apartamentos de este piso</div>
                <div class="apto-generador" style="margin-bottom:10px;padding:10px 12px;background:#f7f9fd;border-radius:9px;">
                    <label class="text-sm">¿Cuántos apartamentos tiene este piso?</label>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                        <input type="number" class="form-control apto-cantidad" min="1" max="100"
                               value="<?= (int)($ed['aptos_por_piso'] ?: 1) ?>" style="width:90px;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="generarAptos(this, <?= (int)$piso['id'] ?>, <?= (int)$piso['numero_piso'] ?>)">
                            <i class="bi bi-grid-3x3-gap"></i> Generar apartamentos
                        </button>
                    </div>
                </div>
                <div class="apto-lista"></div>

            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:16px;">
        <button type="button" class="btn btn-outline" onclick="irPaso(1)"><i class="bi bi-arrow-left"></i> Datos del edificio</button>
        <button type="button" class="btn btn-primary" onclick="irPaso(3)">Ir al cierre <i class="bi bi-arrow-right"></i></button>
    </div>
</div>

<!-- ============ PASO 3: CIERRE (AZOTEA + RESUMEN) ============ -->
<div class="wz-panel hidden" id="paso-3">
    <h3>Cierre del levantamiento</h3>
    <p class="sub">Lo último del recorrido: al llegar a la azotea, registre su situación y la de tanques e impermeabilización.</p>

    <form id="form-cierre" onsubmit="return guardarCierre(event)">
        <?php
        $globales = ['azotea' => 'Azotea', 'tanques' => 'Tanques de agua', 'impermeabilizacion' => 'Impermeabilización'];
        $opciones = ['Buena','Regular','Requiere reparación','No aplica'];
        foreach ($globales as $key => $lbl):
            $valActual = $ed[$key . '_estado'] ?? '';
        ?>
        <div class="campo-estado">
            <label class="tit"><?= $lbl ?></label>
            <?php foreach ($opciones as $op): ?>
            <label class="seg-radio">
                <input type="radio" name="<?= $key ?>_estado" value="<?= $op ?>" <?= $valActual === $op ? 'checked' : '' ?>>
                <?= $op ?>
            </label>
            <?php endforeach; ?>
        </div>
        <input type="text" class="form-control" name="<?= $key ?>_obs" placeholder="Observación (opcional)"
               value="<?= e($ed[$key . '_obs'] ?? '') ?>" style="margin-bottom:8px;">
        <?php endforeach; ?>

        <hr style="margin:18px 0;border:0;border-top:1px solid #eef0f5;">
        <div class="bloque-tit"><i class="bi bi-calendar-range"></i> Tiempo estimado de la reconstrucción</div>
        <div class="flex gap-8" style="flex-wrap:wrap;">
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Fecha de inicio estimada</label>
                <input type="date" name="fecha_inicio_estimada" class="form-control">
            </div>
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Fecha de fin estimada</label>
                <input type="date" name="fecha_fin_estimada" class="form-control">
            </div>
        </div>

        <?php if ($puedeEditar): ?>
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Guardar y ver resumen</button>
            <button type="button" class="btn btn-outline" onclick="irPaso(2)"><i class="bi bi-arrow-left"></i> Volver al recorrido</button>
        </div>
        <?php endif; ?>
    </form>

    <!-- Resumen de materiales (se llena al calcular) -->
    <div id="resumen-materiales" style="margin-top:20px;"></div>
</div>

</div><!-- /wz-wrap -->

<!-- Input de archivo oculto, compartido para subir fotos -->
<input type="file" id="rec-file-input" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="_onFotoElegida(this)">

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const EDIFICIO_ID = <?= (int)$ed['id'] ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;

function irPaso(n) {
    [1,2,3].forEach(i => {
        document.getElementById('paso-'+i).classList.toggle('hidden', i !== n);
        document.getElementById('tab-'+i).classList.toggle('activo', i === n);
    });
    if (n === 3) cargarResumen();
    window.scrollTo({top:0, behavior:'smooth'});
}

document.getElementById('tiene_areas_comunes')?.addEventListener('change', function(){
    document.getElementById('wrap-areas').style.display = this.checked ? '' : 'none';
});

// ================= PASO 1: DATOS DEL EDIFICIO =================
async function guardarEdificio(ev) {
    ev.preventDefault();
    if (!PUEDE_EDITAR) return false;
    const payload = {
        inspeccion_id: INSPECCION_ID,
        num_pisos: document.getElementById('num_pisos').value,
        aptos_por_piso: document.getElementById('aptos_por_piso').value,
        tiene_areas_comunes: document.getElementById('tiene_areas_comunes').checked ? 1 : 0,
        areas_comunes_desc: document.getElementById('areas_comunes_desc').value,
    };
    const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) {
        // Recargar para que se generen los pisos y aparezcan en el paso 2.
        location.href = 'levantamiento.php?inspeccion=' + INSPECCION_ID + '&paso=2';
    } else alert(data.mensaje || 'Error al guardar.');
    return false;
}

// ================= PASO 2: PISOS =================
function togglePiso(head) {
    head.nextElementSibling.classList.toggle('hidden');
}

async function guardarPiso(btn, pisoId) {
    const card = btn.closest('.piso-card');
    const elementos = [];
    card.querySelectorAll('.elem-row').forEach(row => {
        elementos.push({
            tipo: row.dataset.tipo,
            presente: row.querySelector('.el-presente').checked ? 1 : 0,
            estado: row.querySelector('.el-estado').value,
            necesita_reparacion: row.querySelector('.el-reparar').checked ? 1 : 0,
        });
    });
    const payload = {
        piso_id: pisoId,
        tiene_areas_comunes: card.querySelector('.piso-areas').checked ? 1 : 0,
        areas_comunes_desc: card.querySelector('.piso-areas-desc').value,
        elementos,
    };
    const res = await fetch(URL_BASE + 'guardar_rec_piso.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    const msg = card.querySelector('.piso-msg');
    if (data.ok) {
        msg.textContent = '✓ Guardado'; msg.style.color = '#2E7D32';
        if (data.elementos) {
            card.querySelectorAll('.elem-row').forEach(row => {
                const id = data.elementos[row.dataset.tipo];
                if (id) row.dataset.elemId = id;
            });
        }
    } else { msg.textContent = data.mensaje || 'Error'; msg.style.color = '#A61C1C'; }
}

// ================= APARTAMENTOS (dentro de cada piso) =================
async function generarAptos(btn, pisoId, numeroPiso) {
    const card = btn.closest('.piso-card');
    const cantidad = parseInt(card.querySelector('.apto-cantidad').value) || 0;
    if (cantidad < 1) { alert('Indique la cantidad de apartamentos.'); return; }
    const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'generar', piso_id: pisoId, cantidad, numero_piso: numeroPiso })
    });
    const data = await res.json();
    if (data.ok) {
        const lista = card.querySelector('.apto-lista');
        lista.innerHTML = '';
        data.apartamentos.forEach(a => pintarApartamento(a, lista));
    } else alert(data.mensaje || 'Error al generar.');
}

function pintarApartamento(a, lista) {
    const card = document.createElement('div');
    card.className = 'apto-card';
    card.innerHTML = `
        <div class="apto-head" onclick="this.nextElementSibling.classList.toggle('hidden')">
            <i class="bi bi-door-open"></i> Apartamento ${a.identificador}
        </div>
        <div class="apto-body hidden">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                ${['habitaciones','salas','balcones','cocinas'].map(t => `
                    <div class="field" style="width:105px;">
                        <label class="text-sm" style="text-transform:capitalize;">${t}</label>
                        <input type="number" min="0" max="30" class="form-control amb-${t}" value="${a['num_'+t]||0}">
                    </div>`).join('')}
                <button type="button" class="btn btn-primary btn-sm" onclick="guardarApto(this, ${a.id})">
                    <i class="bi bi-check-lg"></i> Generar ambientes
                </button>
            </div>
            <div class="amb-lista" style="margin-top:14px;"></div>
        </div>`;
    lista.appendChild(card);
    if ((a.num_habitaciones + a.num_salas + a.num_balcones + a.num_cocinas) > 0) {
        cargarAmbientes(a.id, card.querySelector('.amb-lista'));
    }
}

async function guardarApto(btn, aptoId) {
    const cont = btn.closest('.apto-body');
    const payload = {
        accion:'guardar_apto', apartamento_id: aptoId,
        num_habitaciones: cont.querySelector('.amb-habitaciones').value,
        num_salas:        cont.querySelector('.amb-salas').value,
        num_balcones:     cont.querySelector('.amb-balcones').value,
        num_cocinas:      cont.querySelector('.amb-cocinas').value,
    };
    const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) pintarAmbientes(data.ambientes, cont.querySelector('.amb-lista'));
    else alert(data.mensaje || 'Error.');
}

async function cargarAmbientes(aptoId, contenedor) {
    const res = await fetch(URL_BASE + 'listar_rec_aptos.php?ambientes_de=' + aptoId);
    const data = await res.json();
    if (data.ok) pintarAmbientes(data.ambientes, contenedor);
}

function pintarAmbientes(ambientes, contenedor) {
    if (!ambientes || !ambientes.length) { contenedor.innerHTML = ''; return; }
    const iconos = {'Habitación':'bi-door-closed','Sala':'bi-tv','Balcón':'bi-flower1','Cocina':'bi-fire'};
    let html = '';
    ambientes.forEach(am => {
        const rep = am.necesita_reparacion == 1;
        html += `
        <div class="amb-row" data-amb="${am.id}" style="border:1px solid #e8ebf3;border-radius:9px;padding:10px 12px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-weight:600;color:#2a3140;"><i class="bi ${iconos[am.tipo]||'bi-square'}"></i> ${am.tipo} ${am.numero}</span>
                <label class="seg-radio"><input type="checkbox" class="amb-reparar" ${rep?'checked':''} onchange="toggleReparar(this, ${am.id})"> Necesita reparación</label>
                <button type="button" class="btn btn-outline btn-sm" onclick="fotoAmbiente(this, ${am.id})"><i class="bi bi-camera"></i> Foto${rep?'s':''}</button>
                <span class="amb-hint text-sm" style="color:#767c94;">${rep?'Suba fotos indicando la parte':'Suba 1 foto del estado'}</span>
            </div>
            <div class="amb-reparacion" style="${rep?'':'display:none;'}margin-top:10px;padding:10px;background:#fbf8ef;border-radius:8px;">
                <div class="text-sm" style="font-weight:600;color:#8a6d1a;margin-bottom:6px;"><i class="bi bi-rulers"></i> Metros cuadrados a reparar</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    ${['pared','techo','piso','closet'].map(sup => `
                        <div style="width:110px;">
                            <label class="text-sm" style="text-transform:capitalize;">${sup} (m²)</label>
                            <input type="number" min="0" step="0.01" class="form-control sup-${sup}" data-sup="${sup}" value="0" onchange="guardarReparacion(${am.id}, this)">
                        </div>`).join('')}
                </div>
                <div class="amb-materiales text-sm" style="margin-top:8px;color:#55617f;"></div>
            </div>
            <div class="amb-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;"></div>
        </div>`;
    });
    contenedor.innerHTML = html;
    ambientes.forEach(am => {
        fetch(URL_BASE + 'listar_rec_aptos.php?fotos_de=' + am.id).then(r=>r.json()).then(d=>{
            if (d.ok && d.fotos.length) {
                const row = contenedor.querySelector('.amb-row[data-amb="'+am.id+'"] .amb-fotos');
                d.fotos.forEach(f => agregarMiniFoto(row, f));
            }
        });
        fetch(URL_BASE + 'listar_rec_aptos.php?reparaciones_de=' + am.id).then(r=>r.json()).then(d=>{
            if (d.ok && d.reparaciones && d.reparaciones.length) {
                const row = contenedor.querySelector('.amb-row[data-amb="'+am.id+'"]');
                d.reparaciones.forEach(rep => {
                    const inp = row.querySelector('.sup-'+rep.tipo_superficie);
                    if (inp) inp.value = rep.metros_cuadrados;
                });
                recalcularMateriales(row);
            }
        });
    });
}

async function toggleReparar(chk, ambId) {
    const row = chk.closest('.amb-row');
    row.querySelector('.amb-hint').textContent = chk.checked ? 'Suba fotos indicando la parte' : 'Suba 1 foto del estado';
    const bloque = row.querySelector('.amb-reparacion');
    if (bloque) bloque.style.display = chk.checked ? 'block' : 'none';
    await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'guardar_ambiente', ambiente_id: ambId, necesita_reparacion: chk.checked?1:0 })
    });
}

async function guardarReparacion(ambId, input) {
    const row = input.closest('.amb-row');
    const reparaciones = [];
    row.querySelectorAll('[data-sup]').forEach(inp => {
        const m2 = parseFloat(inp.value) || 0;
        if (m2 > 0) reparaciones.push({ tipo_superficie: inp.dataset.sup, metros_cuadrados: m2 });
    });
    await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'guardar_reparaciones', nivel:'ambiente', ref_id: ambId, reparaciones })
    });
    recalcularMateriales(row);
}

async function recalcularMateriales(row) {
    const m2 = {};
    row.querySelectorAll('[data-sup]').forEach(inp => { const v = parseFloat(inp.value)||0; if (v>0) m2[inp.dataset.sup]=v; });
    const cont = row.querySelector('.amb-materiales');
    if (!cont) return;
    if (Object.keys(m2).length === 0) { cont.innerHTML = ''; return; }
    const res = await fetch(URL_BASE + 'calcular_materiales.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ m2 })
    });
    const data = await res.json();
    if (data.ok) {
        const items = Object.entries(data.materiales).map(([m,c]) =>
            `<span style="display:inline-block;background:#eef2fb;border-radius:14px;padding:2px 9px;margin:2px;">${m}: <b>${c}</b></span>`).join('');
        cont.innerHTML = '<div style="margin-top:4px;"><i class="bi bi-box-seam"></i> Materiales estimados: ' + items + '</div>';
    }
}

// ================= FOTOS (compartido) =================
let _fotoDestino = null;

function subirFotoElemento(btn, pisoId, tipo) {
    const row = btn.closest('.elem-row');
    if (!row.dataset.elemId) {
        alert('Primero guarde los elementos del piso para poder adjuntar fotos.');
        return;
    }
    let parte = null;
    if (row.querySelector('.el-reparar').checked) {
        parte = prompt('¿Qué parte muestra la foto? (ej: motor, cabina, estructura)');
        if (parte === null) return;
    }
    _fotoDestino = { nivel:'elemento_piso', refId: row.dataset.elemId, parte, cont: row.querySelector('.elem-fotos') };
    document.getElementById('rec-file-input').click();
}

function fotoAmbiente(btn, ambId) {
    const row = btn.closest('.amb-row');
    const necesitaReparar = row.querySelector('.amb-reparar').checked;
    if (!necesitaReparar && row.querySelectorAll('.amb-fotos img').length >= 1) {
        alert('Este ambiente no necesita reparación: basta con una foto. Marque "Necesita reparación" para agregar más.');
        return;
    }
    let parte = null;
    if (necesitaReparar) {
        parte = prompt('¿Qué parte muestra la foto? (ej: pared norte, closet, techo, piso)');
        if (parte === null) return;
    }
    _fotoDestino = { nivel:'ambiente', refId: ambId, parte, cont: row.querySelector('.amb-fotos') };
    document.getElementById('rec-file-input').click();
}

async function _onFotoElegida(input) {
    if (!input.files || !input.files[0] || !_fotoDestino) { input.value=''; return; }
    const fd = new FormData();
    fd.append('nivel', _fotoDestino.nivel);
    fd.append('ref_id', _fotoDestino.refId);
    if (_fotoDestino.parte) fd.append('parte', _fotoDestino.parte);
    fd.append('foto', input.files[0]);
    const cont = _fotoDestino.cont;
    cont.insertAdjacentHTML('beforeend', '<span class="subiendo">Subiendo…</span>');
    try {
        const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
        const data = await res.json();
        cont.querySelector('.subiendo')?.remove();
        if (data.ok) agregarMiniFoto(cont, data.foto);
        else alert(data.mensaje || 'No se pudo subir la foto.');
    } catch(e) {
        cont.querySelector('.subiendo')?.remove();
        alert('Error de red al subir la foto.');
    }
    input.value = '';
    _fotoDestino = null;
}

function agregarMiniFoto(cont, f) {
    const parte = f.parte ? '<div style="font-size:10px;color:#55617f;text-align:center;">'+f.parte+'</div>' : '';
    cont.insertAdjacentHTML('beforeend',
        '<div style="text-align:center;"><img src="'+f.ruta+'" style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #d8dce6;">'+parte+'</div>');
}

// ================= PASO 3: CIERRE Y RESUMEN =================
async function guardarCierre(ev) {
    ev.preventDefault();
    if (!PUEDE_EDITAR) return false;
    const form = document.getElementById('form-cierre');
    const payload = { inspeccion_id: INSPECCION_ID, accion:'cierre' };
    ['azotea','tanques','impermeabilizacion'].forEach(k => {
        const sel = form.querySelector('input[name="'+k+'_estado"]:checked');
        payload[k+'_estado'] = sel ? sel.value : '';
        payload[k+'_obs'] = form.querySelector('input[name="'+k+'_obs"]').value;
    });
    payload.fecha_inicio_estimada = form.querySelector('input[name="fecha_inicio_estimada"]').value;
    payload.fecha_fin_estimada = form.querySelector('input[name="fecha_fin_estimada"]').value;

    const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) { cargarResumen(); alert('Levantamiento cerrado. Revise el resumen de materiales.'); }
    else alert(data.mensaje || 'Error al guardar.');
    return false;
}

async function cargarResumen() {
    const cont = document.getElementById('resumen-materiales');
    if (!cont) return;
    cont.innerHTML = '<p class="text-muted">Calculando materiales del edificio…</p>';
    const res = await fetch(URL_BASE + 'resumen_materiales.php?edificio_id=' + EDIFICIO_ID);
    const data = await res.json();
    if (!data.ok) { cont.innerHTML = '<p class="text-muted">No se pudo cargar el resumen.</p>'; return; }
    let html = '<div class="wz-panel" style="border:2px solid #22366F;"><h3><i class="bi bi-clipboard-check"></i> Resumen de materiales del edificio</h3>';
    html += '<p class="sub">Total aproximado según los m² registrados en todo el edificio.</p>';
    if (data.total_m2 > 0) {
        html += '<p><b>Total a reparar:</b> ' + data.total_m2.toFixed(2) + ' m²</p>';
        html += '<table style="width:100%;border-collapse:collapse;margin-top:8px;">';
        html += '<tr style="background:#22366F;color:#fff;"><th style="text-align:left;padding:7px;">Material</th><th style="text-align:right;padding:7px;">Cantidad</th></tr>';
        Object.entries(data.materiales).forEach(([m,c],i) => {
            html += `<tr style="background:${i%2?'#f7f9fd':'#fff'};"><td style="padding:6px 7px;">${m}</td><td style="padding:6px 7px;text-align:right;font-weight:600;">${c}</td></tr>`;
        });
        html += '</table>';
    } else {
        html += '<p class="text-muted">Aún no hay reparaciones registradas con metros cuadrados.</p>';
    }
    html += '</div>';
    cont.innerHTML = html;
}

// Si venimos de guardar el paso 1, abrir directo el recorrido.
<?php if (($_GET['paso'] ?? '') === '2'): ?>
irPaso(2);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
