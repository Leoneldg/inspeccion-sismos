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
frenteRespAsegurar();
repAsegurarTablas();

$misParroquias = parroquiasDelUsuario();
$esResponsable = usuarioLimitadoAParroquia();

// Responsables disponibles para vincular.
$responsables = [];
try {
    $responsables = db()->query(
        'SELECT id, nombre FROM representantes WHERE activo = 1 ORDER BY nombre'
    )->fetchAll();
} catch (Throwable $e) {}
$nombresResp = [];
foreach ($responsables as $r) $nombresResp[(int)$r['id']] = $r['nombre'];

// Frentes: los de sus parroquias si es responsable, todos si es admin.
$frentes = [];
try {
    if ($esResponsable && $misParroquias) {
        $marcas = []; $params = [];
        foreach ($misParroquias as $i => $pp) { $marcas[] = ':p'.$i; $params['p'.$i] = $pp; }
        $stF = db()->prepare('SELECT * FROM frente WHERE activo = 1 AND parroquia IN ('
            . implode(',', $marcas) . ') ORDER BY numero');
        $stF->execute($params);
        $frentes = frenteAdjuntarBrigadas($stF->fetchAll());
    } else {
        $frentes = frenteAdjuntarBrigadas(
            db()->query('SELECT * FROM frente WHERE activo = 1 ORDER BY numero')->fetchAll()
        );
    }
} catch (Throwable $e) {}

// Totales
$totFrentes = count($frentes);
$totBrigadas = 0; $totObras = 0; $porParroquia = [];
foreach ($frentes as $ff) {
    $totBrigadas += count($ff['brigadas'] ?? []);
    $totObras += (int)($ff['obras'] ?? 0);
    $pp = $ff['parroquia'] ?: 'Sin parroquia';
    if (!isset($porParroquia[$pp])) $porParroquia[$pp] = ['frentes'=>0,'brigadas'=>0];
    $porParroquia[$pp]['frentes']++;
    $porParroquia[$pp]['brigadas'] += count($ff['brigadas'] ?? []);
}
ksort($porParroquia);
$siguiente = frenteSiguienteGlobal();
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

<!-- TOTALES -->
<div class="fr-card">
  <div class="fr-body">
    <div style="font-weight:700;color:#22366F;margin-bottom:12px;">
      <i class="bi bi-diagram-3-fill"></i>
      <?= $esResponsable ? 'Mis frentes de trabajo' : 'Frentes de trabajo' ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <div style="flex:1;min-width:120px;text-align:center;padding:15px 10px;border-radius:11px;
                  border:1px solid #22366F33;background:#22366F0a;">
        <div style="font-size:32px;font-weight:800;color:#22366F;line-height:1;"><?= $totFrentes ?></div>
        <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">Frentes de trabajo</div>
      </div>
      <div style="flex:1;min-width:120px;text-align:center;padding:15px 10px;border-radius:11px;
                  border:1px solid #2d448833;background:#2d44880a;">
        <div style="font-size:32px;font-weight:800;color:#2d4488;line-height:1;"><?= $totBrigadas ?></div>
        <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">Brigadas en total</div>
      </div>
      <div style="flex:1;min-width:120px;text-align:center;padding:15px 10px;border-radius:11px;
                  border:1px solid #C9A22733;background:#C9A2270a;">
        <div style="font-size:32px;font-weight:800;color:#a8871f;line-height:1;"><?= $totObras ?></div>
        <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">Edificaciones</div>
      </div>
      <?php if (count($porParroquia) > 1): ?>
      <div style="flex:1;min-width:120px;text-align:center;padding:15px 10px;border-radius:11px;
                  border:1px solid #2E7D3233;background:#2E7D320a;">
        <div style="font-size:32px;font-weight:800;color:#2E7D32;line-height:1;"><?= count($porParroquia) ?></div>
        <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">Parroquias</div>
      </div>
      <?php endif; ?>
    </div>

    <?php if (count($porParroquia) > 1): ?>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eef0f5;">
      <div style="font-size:11.5px;text-transform:uppercase;color:#55617f;font-weight:700;margin-bottom:8px;">
        Por parroquia
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($porParroquia as $parr => $d): ?>
        <span style="background:#f7f9fd;border:1px solid #e5e8f0;border-radius:9px;padding:7px 13px;font-size:12.5px;">
          <strong style="color:#22366F;"><?= e($parr) ?></strong><br>
          <span style="color:#5b6478;">
            <?= $d['frentes'] ?> frente<?= $d['frentes'] === 1 ? '' : 's' ?> ·
            <?= $d['brigadas'] ?> brigada<?= $d['brigadas'] === 1 ? '' : 's' ?>
          </span>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- CREAR FRENTE -->
<?php if ($puedeEditar): ?>
<div class="fr-card">
  <div class="fr-body">
    <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
      <i class="bi bi-plus-circle-fill"></i> Crear frentes de trabajo
    </div>
    <div class="fr-form">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="flex:1;min-width:180px;margin:0;">
          <label class="text-sm">Parroquia *</label>
          <select id="nf-parroquia" class="form-control">
            <option value="">— Seleccione —</option>
            <?php
            $parrDisp = $misParroquias;
            if (!$parrDisp) {
                $geo = @file_get_contents(__DIR__ . '/../assets/geo/parroquias/distrito_capital.geojson');
                if ($geo && ($j = json_decode($geo, true))) {
                    foreach ($j['features'] ?? [] as $gf) {
                        $gp = $gf['properties']['parroquia'] ?? '';
                        if ($gp !== '') $parrDisp[] = $gp;
                    }
                    sort($parrDisp);
                }
            }
            foreach ($parrDisp as $pd): ?>
            <option value="<?= e($pd) ?>"><?= e(mb_strtoupper($pd, 'UTF-8')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1;min-width:180px;margin:0;">
          <label class="text-sm">Responsable de parroquia</label>
          <select id="nf-responsable" class="form-control">
            <option value="">— Ninguno —</option>
            <?php foreach ($responsables as $r): ?>
            <option value="<?= (int)$r['id'] ?>"><?= e($r['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="width:120px;margin:0;">
          <label class="text-sm">Cuántos</label>
          <input type="number" id="nf-cantidad" class="form-control" value="1" min="1" max="20">
        </div>
        <button class="btn btn-primary" onclick="crearFrentes()">
          <i class="bi bi-check-lg"></i> Crear
        </button>
      </div>
      <div class="text-sm text-muted" style="margin-top:8px;">
        <i class="bi bi-info-circle"></i>
        El número se asigna solo y es correlativo:
        el próximo será el <strong>Frente de Trabajo <?= $siguiente ?></strong>.
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- LISTADO -->
<?php if (!$frentes): ?>
<div class="fr-card">
  <div class="fr-body" style="text-align:center;padding:36px 20px;">
    <div style="font-size:42px;color:#c4c9d6;"><i class="bi bi-diagram-3"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">Todavía no hay frentes de trabajo</h3>
    <p class="text-muted" style="margin:0;">Cree el primero arriba.</p>
  </div>
</div>
<?php else: ?>

<div class="fr-card">
  <div class="fr-body">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <div style="font-weight:700;color:#22366F;"><i class="bi bi-list-ol"></i> Detalle</div>
      <input type="text" id="ft-buscar" class="form-control" style="width:210px;"
             placeholder="Buscar frente o parroquia…" oninput="filtrarFrentes()">
    </div>

    <div id="ft-lista">
    <?php foreach ($frentes as $f):
      $fid = (int)$f['id'];
      $resp = $nombresResp[(int)($f['responsable_id'] ?? 0)] ?? null;
    ?>
    <div class="ft-frente" style="border:1px solid #e5e8f0;border-radius:11px;margin-bottom:10px;overflow:hidden;"
         data-txt="<?= e(mb_strtolower('frente ' . $f['numero'] . ' ' . ($f['parroquia'] ?? '') . ' ' . ($resp ?? ''), 'UTF-8')) ?>">

      <div style="display:flex;align-items:center;gap:12px;padding:12px 15px;background:#f7f9fd;flex-wrap:wrap;">
        <div style="background:#22366F;color:#fff;width:44px;height:44px;border-radius:10px;
                    display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:800;">
          <?= (int)$f['numero'] ?>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#22366F;font-size:15.5px;">
            Frente de Trabajo <?= (int)$f['numero'] ?>
          </div>
          <div style="font-size:12px;color:#5b6478;">
            <i class="bi bi-geo-alt"></i> <?= e($f['parroquia'] ?: 'Sin parroquia') ?>
            <?php if ($resp): ?> · <i class="bi bi-person"></i> <?= e($resp) ?><?php endif; ?>
          </div>
        </div>
        <div style="text-align:center;min-width:62px;">
          <div style="font-size:17px;font-weight:800;color:#2d4488;"><?= count($f['brigadas'] ?? []) ?></div>
          <div style="font-size:10px;color:#767c94;text-transform:uppercase;">brigadas</div>
        </div>
        <div style="text-align:center;min-width:62px;">
          <div style="font-size:17px;font-weight:800;color:#a8871f;"><?= (int)($f['obras'] ?? 0) ?></div>
          <div style="font-size:10px;color:#767c94;text-transform:uppercase;">obras</div>
        </div>
        <?php if ($puedeEditar): ?>
        <button onclick="quitarFrente(<?= $fid ?>, <?= (int)$f['numero'] ?>)" title="Desactivar"
                style="background:transparent;border:0;color:#c4c9d6;font-size:17px;cursor:pointer;">
          <i class="bi bi-x-circle"></i>
        </button>
        <?php endif; ?>
      </div>

      <div style="padding:11px 15px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:11.5px;color:#55617f;font-weight:600;">Brigadas:</span>
        <?php if (!empty($f['brigadas'])): foreach ($f['brigadas'] as $b): ?>
        <span style="background:#eef2fb;color:#22366F;border-radius:9px;padding:7px 13px;
                     font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;">
          <?= (int)$b['numero'] ?>
          <span style="font-weight:400;font-size:11px;color:#5b6478;">
            <?= (int)($b['obras'] ?? 0) ?> obra<?= (int)($b['obras'] ?? 0) === 1 ? '' : 's' ?>
          </span>
          <?php if ($puedeEditar): ?>
          <a href="#" onclick="quitarBrigada(<?= (int)$b['id'] ?>, <?= (int)$b['numero'] ?>);return false;"
             style="color:#97a0b8;text-decoration:none;">&times;</a>
          <?php endif; ?>
        </span>
        <?php endforeach; else: ?>
        <span style="font-size:12.5px;color:#97a0b8;font-style:italic;">Sin brigadas</span>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <button class="btn btn-outline btn-sm" style="font-size:12px;padding:4px 11px;"
                onclick="agregarBrigada(<?= $fid ?>)">
          <i class="bi bi-plus-lg"></i> Brigada
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <p id="ft-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
      Ninguno coincide con la búsqueda.
    </p>
  </div>
</div>
<?php endif; ?>

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

// Crea uno o varios frentes seguidos; el número lo asigna el servidor.
async function crearFrentes() {
    const parr = document.getElementById('nf-parroquia').value;
    if (!parr) { alert('Seleccione la parroquia.'); return; }
    const cant = parseInt(document.getElementById('nf-cantidad').value) || 1;
    const resp = document.getElementById('nf-responsable').value;

    const creados = [];
    for (let i = 0; i < cant; i++) {
        const d = await api({ accion: 'crear_frente_resp', parroquia: parr, responsable_id: resp });
        if (!d) break;
        creados.push(d.numero);
    }
    if (creados.length) {
        alert('Creado(s): Frente de Trabajo ' + creados.join(', ') + '.');
        location.reload();
    }
}

async function quitarFrente(id, numero) {
    if (!confirm('¿Desactivar el Frente de Trabajo ' + numero + '?\n\n'
        + 'Sus brigadas y obras quedarán sin frente.')) return;
    const d = await api({ accion: 'desactivar_frente', frente_id: id });
    if (d) location.reload();
}

async function agregarBrigada(frenteId) {
    const d = await api({ accion: 'crear_brigada', frente_id: frenteId });
    if (d) location.reload();
}

async function quitarBrigada(id, numero) {
    if (!confirm('¿Eliminar la Brigada ' + numero + '?')) return;
    const d = await api({ accion: 'quitar_brigada', brigada_id: id });
    if (d) location.reload();
}

function filtrarFrentes() {
    const t = (document.getElementById('ft-buscar').value || '').toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('.ft-frente').forEach(f => {
        const ver = !t || (f.dataset.txt || '').includes(t);
        f.style.display = ver ? '' : 'none';
        if (ver) n++;
    });
    const v = document.getElementById('ft-vacio');
    if (v) v.style.display = n ? 'none' : '';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
