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

<!-- ============ PASO 3: APARTAMENTOS (próximamente) ============ -->
<div class="wz-panel hidden" id="paso-3">
    <h3>Apartamentos</h3>
    <p class="sub">Esta sección se construye en el siguiente paso del desarrollo.</p>
    <button type="button" class="btn btn-outline" onclick="irPaso(2)"><i class="bi bi-arrow-left"></i> Volver a pisos</button>
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

    const cont = _fotoDestino.row.querySelector('.elem-fotos') || (() => {
        const d = document.createElement('div');
        d.className = 'elem-fotos';
        d.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;width:100%;';
        _fotoDestino.row.appendChild(d);
        return d;
    })();
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

// Si venimos de guardar el paso 1, abrir directo el paso 2.
<?php if (($_GET['paso'] ?? '') === '2'): ?>
irPaso(2);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
