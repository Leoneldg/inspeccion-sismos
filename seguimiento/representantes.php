<?php
/**
 * Representantes por parroquia (Fase 1 de Reconstrucción).
 * Reemplaza el modelo de "entes por edificio": aquí se registran
 * representantes y se les asignan una o más parroquias.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeEditar = puede('seguimiento', 'editar');

$representantes = repListar();

// Parroquias disponibles (de las inspecciones existentes), agrupadas por estado.
$parroquiasPorEstado = [];
foreach (db()->query(
    "SELECT DISTINCT estado, municipio, parroquia FROM inspecciones
     WHERE parroquia IS NOT NULL AND parroquia <> '' ORDER BY estado, parroquia"
)->fetchAll() as $row) {
    $parroquiasPorEstado[$row['estado']][] = $row;
}

$pageTitle    = 'Representantes por parroquia';
$pageSubtitle = 'Asigne uno o más representantes responsables a cada parroquia';
$activeModule = 'representantes';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .rep-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width:900px){ .rep-grid{ grid-template-columns:1fr; } }
    .rep-card { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:18px 20px; }
    .rep-card h3 { margin:0 0 12px; font-size:15px; color:#22366F; }
    .rep-item { border:1px solid #e8ebf3; border-radius:10px; padding:12px 14px; margin-bottom:10px; }
    .rep-item .nom { font-weight:600; color:#2a3140; font-size:14px; }
    .rep-item .meta { font-size:12px; color:#767c94; margin-top:2px; }
    .rep-parr { display:inline-block; background:#eef2fb; color:#2d4488; border-radius:20px;
                padding:2px 10px; font-size:11.5px; margin:3px 3px 0 0; }
    .rep-empty { color:#9aa1b4; font-style:italic; padding:14px; text-align:center; }
    .rep-parr-list { max-height:220px; overflow-y:auto; border:1px solid #e8ebf3; border-radius:8px; padding:8px; margin-top:6px; }
    .rep-parr-opt { display:flex; align-items:center; gap:7px; padding:4px 6px; border-radius:6px; font-size:13px; }
    .rep-parr-opt:hover { background:#f4f6fc; }
    .rep-estado-lbl { font-weight:600; color:#55617f; font-size:11px; text-transform:uppercase; margin:8px 0 3px; }
</style>

<div class="rep-grid">

    <!-- Lista de representantes -->
    <div class="rep-card">
        <h3><i class="bi bi-people-fill"></i> Representantes registrados (<?= count($representantes) ?>)</h3>
        <?php if (!$representantes): ?>
            <div class="rep-empty">Aún no hay representantes. Registre el primero en el panel de la derecha.</div>
        <?php else: ?>
            <?php foreach ($representantes as $r): ?>
                <div class="rep-item">
                    <div class="nom"><?= e($r['nombre']) ?><?php if ($r['cargo']): ?> <span class="meta">· <?= e($r['cargo']) ?></span><?php endif; ?></div>
                    <div class="meta">
                        <?= $r['cedula'] ? 'C.I. ' . e($r['cedula']) : '' ?>
                        <?= $r['telefono'] ? ' · ' . e($r['telefono']) : '' ?>
                        <?= $r['email'] ? ' · ' . e($r['email']) : '' ?>
                    </div>
                    <div style="margin-top:6px;">
                        <?php if ($r['parroquias']): ?>
                            <?php foreach ($r['parroquias'] as $p): ?>
                                <span class="rep-parr"><i class="bi bi-geo-alt"></i> <?= e($p['parroquia']) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="meta">Sin parroquias asignadas</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($puedeEditar): ?>
                    <div style="margin-top:8px;">
                        <button class="btn btn-outline btn-sm" onclick="editarRep(<?= $r['id'] ?>)"><i class="bi bi-pencil"></i> Editar parroquias</button>
                        <button class="btn btn-outline btn-sm" onclick="desactivarRep(<?= $r['id'] ?>, '<?= e(addslashes($r['nombre'])) ?>')"><i class="bi bi-trash3"></i></button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Formulario para crear/asignar -->
    <?php if ($puedeEditar): ?>
    <div class="rep-card">
        <h3><i class="bi bi-person-plus-fill"></i> <span id="form-titulo">Nuevo representante</span></h3>
        <form id="form-rep" onsubmit="return guardarRep(event)">
            <input type="hidden" id="rep-id" value="">
            <div class="field">
                <label class="text-sm">Nombre completo *</label>
                <input type="text" id="rep-nombre" class="form-control" required>
            </div>
            <div class="flex gap-8">
                <div class="field" style="flex:1;">
                    <label class="text-sm">Cédula</label>
                    <input type="text" id="rep-cedula" class="form-control">
                </div>
                <div class="field" style="flex:1;">
                    <label class="text-sm">Teléfono</label>
                    <input type="text" id="rep-telefono" class="form-control">
                </div>
            </div>
            <div class="flex gap-8">
                <div class="field" style="flex:1;">
                    <label class="text-sm">Cargo</label>
                    <input type="text" id="rep-cargo" class="form-control" placeholder="Ej: Líder de brigada">
                </div>
                <div class="field" style="flex:1;">
                    <label class="text-sm">Email</label>
                    <input type="email" id="rep-email" class="form-control">
                </div>
            </div>

            <label class="text-sm" style="display:block;margin-top:8px;font-weight:600;">Parroquias a cargo (una o varias)</label>
            <div class="rep-parr-list" id="rep-parroquias">
                <?php foreach ($parroquiasPorEstado as $estado => $parrs): ?>
                    <div class="rep-estado-lbl"><?= e($estado) ?></div>
                    <?php foreach ($parrs as $p): ?>
                        <label class="rep-parr-opt">
                            <input type="checkbox" class="chk-parr"
                                   value="<?= e($p['parroquia']) ?>"
                                   data-estado="<?= e($estado) ?>"
                                   data-municipio="<?= e($p['municipio'] ?? '') ?>">
                            <?= e($p['parroquia']) ?>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:12px;display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                <button type="button" class="btn btn-outline" onclick="resetForm()">Limpiar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
const REPS = <?= json_encode($representantes, JSON_UNESCAPED_UNICODE) ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';

function parroquiasMarcadas() {
    return Array.from(document.querySelectorAll('.chk-parr:checked')).map(c => ({
        parroquia: c.value, estado: c.dataset.estado, municipio: c.dataset.municipio
    }));
}

async function guardarRep(ev) {
    ev.preventDefault();
    const parrs = parroquiasMarcadas();
    if (parrs.length === 0) { alert('Seleccione al menos una parroquia.'); return false; }
    const payload = {
        id: document.getElementById('rep-id').value,
        nombre: document.getElementById('rep-nombre').value,
        cedula: document.getElementById('rep-cedula').value,
        telefono: document.getElementById('rep-telefono').value,
        cargo: document.getElementById('rep-cargo').value,
        email: document.getElementById('rep-email').value,
        parroquias: parrs,
    };
    const res = await fetch(URL_BASE + 'guardar_representante.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) { location.reload(); }
    else { alert(data.mensaje || 'No se pudo guardar.'); }
    return false;
}

function editarRep(id) {
    const r = REPS.find(x => x.id == id);
    if (!r) return;
    document.getElementById('rep-id').value = r.id;
    document.getElementById('rep-nombre').value = r.nombre || '';
    document.getElementById('rep-cedula').value = r.cedula || '';
    document.getElementById('rep-telefono').value = r.telefono || '';
    document.getElementById('rep-cargo').value = r.cargo || '';
    document.getElementById('rep-email').value = r.email || '';
    document.getElementById('form-titulo').textContent = 'Editar: ' + r.nombre;
    // Marcar sus parroquias
    const suyas = (r.parroquias || []).map(p => p.parroquia);
    document.querySelectorAll('.chk-parr').forEach(c => { c.checked = suyas.includes(c.value); });
    window.scrollTo({top:0, behavior:'smooth'});
}

function resetForm() {
    document.getElementById('form-rep').reset();
    document.getElementById('rep-id').value = '';
    document.getElementById('form-titulo').textContent = 'Nuevo representante';
    document.querySelectorAll('.chk-parr').forEach(c => c.checked = false);
}

async function desactivarRep(id, nombre) {
    if (!confirm('¿Quitar al representante "' + nombre + '"?')) return;
    const res = await fetch(URL_BASE + 'guardar_representante.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ accion: 'desactivar', id })
    });
    const data = await res.json();
    if (data.ok) location.reload();
    else alert(data.mensaje || 'No se pudo.');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
