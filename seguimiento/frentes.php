<?php
/**
 * FRENTES DE TRABAJO — gestión completa.
 *
 * Cada frente está numerado (Frente de Trabajo 1, 2, 3…), cubre una o
 * varias parroquias, tiene un equipo de supervisión y sus cuadrillas.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeEditar = puede('seguimiento', 'editar');

$estadoUsr = estadoDelUsuario() ?: 'Distrito Capital';
$frentes   = frentesNumerados($estadoUsr);
$progreso  = [];
foreach (frenteProgreso($estadoUsr) as $p) $progreso[(int)$p['id']] = $p;

// Parroquias disponibles para asignar.
$parroquiasDisp = parroquiasDelUsuario();
if (!$parroquiasDisp) {
    $geo = @file_get_contents(__DIR__ . '/../assets/geo/parroquias/distrito_capital.geojson');
    if ($geo && ($j = json_decode($geo, true))) {
        foreach ($j['features'] ?? [] as $f) {
            $p = $f['properties']['parroquia'] ?? '';
            if ($p !== '') $parroquiasDisp[] = $p;
        }
        sort($parroquiasDisp);
    }
}
$entes = segEntes(usuarioEsMaster() ? null : $estadoUsr);

$pageTitle    = 'Frentes de trabajo';
$pageSubtitle = count($frentes) . ' frentes activos';
$activeModule = 'frentes';
include __DIR__ . '/../includes/header.php';
?>
<style>
.fr-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           margin-bottom:16px; overflow:hidden; }
.fr-cab { background:#22366F; color:#fff; padding:14px 18px; display:flex;
          align-items:center; gap:12px; flex-wrap:wrap; }
.fr-num { background:#C9A227; color:#22366F; width:42px; height:42px; border-radius:10px;
          display:flex; align-items:center; justify-content:center; font-size:20px;
          font-weight:800; flex-shrink:0; }
.fr-body { padding:16px 18px; }
.fr-sec { margin-bottom:16px; }
.fr-sec:last-child { margin-bottom:0; }
.fr-sec-tit { font-size:11.5px; text-transform:uppercase; letter-spacing:.4px;
              color:#55617f; font-weight:700; margin-bottom:8px;
              display:flex; align-items:center; gap:6px; }
.fr-chip { background:#eef2fb; color:#22366F; border-radius:20px; padding:5px 12px;
           font-size:12.5px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
.fr-persona { display:flex; align-items:center; gap:10px; padding:8px 4px;
              border-bottom:1px solid #f0f2f7; font-size:13px; }
.fr-persona:last-child { border-bottom:0; }
.fr-cuad { border:1px solid #e5e8f0; border-radius:10px; padding:11px 13px; margin-bottom:8px; }
.fr-vacio { color:#97a0b8; font-size:12.5px; font-style:italic; }
.fr-form { background:#f7f9fd; border-radius:10px; padding:14px 16px; margin-bottom:16px; }
@media (max-width: 640px) {
    .fr-cab { padding:12px 14px; }
    .fr-body { padding:14px; }
}
</style>

<?php if ($puedeEditar): ?>
<!-- Crear frente -->
<div class="fr-card">
  <div class="fr-body">
    <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
      <i class="bi bi-plus-circle-fill"></i> Crear un frente de trabajo
    </div>
    <div class="fr-form">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="width:120px;margin:0;">
          <label class="text-sm">Número</label>
          <input type="number" id="nf-numero" class="form-control"
                 value="<?= frenteSiguienteNumero($estadoUsr) ?>" min="1">
        </div>
        <div class="field" style="flex:1;min-width:170px;margin:0;">
          <label class="text-sm">Nombre <span class="text-muted">(opcional)</span></label>
          <input type="text" id="nf-nombre" class="form-control" placeholder="Ej: Frente Centro">
        </div>
        <div class="field" style="flex:1;min-width:170px;margin:0;">
          <label class="text-sm">Ente responsable</label>
          <select id="nf-ente" class="form-control">
            <option value="">— Ninguno —</option>
            <?php foreach ($entes as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= e(mb_strtoupper($e['nombre'], 'UTF-8')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" onclick="crearFrente()">
          <i class="bi bi-check-lg"></i> Crear
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$frentes): ?>
<div class="fr-card">
  <div class="fr-body" style="text-align:center;padding:40px 20px;">
    <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-diagram-3"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">Todavía no hay frentes de trabajo</h3>
    <p class="text-muted" style="margin:0;">
      Cree el primero arriba. Cada frente cubre una o varias parroquias
      y agrupa sus cuadrillas.
    </p>
  </div>
</div>
<?php endif; ?>

<?php foreach ($frentes as $f):
    $fid = (int)$f['id'];
    $pr = $progreso[$fid] ?? ['obras' => 0, 'avance' => 0, 'culminadas' => 0];
    $av = (int)$pr['avance'];
    $colAv = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#a8871f' : '#97a0b8'));
?>
<div class="fr-card" id="frente-<?= $fid ?>">
  <div class="fr-cab">
    <div class="fr-num"><?= (int)$f['numero'] ?></div>
    <div style="flex:1;min-width:0;">
      <div style="font-weight:700;font-size:16px;">
        Frente de Trabajo <?= (int)$f['numero'] ?>
        <?php if (!empty($f['nombre'])): ?>
          <span style="font-weight:500;opacity:.85;">· <?= e($f['nombre']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($f['ente_nombre'])): ?>
      <div style="font-size:12px;opacity:.8;"><?= e($f['ente_nombre']) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;">
      <div style="font-size:20px;font-weight:800;"><?= (int)$pr['obras'] ?></div>
      <div style="font-size:10.5px;opacity:.8;text-transform:uppercase;">obras</div>
    </div>
    <?php if ((int)$pr['obras'] > 0): ?>
    <div style="text-align:right;min-width:60px;">
      <div style="font-size:20px;font-weight:800;color:<?= $av >= 75 ? '#9fe0a8' : '#f0d590' ?>;"><?= $av ?>%</div>
      <div style="font-size:10.5px;opacity:.8;text-transform:uppercase;">avance</div>
    </div>
    <?php endif; ?>
    <?php if ($puedeEditar): ?>
    <button onclick="desactivarFrente(<?= $fid ?>)" title="Desactivar frente"
            style="background:transparent;border:0;color:#ffffff88;font-size:17px;cursor:pointer;">
      <i class="bi bi-x-circle"></i>
    </button>
    <?php endif; ?>
  </div>

  <div class="fr-body">

    <!-- Parroquias -->
    <div class="fr-sec">
      <div class="fr-sec-tit"><i class="bi bi-geo-alt-fill"></i> Parroquias que cubre</div>
      <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
        <?php if ($f['parroquias']): foreach ($f['parroquias'] as $p): ?>
          <span class="fr-chip">
            <?= e($p) ?>
            <?php if ($puedeEditar): ?>
            <a href="#" onclick="quitarParroquia(<?= $fid ?>,'<?= e(addslashes($p)) ?>');return false;"
               style="color:#97a0b8;text-decoration:none;">&times;</a>
            <?php endif; ?>
          </span>
        <?php endforeach; else: ?>
          <span class="fr-vacio">Sin parroquias asignadas</span>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <select onchange="agregarParroquia(<?= $fid ?>, this)" class="form-control"
                style="width:auto;min-width:170px;font-size:13px;padding:5px 9px;">
          <option value="">+ Agregar parroquia</option>
          <?php foreach ($parroquiasDisp as $p):
              if (in_array($p, $f['parroquias'], true)) continue; ?>
          <option value="<?= e($p) ?>"><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
    </div>

    <!-- Supervisión -->
    <div class="fr-sec">
      <div class="fr-sec-tit"><i class="bi bi-person-badge-fill"></i> Equipo de supervisión</div>
      <?php if ($f['supervisores']): foreach ($f['supervisores'] as $s): ?>
        <div class="fr-persona">
          <i class="bi bi-person-fill" style="color:#2d4488;"></i>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#2a3140;"><?= e($s['nombre']) ?></div>
            <div style="font-size:11.5px;color:#5b6478;">
              <?= e($s['cargo'] ?: 'Supervisor') ?>
              <?php if (!empty($s['telefono'])): ?> · <?= e($s['telefono']) ?><?php endif; ?>
            </div>
          </div>
          <?php if ($puedeEditar): ?>
          <button onclick="quitarSupervisor(<?= (int)$s['id'] ?>)"
                  style="background:transparent;border:0;color:#c4c9d6;cursor:pointer;font-size:15px;">
            <i class="bi bi-x-circle"></i></button>
          <?php endif; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="fr-vacio">Sin supervisores registrados</div>
      <?php endif; ?>

      <?php if ($puedeEditar): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:9px;">
        <input type="text" id="sup-nom-<?= $fid ?>" class="form-control"
               style="flex:2;min-width:150px;font-size:13px;" placeholder="Nombre del supervisor">
        <input type="text" id="sup-cargo-<?= $fid ?>" class="form-control"
               style="flex:1;min-width:120px;font-size:13px;" placeholder="Cargo">
        <input type="tel" id="sup-tel-<?= $fid ?>" class="form-control"
               style="flex:1;min-width:120px;font-size:13px;" placeholder="Teléfono" inputmode="tel">
        <button class="btn btn-outline btn-sm" onclick="agregarSupervisor(<?= $fid ?>)">
          <i class="bi bi-plus-lg"></i> Agregar
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Cuadrillas -->
    <div class="fr-sec">
      <div class="fr-sec-tit">
        <i class="bi bi-people-fill"></i> Cuadrillas de trabajo
        <span style="background:#eef2fb;color:#22366F;border-radius:10px;padding:1px 8px;
                     font-size:11px;"><?= count($f['cuadrillas']) ?></span>
      </div>

      <?php if ($f['cuadrillas']): foreach ($f['cuadrillas'] as $c): ?>
      <div class="fr-cuad">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px;">
          <span style="background:#2d4488;color:#fff;width:26px;height:26px;border-radius:7px;
                       display:flex;align-items:center;justify-content:center;font-weight:800;
                       font-size:12.5px;"><?= (int)$c['numero'] ?></span>
          <div style="flex:1;min-width:0;">
            <span style="font-weight:600;color:#2a3140;font-size:13.5px;">
              Cuadrilla <?= (int)$c['numero'] ?><?php if (!empty($c['nombre'])): ?> · <?= e($c['nombre']) ?><?php endif; ?>
            </span>
            <?php if (!empty($c['especialidad'])): ?>
            <span style="font-size:11.5px;color:#5b6478;"> — <?= e($c['especialidad']) ?></span>
            <?php endif; ?>
          </div>
          <span style="font-size:11.5px;color:#767c94;"><?= count($c['integrantes']) ?> persona(s)</span>
          <?php if ($puedeEditar): ?>
          <button onclick="quitarCuadrilla(<?= (int)$c['id'] ?>)"
                  style="background:transparent;border:0;color:#c4c9d6;cursor:pointer;">
            <i class="bi bi-x-circle"></i></button>
          <?php endif; ?>
        </div>

        <?php foreach ($c['integrantes'] as $i): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0 5px 34px;font-size:12.5px;">
          <?php if (!empty($i['es_jefe'])): ?>
            <i class="bi bi-star-fill" style="color:#C9A227;font-size:11px;" title="Jefe de cuadrilla"></i>
          <?php else: ?>
            <i class="bi bi-dot" style="color:#97a0b8;"></i>
          <?php endif; ?>
          <span style="flex:1;color:#2a3140;"><?= e($i['nombre']) ?></span>
          <?php if (!empty($i['oficio'])): ?>
          <span style="font-size:11px;color:#767c94;"><?= e($i['oficio']) ?></span>
          <?php endif; ?>
          <?php if ($puedeEditar): ?>
          <button onclick="quitarIntegrante(<?= (int)$i['id'] ?>)"
                  style="background:transparent;border:0;color:#dbe0ec;cursor:pointer;font-size:13px;">
            <i class="bi bi-x"></i></button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($puedeEditar): ?>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:7px;padding-left:34px;">
          <input type="text" id="int-nom-<?= (int)$c['id'] ?>" class="form-control"
                 style="flex:2;min-width:130px;font-size:12.5px;padding:5px 9px;" placeholder="Nombre">
          <input type="text" id="int-ofi-<?= (int)$c['id'] ?>" class="form-control"
                 style="flex:1;min-width:100px;font-size:12.5px;padding:5px 9px;" placeholder="Oficio">
          <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#5b6478;">
            <input type="checkbox" id="int-jefe-<?= (int)$c['id'] ?>"> Jefe
          </label>
          <button class="btn btn-outline btn-sm" onclick="agregarIntegrante(<?= (int)$c['id'] ?>)">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
        <div class="fr-vacio" style="margin-bottom:9px;">Sin cuadrillas creadas</div>
      <?php endif; ?>

      <?php if ($puedeEditar): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:9px;">
        <input type="text" id="cuad-nom-<?= $fid ?>" class="form-control"
               style="flex:2;min-width:150px;font-size:13px;" placeholder="Nombre (opcional)">
        <input type="text" id="cuad-esp-<?= $fid ?>" class="form-control"
               style="flex:1;min-width:140px;font-size:13px;" placeholder="Especialidad">
        <button class="btn btn-outline btn-sm" onclick="agregarCuadrilla(<?= $fid ?>)">
          <i class="bi bi-plus-lg"></i> Crear cuadrilla
        </button>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
<?php endforeach; ?>

<script>
const URL_F = '<?= APP_URL_BASE ?>seguimiento/guardar_frente.php';

async function api(datos) {
    try {
        const res = await fetch(URL_F, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(datos), credentials: 'same-origin'
        });
        const d = await res.json();
        if (!d.ok) { alert(d.mensaje || 'No se pudo guardar.'); return null; }
        return d;
    } catch (e) {
        alert('Sin conexión. Intente de nuevo.');
        return null;
    }
}

async function crearFrente() {
    const numero = parseInt(document.getElementById('nf-numero').value) || 0;
    if (numero < 1) { alert('Indique el número del frente.'); return; }
    const d = await api({
        accion: 'crear_frente', numero: numero,
        nombre: document.getElementById('nf-nombre').value,
        ente_id: document.getElementById('nf-ente').value,
    });
    if (d) location.reload();
}

async function desactivarFrente(id) {
    if (!confirm('¿Desactivar este frente?\n\nLas obras asignadas quedarán sin frente.')) return;
    const d = await api({ accion: 'desactivar_frente', frente_id: id });
    if (d) location.reload();
}

async function agregarParroquia(frenteId, sel) {
    if (!sel.value) return;
    const d = await api({ accion: 'agregar_parroquia', frente_id: frenteId, parroquia: sel.value });
    if (d) location.reload();
}

async function quitarParroquia(frenteId, parroquia) {
    const d = await api({ accion: 'quitar_parroquia', frente_id: frenteId, parroquia: parroquia });
    if (d) location.reload();
}

async function agregarSupervisor(frenteId) {
    const nom = document.getElementById('sup-nom-' + frenteId).value.trim();
    if (!nom) { alert('Indique el nombre del supervisor.'); return; }
    const d = await api({
        accion: 'agregar_supervisor', frente_id: frenteId, nombre: nom,
        cargo: document.getElementById('sup-cargo-' + frenteId).value,
        telefono: document.getElementById('sup-tel-' + frenteId).value,
    });
    if (d) location.reload();
}

async function quitarSupervisor(id) {
    const d = await api({ accion: 'quitar_supervisor', supervisor_id: id });
    if (d) location.reload();
}

async function agregarCuadrilla(frenteId) {
    const d = await api({
        accion: 'agregar_cuadrilla', frente_id: frenteId,
        nombre: document.getElementById('cuad-nom-' + frenteId).value,
        especialidad: document.getElementById('cuad-esp-' + frenteId).value,
    });
    if (d) location.reload();
}

async function quitarCuadrilla(id) {
    if (!confirm('¿Eliminar esta cuadrilla y sus integrantes?')) return;
    const d = await api({ accion: 'quitar_cuadrilla', cuadrilla_id: id });
    if (d) location.reload();
}

async function agregarIntegrante(cuadrillaId) {
    const nom = document.getElementById('int-nom-' + cuadrillaId).value.trim();
    if (!nom) { alert('Indique el nombre.'); return; }
    const d = await api({
        accion: 'agregar_integrante', cuadrilla_id: cuadrillaId, nombre: nom,
        oficio: document.getElementById('int-ofi-' + cuadrillaId).value,
        es_jefe: document.getElementById('int-jefe-' + cuadrillaId).checked ? 1 : 0,
    });
    if (d) location.reload();
}

async function quitarIntegrante(id) {
    const d = await api({ accion: 'quitar_integrante', integrante_id: id });
    if (d) location.reload();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
