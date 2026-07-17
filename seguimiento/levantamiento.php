<?php
/**
 * Levantamiento técnico del edificio (wizard por pasos).
 * Paso 1: datos generales (pisos, aptos, áreas comunes, azotea/tanques/imperm.)
 * Paso 2: estructura de pisos (áreas comunes por piso + elementos con foto)
 * Los apartamentos y ambientes se agregan en pasos posteriores.
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

// Datos del edificio (inspección) y del levantamiento.
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
    .wz-steps { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
    .wz-step { flex:1; min-width:130px; padding:10px 14px; border-radius:10px; background:#f2f5fc;
               border:2px solid transparent; font-size:13px; color:#767c94; }
    .wz-step.activo { background:#eaf0ff; border-color:#22366F; color:#22366F; font-weight:600; }
    .wz-step.hecho { background:#e5f7ee; color:#2E7D32; }
    .wz-step .n { display:inline-block; width:22px; height:22px; border-radius:50%; background:#fff;
                  text-align:center; line-height:22px; font-weight:700; margin-right:6px; font-size:12px; }
    .wz-panel { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:22px 24px; }
    .wz-panel h3 { margin:0 0 4px; color:#22366F; font-size:16px; }
    .wz-panel .sub { color:#767c94; font-size:13px; margin:0 0 18px; }
    .campo-estado { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:10px 0; }
    .campo-estado label.tit { min-width:150px; font-weight:600; color:#2a3140; font-size:13.5px; }
    .seg-radio { display:inline-flex; gap:5px; align-items:center; font-size:12.5px; padding:4px 9px;
                 border:1px solid #d6dbe8; border-radius:20px; cursor:pointer; }
    .seg-radio input { margin:0; }
    .hidden { display:none; }
    .piso-card { border:1px solid #e6e9f2; border-radius:10px; margin-bottom:12px; overflow:hidden; }
    .piso-head { background:#f6f7fb; padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
    .piso-head .tit { font-weight:600; color:#22366F; }
    .piso-body { padding:14px; display:none; }
    .piso-body.abierto { display:block; }
    .elem-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f2f7; flex-wrap:wrap; }
    .elem-nom { min-width:150px; font-weight:600; font-size:13px; color:#2a3140; }
</style>

<!-- Indicador de pasos -->
<div class="wz-steps">
    <div class="wz-step <?= $ed['completado'] ? 'hecho' : 'activo' ?>" id="tab-1" onclick="irPaso(1)">
        <span class="n">1</span> Datos generales
    </div>
    <div class="wz-step <?= $ed['completado'] ? 'activo' : '' ?>" id="tab-2" onclick="irPaso(2)">
        <span class="n">2</span> Pisos y elementos
    </div>
    <div class="wz-step" id="tab-3" onclick="irPaso(3)">
        <span class="n">3</span> Apartamentos
    </div>
</div>

<!-- ============ PASO 1: DATOS GENERALES ============ -->
<div class="wz-panel" id="paso-1">
    <h3>Datos generales del edificio</h3>
    <p class="sub">Corrobore la información básica de la estructura.</p>
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
            <label class="tit">¿Tiene áreas comunes?</label>
            <label class="seg-radio"><input type="checkbox" id="tiene_areas_comunes" <?= $ed['tiene_areas_comunes'] ? 'checked' : '' ?>> Sí, tiene áreas comunes generales</label>
        </div>
        <div class="field" id="wrap-areas" style="<?= $ed['tiene_areas_comunes'] ? '' : 'display:none;' ?>">
            <label class="text-sm">¿Cuáles? (descripción general)</label>
            <input type="text" id="areas_comunes_desc" class="form-control" value="<?= e($ed['areas_comunes_desc'] ?? '') ?>" placeholder="Ej: lobby, estacionamiento, salón de fiestas">
        </div>

        <hr style="margin:18px 0;border:0;border-top:1px solid #eef0f5;">
        <h3 style="font-size:14px;">Situación de elementos del edificio</h3>

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
        <input type="text" class="form-control" name="<?= $key ?>_obs" placeholder="Observación de <?= mb_strtolower($lbl) ?> (opcional)"
               value="<?= e($ed[$key . '_obs'] ?? '') ?>" style="margin-bottom:8px;">
        <?php endforeach; ?>

        <?php if ($puedeEditar): ?>
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right"></i> Guardar y continuar a pisos</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ============ PASO 2: PISOS Y ELEMENTOS ============ -->
<div class="wz-panel hidden" id="paso-2">
    <h3>Pisos y elementos comunes</h3>
    <p class="sub">Por cada piso, indique áreas comunes y el estado de sus elementos. Cada elemento puede llevar foto.</p>
    <div id="lista-pisos">
        <?php if (!$pisos): ?>
            <p class="text-muted">Primero complete los datos generales (Paso 1) para generar los pisos.</p>
        <?php else: ?>
            <?php foreach ($pisos as $piso):
                $elems = recElementosPiso((int)$piso['id']);
            ?>
            <div class="piso-card" data-piso="<?= (int)$piso['id'] ?>">
                <div class="piso-head" onclick="togglePiso(this)">
                    <span class="tit">Piso <?= (int)$piso['numero_piso'] ?></span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="piso-body">
                    <div class="campo-estado">
                        <label class="seg-radio"><input type="checkbox" class="piso-areas" <?= $piso['tiene_areas_comunes'] ? 'checked' : '' ?>> Tiene áreas comunes en este piso</label>
                        <input type="text" class="form-control piso-areas-desc" placeholder="¿Cuáles?" value="<?= e($piso['areas_comunes_desc'] ?? '') ?>" style="flex:1;min-width:160px;">
                    </div>
                    <div style="margin-top:8px;">
                        <?php foreach ($tiposElem as $tk => $tlbl):
                            $el = $elems[$tk] ?? null;
                        ?>
                        <div class="elem-row" data-tipo="<?= $tk ?>">
                            <span class="elem-nom"><?= e($tlbl) ?></span>
                            <label class="seg-radio"><input type="checkbox" class="el-presente" <?= ($el && $el['presente']) ? 'checked' : '' ?>> Tiene</label>
                            <select class="form-control el-estado" style="width:auto;">
                                <option value="">Estado…</option>
                                <?php foreach (['Bueno','Regular','Requiere reparación','No funciona'] as $es): ?>
                                <option value="<?= $es ?>" <?= ($el && $el['estado'] === $es) ? 'selected' : '' ?>><?= $es ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="seg-radio"><input type="checkbox" class="el-reparar" <?= ($el && $el['necesita_reparacion']) ? 'checked' : '' ?>> Necesita reparación</label>
                            <button type="button" class="btn btn-outline btn-sm" onclick="subirFotoElemento(this, <?= (int)$piso['id'] ?>, '<?= $tk ?>')">
                                <i class="bi bi-camera"></i> Foto
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($puedeEditar): ?>
                    <div style="margin-top:12px;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="guardarPiso(this, <?= (int)$piso['id'] ?>)">
                            <i class="bi bi-check-lg"></i> Guardar piso <?= (int)$piso['numero_piso'] ?>
                        </button>
                        <span class="piso-msg text-sm" style="margin-left:8px;"></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div style="margin-top:16px;">
        <button type="button" class="btn btn-outline" onclick="irPaso(1)"><i class="bi bi-arrow-left"></i> Volver a datos generales</button>
        <button type="button" class="btn btn-primary" onclick="irPaso(3)">Continuar a apartamentos <i class="bi bi-arrow-right"></i></button>
    </div>
</div>

<!-- ============ PASO 3: APARTAMENTOS ============ -->
<div class="wz-panel hidden" id="paso-3">
    <h3>Apartamentos por piso</h3>
    <p class="sub">Seleccione un piso, genere sus apartamentos e indique los ambientes de cada uno.</p>

    <?php if (!$pisos): ?>
        <p class="text-muted">Primero complete los datos generales (Paso 1) para generar los pisos.</p>
    <?php else: ?>
        <div class="field" style="max-width:320px;">
            <label class="text-sm">Piso</label>
            <select id="apto-piso-sel" class="form-control" onchange="cargarAptosDePiso()">
                <option value="">Seleccione un piso…</option>
                <?php foreach ($pisos as $piso): ?>
                    <option value="<?= (int)$piso['id'] ?>" data-num="<?= (int)$piso['numero_piso'] ?>">
                        Piso <?= (int)$piso['numero_piso'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Generador de apartamentos -->
        <div id="apto-generador" style="display:none;margin:12px 0;padding:12px 14px;background:#f7f9fd;border-radius:10px;">
            <label class="text-sm">¿Cuántos apartamentos tiene este piso?</label>
            <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                <input type="number" id="apto-cantidad" class="form-control" min="1" max="100"
                       value="<?= (int)($ed['aptos_por_piso'] ?: 1) ?>" style="width:100px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="generarAptos()">
                    <i class="bi bi-grid-3x3-gap"></i> Generar apartamentos
                </button>
            </div>
        </div>

        <!-- Lista de apartamentos del piso seleccionado -->
        <div id="apto-lista"></div>
    <?php endif; ?>

    <div style="margin-top:16px;">
        <button type="button" class="btn btn-outline" onclick="irPaso(2)"><i class="bi bi-arrow-left"></i> Volver a pisos</button>
    </div>
</div>

<!-- Input de archivo oculto, compartido para subir fotos de elementos -->
<input type="file" id="rec-file-input" accept="image/jpeg,image/png,image/webp"
       style="display:none;" onchange="_onFotoElegida(this)">

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;

function irPaso(n) {
    [1,2,3].forEach(i => {
        document.getElementById('paso-'+i).classList.toggle('hidden', i !== n);
        const tab = document.getElementById('tab-'+i);
        tab.classList.toggle('activo', i === n);
    });
    window.scrollTo({top:0, behavior:'smooth'});
}

// Mostrar/ocultar descripción de áreas comunes
document.getElementById('tiene_areas_comunes')?.addEventListener('change', function(){
    document.getElementById('wrap-areas').style.display = this.checked ? '' : 'none';
});

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
    ['azotea','tanques','impermeabilizacion'].forEach(k => {
        const sel = document.querySelector('input[name="'+k+'_estado"]:checked');
        payload[k+'_estado'] = sel ? sel.value : '';
        payload[k+'_obs'] = document.querySelector('input[name="'+k+'_obs"]').value;
    });
    const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) {
        // Recargar para que se generen los pisos y aparezcan en el paso 2.
        location.href = '?inspeccion=' + INSPECCION_ID + '&paso=2';
    } else {
        alert(data.mensaje || 'No se pudo guardar.');
    }
    return false;
}

function togglePiso(head) {
    head.parentElement.querySelector('.piso-body').classList.toggle('abierto');
    const ic = head.querySelector('i');
    ic.classList.toggle('bi-chevron-down'); ic.classList.toggle('bi-chevron-up');
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
        // Guardar el id de cada elemento en su fila, para poder subirle fotos.
        if (data.elementos) {
            card.querySelectorAll('.elem-row').forEach(row => {
                const id = data.elementos[row.dataset.tipo];
                if (id) row.dataset.elemId = id;
            });
        }
    }
    else { msg.textContent = data.mensaje || 'Error'; msg.style.color = '#A61C1C'; }
}

// Input de archivo oculto y estado de la subida en curso.
let _fotoDestino = null;
function subirFotoElemento(btn, pisoId, tipo) {
    const row = btn.closest('.elem-row');
    // El elemento debe estar guardado (tener id) antes de subirle una foto.
    if (!row.dataset.elemId) {
        alert('Primero guarde el piso (botón "Guardar piso") para poder adjuntar fotos a sus elementos.');
        return;
    }
    // Si el elemento necesita reparación, pedir qué parte es (pared, closet, techo…).
    let parte = null;
    const necesitaReparar = row.querySelector('.el-reparar').checked;
    if (necesitaReparar) {
        parte = prompt('¿Qué parte muestra la foto? (ej: pared norte, techo, piso, motor del ascensor)');
        if (parte === null) return; // canceló
    }
    _fotoDestino = { nivel: 'elemento_piso', refId: row.dataset.elemId, parte, row };
    document.getElementById('rec-file-input').click();
}

async function _onFotoElegida(input) {
    if (!input.files || !input.files[0] || !_fotoDestino) { input.value=''; return; }
    const fd = new FormData();
    fd.append('nivel', _fotoDestino.nivel);
    fd.append('ref_id', _fotoDestino.refId);
    if (_fotoDestino.parte) fd.append('parte', _fotoDestino.parte);
    fd.append('foto', input.files[0]);

    // El contenedor de miniaturas depende del nivel: elementos de piso usan
    // .elem-fotos; ambientes usan .amb-fotos (ya existe en la fila).
    let cont = _fotoDestino.row.querySelector('.amb-fotos') || _fotoDestino.row.querySelector('.elem-fotos');
    if (!cont) {
        cont = document.createElement('div');
        cont.className = 'elem-fotos';
        cont.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;width:100%;';
        _fotoDestino.row.appendChild(cont);
    }
    cont.insertAdjacentHTML('beforeend', '<span class="subiendo" style="font-size:12px;color:#767c94;">Subiendo…</span>');

    try {
        const res = await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd });
        const data = await res.json();
        cont.querySelector('.subiendo')?.remove();
        if (data.ok) {
            const p = data.foto.parte ? ('<div style="font-size:10px;color:#55617f;text-align:center;">'+data.foto.parte+'</div>') : '';
            cont.insertAdjacentHTML('beforeend',
                '<div style="text-align:center;"><img src="'+data.foto.ruta+'" style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #d8dce6;">'+p+'</div>');
        } else {
            alert(data.mensaje || 'No se pudo subir la foto.');
        }
    } catch(e) {
        cont.querySelector('.subiendo')?.remove();
        alert('Error de red al subir la foto.');
    }
    input.value = '';
    _fotoDestino = null;
}

// ================= PASO 3: APARTAMENTOS Y AMBIENTES =================
function cargarAptosDePiso() {
    const sel = document.getElementById('apto-piso-sel');
    const gen = document.getElementById('apto-generador');
    const lista = document.getElementById('apto-lista');
    lista.innerHTML = '';
    if (!sel.value) { gen.style.display = 'none'; return; }
    gen.style.display = 'block';
    // Cargar apartamentos ya existentes de ese piso.
    fetch(URL_BASE + 'listar_rec_aptos.php?piso_id=' + sel.value)
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.apartamentos.length) {
                data.apartamentos.forEach(a => pintarApartamento(a));
            }
        });
}

async function generarAptos() {
    const sel = document.getElementById('apto-piso-sel');
    const opt = sel.options[sel.selectedIndex];
    const cantidad = parseInt(document.getElementById('apto-cantidad').value) || 0;
    if (!sel.value || cantidad < 1) { alert('Indique el piso y la cantidad.'); return; }
    const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'generar', piso_id: sel.value, cantidad, numero_piso: opt.dataset.num })
    });
    const data = await res.json();
    if (data.ok) {
        document.getElementById('apto-lista').innerHTML = '';
        data.apartamentos.forEach(a => pintarApartamento(a));
    } else alert(data.mensaje || 'Error al generar.');
}

function pintarApartamento(a) {
    const lista = document.getElementById('apto-lista');
    const card = document.createElement('div');
    card.className = 'piso-card';
    card.style.cssText = 'border:1px solid #e6e9f2;border-radius:12px;margin-bottom:12px;overflow:hidden;';
    card.innerHTML = `
        <div class="piso-head" style="padding:12px 16px;background:#f2f5fc;cursor:pointer;font-weight:600;color:#22366F;"
             onclick="this.nextElementSibling.classList.toggle('hidden')">
            <i class="bi bi-door-open"></i> Apartamento ${a.identificador}
        </div>
        <div class="hidden" style="padding:14px 16px;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                ${['habitaciones','salas','balcones','cocinas'].map(t => `
                    <div class="field" style="width:110px;">
                        <label class="text-sm" style="text-transform:capitalize;">${t}</label>
                        <input type="number" min="0" max="30" class="form-control amb-${t}"
                               value="${a['num_'+t] || 0}">
                    </div>`).join('')}
                <button type="button" class="btn btn-primary btn-sm" onclick="guardarApto(this, ${a.id})">
                    <i class="bi bi-check-lg"></i> Generar ambientes
                </button>
            </div>
            <div class="amb-lista" style="margin-top:14px;"></div>
        </div>`;
    lista.appendChild(card);
    // Si el apartamento ya tenía ambientes, cargarlos.
    if ((a.num_habitaciones + a.num_salas + a.num_balcones + a.num_cocinas) > 0) {
        cargarAmbientes(a.id, card.querySelector('.amb-lista'));
    }
}

async function guardarApto(btn, aptoId) {
    const cont = btn.closest('div').parentElement;
    const payload = {
        accion: 'guardar_apto', apartamento_id: aptoId,
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
                <label class="seg-radio"><input type="checkbox" class="amb-reparar" ${rep?'checked':''}
                    onchange="toggleReparar(this, ${am.id})"> Necesita reparación</label>
                <button type="button" class="btn btn-outline btn-sm" onclick="fotoAmbiente(this, ${am.id})">
                    <i class="bi bi-camera"></i> Foto${rep?'s':''}
                </button>
                <span class="amb-hint text-sm" style="color:#767c94;">${rep?'Suba varias fotos indicando la parte':'Suba 1 foto del estado'}</span>
            </div>
            <!-- Bloque de reparación: m² por superficie (solo si necesita reparación) -->
            <div class="amb-reparacion" style="${rep?'':'display:none;'}margin-top:10px;padding:10px;background:#fbf8ef;border-radius:8px;">
                <div class="text-sm" style="font-weight:600;color:#8a6d1a;margin-bottom:6px;">
                    <i class="bi bi-rulers"></i> Metros cuadrados a reparar por superficie
                </div>
                <div class="amb-superficies" style="display:flex;gap:8px;flex-wrap:wrap;">
                    ${['pared','techo','piso','closet'].map(sup => `
                        <div style="width:120px;">
                            <label class="text-sm" style="text-transform:capitalize;">${sup} (m²)</label>
                            <input type="number" min="0" step="0.01" class="form-control sup-${sup}"
                                   data-sup="${sup}" value="0" onchange="guardarReparacion(${am.id}, this)">
                        </div>`).join('')}
                </div>
                <div class="amb-materiales text-sm" style="margin-top:8px;color:#55617f;"></div>
            </div>
            <div class="amb-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;"></div>
        </div>`;
    });
    contenedor.innerHTML = html;
    // Cargar fotos y reparaciones existentes de cada ambiente
    ambientes.forEach(am => {
        fetch(URL_BASE + 'listar_rec_aptos.php?fotos_de=' + am.id)
            .then(r=>r.json()).then(d=>{
                if (d.ok && d.fotos.length) {
                    const row = contenedor.querySelector('.amb-row[data-amb="'+am.id+'"] .amb-fotos');
                    d.fotos.forEach(f => agregarMiniFoto(row, f));
                }
            });
        // Cargar los m² de reparación ya guardados
        fetch(URL_BASE + 'listar_rec_aptos.php?reparaciones_de=' + am.id)
            .then(r=>r.json()).then(d=>{
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
    row.querySelector('.amb-hint').textContent = chk.checked
        ? 'Suba varias fotos indicando la parte' : 'Suba 1 foto del estado';
    // Mostrar/ocultar el bloque de m² a reparar.
    const bloque = row.querySelector('.amb-reparacion');
    if (bloque) bloque.style.display = chk.checked ? 'block' : 'none';
    await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'guardar_ambiente', ambiente_id: ambId, necesita_reparacion: chk.checked?1:0 })
    });
}

/* Guarda los m² de todas las superficies de un ambiente y recalcula materiales. */
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

/* Pide al servidor el cálculo de materiales de este ambiente y lo muestra. */
async function recalcularMateriales(row) {
    const m2 = {};
    row.querySelectorAll('[data-sup]').forEach(inp => {
        const v = parseFloat(inp.value) || 0;
        if (v > 0) m2[inp.dataset.sup] = v;
    });
    const cont = row.querySelector('.amb-materiales');
    if (!cont) return;
    if (Object.keys(m2).length === 0) { cont.innerHTML = ''; return; }
    const res = await fetch(URL_BASE + 'calcular_materiales.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ m2 })
    });
    const data = await res.json();
    if (data.ok) {
        const items = Object.entries(data.materiales).map(([m,c]) =>
            `<span style="display:inline-block;background:#eef2fb;border-radius:14px;padding:2px 9px;margin:2px;">${m}: <b>${c}</b></span>`
        ).join('');
        cont.innerHTML = '<div style="margin-top:4px;"><i class="bi bi-box-seam"></i> Materiales estimados: ' + items + '</div>';
    }
}

function fotoAmbiente(btn, ambId) {
    const row = btn.closest('.amb-row');
    const necesitaReparar = row.querySelector('.amb-reparar').checked;
    // Sin reparación: 1 sola foto. Si ya tiene una, avisar.
    if (!necesitaReparar && row.querySelectorAll('.amb-fotos img').length >= 1) {
        alert('Este ambiente no necesita reparación: basta con una foto del estado. Marque "Necesita reparación" si quiere agregar más.');
        return;
    }
    let parte = null;
    if (necesitaReparar) {
        parte = prompt('¿Qué parte muestra la foto? (ej: pared norte, closet, techo, piso)');
        if (parte === null) return;
    }
    _fotoDestino = { nivel:'ambiente', refId: ambId, parte, row };
    document.getElementById('rec-file-input').click();
}

function agregarMiniFoto(cont, f) {
    const parte = f.parte ? '<div style="font-size:10px;color:#55617f;text-align:center;">'+f.parte+'</div>' : '';
    cont.insertAdjacentHTML('beforeend',
        '<div style="text-align:center;"><img src="'+f.ruta+'" style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #d8dce6;">'+parte+'</div>');
}

// Si venimos de guardar el paso 1, abrir directo el paso 2.
<?php if (($_GET['paso'] ?? '') === '2'): ?>
irPaso(2);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
