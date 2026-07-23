<?php
/**
 * SELECCIÓN DE VARIOS EDIFICIOS → MATERIALES POR PISO.
 *
 * El usuario marca los edificios que quiere y genera un PDF con el
 * material consolidado, desglosado por piso (juntando los pisos
 * equivalentes de todos) y por áreas comunes.
 *
 * Uso: seguimiento/materiales_multi.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/materiales_multi.php';

requierePermiso('seguimiento', 'ver');

$parrF = trim($_GET['parroquia'] ?? '');
if ($parrF !== '' && !puedeAccederParroquia($parrF)) $parrF = '';
$filtros = $parrF !== '' ? ['parroquia' => $parrF] : [];

$edificios = matEdificiosSeleccionables($filtros);

// Parroquias disponibles para el filtro.
$parroquiasDisp = [];
foreach ($edificios as $e) {
    $pn = $e['parroquia'] ?: 'Sin parroquia';
    $parroquiasDisp[$pn] = true;
}
$parroquiasDisp = array_keys($parroquiasDisp);
sort($parroquiasDisp);

// Si se filtró por parroquia, la lista de opciones debe salir de TODAS
// (sin el filtro), para poder cambiar.
if ($parrF !== '') {
    $todos = matEdificiosSeleccionables([]);
    $tmp = [];
    foreach ($todos as $e) { $tmp[$e['parroquia'] ?: 'Sin parroquia'] = true; }
    $parroquiasDisp = array_keys($tmp);
    sort($parroquiasDisp);
}

// Agrupar edificios por parroquia para mostrarlos ordenados.
$porParroquia = [];
foreach ($edificios as $e) {
    $pn = $e['parroquia'] ?: 'Sin parroquia';
    $porParroquia[$pn][] = $e;
}

$activeModule = 'reconstruccion';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width:900px;margin:0 auto;padding:0 12px;">

  <div style="margin:16px 0;">
    <h1 style="margin:0;font-size:22px;color:#22366F;">
      <i class="bi bi-clipboard-check-fill"></i> Materiales de varios edificios
    </h1>
    <div style="color:#5b6478;font-size:13px;">
      Marca los edificios y genera un PDF con el material que necesitan,
      desglosado por piso y áreas comunes.
    </div>
  </div>

  <form id="formMulti" method="get" action="<?= APP_URL_BASE ?>seguimiento/pdf_materiales_multi.php" target="_blank">

    <!-- Barra de acciones -->
    <div style="position:sticky;top:0;z-index:5;background:#f4f6fb;border:1px solid #e0e4ee;
                border-radius:10px;padding:10px 12px;margin-bottom:14px;
                display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select onchange="if(this.value)location.href='?parroquia='+encodeURIComponent(this.value);else location.href='?';"
                class="form-control" style="width:180px;">
          <option value="">Todas las parroquias</option>
          <?php foreach ($parroquiasDisp as $pp): ?>
          <option value="<?= e($pp) ?>" <?= $pp === $parrF ? 'selected' : '' ?>><?= e($pp) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" onclick="marcarTodos(true)" class="btn btn-light btn-sm">
          <i class="bi bi-check2-square"></i> Marcar todos
        </button>
        <button type="button" onclick="marcarTodos(false)" class="btn btn-light btn-sm">
          <i class="bi bi-square"></i> Desmarcar
        </button>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <span id="contador" style="font-size:13px;color:#5b6478;">0 seleccionados</span>
        <button type="submit" id="btnGenerar" class="btn btn-primary btn-sm" disabled
                style="white-space:nowrap;">
          <i class="bi bi-file-earmark-pdf-fill"></i> Generar PDF
        </button>
      </div>
    </div>

    <?php if (!$edificios): ?>
      <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:20px;
                  text-align:center;color:#5b6478;">
        No hay edificios con levantamiento cerrado en esta selección.
      </div>
    <?php else: ?>
      <?php foreach ($porParroquia as $parr => $lista): ?>
      <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;
                  padding:12px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <label style="font-weight:700;color:#22366F;font-size:14px;cursor:pointer;
                        display:flex;align-items:center;gap:6px;">
            <input type="checkbox" class="chk-parroquia" data-parr="<?= e($parr) ?>"
                   onchange="marcarParroquia(this)">
            <i class="bi bi-geo-alt-fill"></i> <?= e($parr) ?>
            <span style="font-weight:400;color:#5b6478;font-size:12px;">(<?= count($lista) ?>)</span>
          </label>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;">
          <?php foreach ($lista as $e):
            $col = strtolower($e['colapso']);
            $colColor = $col === 'total' ? '#A61C1C' : ($col === 'parcial' ? '#C9A227' : '#8a93a6');
          ?>
          <label style="display:flex;align-items:center;gap:8px;padding:7px 9px;
                        border:1px solid #eceef4;border-radius:8px;cursor:pointer;font-size:13px;">
            <input type="checkbox" name="edificios[]" value="<?= (int)$e['edificio_id'] ?>"
                   class="chk-edificio" data-parr="<?= e($parr) ?>" onchange="actualizar()">
            <span style="flex:1;">
              <span style="font-weight:600;"><?= e($e['nombre']) ?></span><br>
              <span style="color:#8a93a6;font-size:11.5px;">
                <?= (int)$e['num_pisos'] ?> pisos
                <?php if ($col === 'total' || $col === 'parcial'): ?>
                · <span style="color:<?= $colColor ?>;">colapso <?= e($e['colapso']) ?></span>
                <?php endif; ?>
              </span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </form>
</div>

<script>
function actualizar() {
  var marcados = document.querySelectorAll('.chk-edificio:checked').length;
  document.getElementById('contador').textContent = marcados + ' seleccionado' + (marcados===1?'':'s');
  document.getElementById('btnGenerar').disabled = marcados === 0;
  // Sincronizar los checkboxes de parroquia.
  document.querySelectorAll('.chk-parroquia').forEach(function(cp){
    var parr = cp.getAttribute('data-parr');
    var todos = document.querySelectorAll('.chk-edificio[data-parr="'+CSS.escape(parr)+'"]');
    var mar = document.querySelectorAll('.chk-edificio[data-parr="'+CSS.escape(parr)+'"]:checked');
    cp.checked = todos.length > 0 && todos.length === mar.length;
    cp.indeterminate = mar.length > 0 && mar.length < todos.length;
  });
}
function marcarParroquia(cp) {
  var parr = cp.getAttribute('data-parr');
  document.querySelectorAll('.chk-edificio[data-parr="'+CSS.escape(parr)+'"]').forEach(function(ch){
    ch.checked = cp.checked;
  });
  actualizar();
}
function marcarTodos(v) {
  document.querySelectorAll('.chk-edificio').forEach(function(ch){ ch.checked = v; });
  actualizar();
}
actualizar();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
