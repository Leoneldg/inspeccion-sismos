<?php
/**
 * DASHBOARD TÉCNICO / CONSTRUCCIÓN.
 *
 * Enfoque 100% constructivo: materiales a comprar, metros² de trabajo
 * y daño estructural, desglosado por parroquia, por cantidad de pisos
 * y por edificio.
 *
 * Uso: dashboard/control_gubernamental.php[?parroquia=X]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/dashboard_tecnico.php';

requierePermiso('seguimiento', 'ver');

$parrF = trim($_GET['parroquia'] ?? '');
if ($parrF !== '' && !puedeAccederParroquia($parrF)) $parrF = '';
$filtros = $parrF !== '' ? ['parroquia' => $parrF] : [];
$conteoFases = function_exists('segConteoFases') ? segConteoFases($filtros) : ['fase2_levantamiento'=>0,'fase2_reconstruccion'=>0,'fase2_total'=>0];

// Orden de la tabla por edificio: 'obra' (más metros primero, por
// defecto) o 'aptos_asc' (menos apartamentos primero).
$ordenesValidos = ['obra', 'obra_asc', 'aptos_asc', 'pisos_desc', 'pisos_asc'];
$ordenEdif = in_array($_GET['orden_edif'] ?? '', $ordenesValidos, true)
    ? $_GET['orden_edif'] : 'obra';

$d = techDashboard($filtros, $ordenEdif);
$activeModule = 'control_gub';

$cons = $d['consolidado'];
$dano = $d['dano'];
$materiales = $cons['materiales'] ?? [];
$trabajos   = $cons['trabajos'] ?? [];
$parroquias = $cons['parroquias'] ?? [];
$m2Total    = $cons['m2_total'] ?? 0;

// Ordenar materiales y trabajos por cantidad (mayor primero).
usort($materiales, fn($a,$b) => ($b['cantidad'] ?? 0) <=> ($a['cantidad'] ?? 0));
usort($trabajos,   fn($a,$b) => ($b['m2'] ?? 0) <=> ($a['m2'] ?? 0));

// Parroquias disponibles para el filtro.
$parroquiasDisp = [];
try {
    [$w, $p] = techScopeWhere([]);
    $st = db()->prepare("SELECT DISTINCT i.parroquia FROM inspecciones i
                          JOIN rec_edificio re ON re.inspeccion_id = i.id
                          $w AND i.parroquia IS NOT NULL AND i.parroquia <> ''
                          ORDER BY i.parroquia");
    $st->execute($p);
    $parroquiasDisp = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/../includes/header.php';

function num2($v) { return number_format((float)$v, 2, ',', '.'); }
function ent2($v) { return number_format((int)$v, 0, ',', '.'); }
?>

<div style="max-width:1100px;margin:0 auto;padding:0 12px;">

  <div style="display:flex;justify-content:space-between;align-items:center;
              flex-wrap:wrap;gap:10px;margin:16px 0;">
    <div>
      <h1 style="margin:0;font-size:22px;color:#22366F;">
        <i class="bi bi-bricks"></i> Panorama técnico de obra
      </h1>
      <div style="color:#5b6478;font-size:13px;">
        Materiales y metros² de trabajo<?= $parrF ? ' · ' . e($parrF) : '' ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <select onchange="if(this.value)location.href='?parroquia='+encodeURIComponent(this.value);else location.href='?';"
              class="form-control" style="width:190px;">
        <option value="">Todas las parroquias</option>
        <?php foreach ($parroquiasDisp as $pp): ?>
        <option value="<?= e($pp) ?>" <?= $pp === $parrF ? 'selected' : '' ?>><?= e($pp) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= APP_URL_BASE ?>dashboard/pdf_control_gubernamental.php<?= $parrF ? '?parroquia=' . urlencode($parrF) : '' ?>"
         target="_blank" class="btn btn-primary btn-sm" style="white-space:nowrap;">
        <i class="bi bi-file-earmark-pdf-fill"></i> Exportar PDF
      </a>
    </div>
  </div>

  <!-- PESTAÑAS: unifican las herramientas de la fase -->
  <div class="fase-tabs" style="display:flex;gap:4px;border-bottom:1px solid #e6e9f0;margin-bottom:16px;flex-wrap:wrap;">
    <button class="fase-tab on" data-tab="resumen" onclick="faseTab('resumen',this)">Resumen</button>
    <button class="fase-tab" data-tab="levantamientos" onclick="faseTab('levantamientos',this)">Levantamientos</button>
    <?php if (function_exists('esSistematizador') && esSistematizador()): ?>
    <button class="fase-tab" data-tab="requisiciones" onclick="faseTab('requisiciones',this)">Requisiciones</button>
    <?php endif; ?>
  </div>

  <style>
    .fase-tab { padding:9px 15px; font-size:13px; color:#5b6478; background:none; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; font-weight:500; }
    .fase-tab.on { color:#22366F; border-bottom-color:#22366F; }
    .fase-tab:hover { color:#22366F; }
    .fase-panel { display:none; }
    .fase-panel.on { display:block; }
    .fase-frame { width:100%; height:calc(100vh - 220px); min-height:520px; border:1px solid #e6e9f0; border-radius:11px; }
  </style>

  <!-- PANEL: Resumen (el contenido original de la fase) -->
  <div class="fase-panel on" id="panel-resumen">

  <!-- Los dos momentos de la Fase 2, mostrados por separado -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
    <div onclick="kpiAbrir('levantamiento')" style="border:1px solid #e0e4ee;border-radius:12px;padding:16px;background:#fff;cursor:pointer;">
      <div style="display:flex;align-items:center;gap:9px;margin-bottom:4px;">
        <div style="width:36px;height:36px;border-radius:9px;background:#EEF2FB;color:#22366F;display:flex;align-items:center;justify-content:center;font-size:18px;">
          <i class="bi bi-rulers"></i>
        </div>
        <div style="font-size:12.5px;color:#5b6478;font-weight:600;">Con levantamiento técnico</div>
      </div>
      <div style="font-size:30px;font-weight:800;color:#22366F;line-height:1;margin-top:6px;"><?= number_format($conteoFases['fase2_levantamiento'],0,',','.') ?></div>
      <div style="font-size:11.5px;color:#9aa1b4;margin-top:3px;">Medidos, listos para reconstruir (avance 0%)</div>
      <div style="font-size:11.5px;color:#22366F;font-weight:600;margin-top:7px;"><i class="bi bi-list-ul"></i> Ver lista por parroquia</div>
    </div>
    <div onclick="kpiAbrir('reconstruccion')" style="border:1px solid #C9A22733;border-radius:12px;padding:16px;background:#FFFDF5;cursor:pointer;">
      <div style="display:flex;align-items:center;gap:9px;margin-bottom:4px;">
        <div style="width:36px;height:36px;border-radius:9px;background:#F7EFD6;color:#A66A00;display:flex;align-items:center;justify-content:center;font-size:18px;">
          <i class="bi bi-hammer"></i>
        </div>
        <div style="font-size:12.5px;color:#5b6478;font-weight:600;">En reconstrucción</div>
      </div>
      <div style="font-size:30px;font-weight:800;color:#A66A00;line-height:1;margin-top:6px;"><?= number_format($conteoFases['fase2_reconstruccion'],0,',','.') ?></div>
      <div style="font-size:11.5px;color:#9aa1b4;margin-top:3px;">Con avance de obra registrado (≥ 1%)</div>
      <div style="font-size:11.5px;color:#A66A00;font-weight:600;margin-top:7px;"><i class="bi bi-list-ul"></i> Ver lista por parroquia</div>
    </div>
  </div>

  <!-- KPIs constructivos -->

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;">
    <div style="background:#22366F;color:#fff;border-radius:10px;padding:14px;">
      <div style="font-size:26px;font-weight:800;"><?= ent2($cons['edificios'] ?? 0) ?></div>
      <div style="font-size:12px;opacity:.95;">Edificaciones con obra</div>
    </div>
    <div style="background:#2E7D32;color:#fff;border-radius:10px;padding:14px;">
      <div style="font-size:26px;font-weight:800;"><?= num2($m2Total) ?></div>
      <div style="font-size:12px;opacity:.95;">Metros² de trabajo total</div>
    </div>
    <div style="background:#8B4513;color:#fff;border-radius:10px;padding:14px;">
      <div style="font-size:26px;font-weight:800;"><?= num2($cons['friso'] ?? 0) ?></div>
      <div style="font-size:12px;opacity:.95;">m² de friso</div>
    </div>
    <div style="background:#5b6478;color:#fff;border-radius:10px;padding:14px;">
      <div style="font-size:26px;font-weight:800;"><?= num2($cons['pintura'] ?? 0) ?></div>
      <div style="font-size:12px;opacity:.95;">m² de pintura</div>
    </div>
  </div>

  <!-- Materiales a comprar + trabajos por tipo (dos columnas) -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px;margin-bottom:14px;">

    <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;">
      <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
        <i class="bi bi-box-seam-fill"></i> Materiales a comprar (total)
      </div>
      <?php if ($materiales): ?>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="background:#22366F;color:#fff;">
          <th style="text-align:left;padding:6px 9px;">Material</th>
          <th style="text-align:right;padding:6px 9px;">Cantidad</th>
        </tr></thead>
        <tbody>
          <?php foreach ($materiales as $i => $m): ?>
          <tr style="border-bottom:1px solid #eef0f5;<?= $i%2?'background:#fafbfe;':'' ?>">
            <td style="padding:5px 9px;"><?= e($m['material']) ?></td>
            <td style="padding:5px 9px;text-align:right;font-weight:600;">
              <?= num2($m['cantidad']) ?> <?= e($m['unidad']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
        <div style="color:#5b6478;font-size:13px;">Sin materiales registrados todavía.</div>
      <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;">
      <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
        <i class="bi bi-rulers"></i> Metros² por tipo de trabajo
      </div>
      <?php if ($trabajos): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="background:#22366F;color:#fff;">
          <th style="text-align:left;padding:6px 9px;">Trabajo</th>
          <th style="text-align:right;padding:6px 9px;">m²</th>
        </tr></thead>
        <tbody>
          <?php foreach ($trabajos as $i => $t): ?>
          <tr style="border-bottom:1px solid #eef0f5;<?= $i%2?'background:#fafbfe;':'' ?>">
            <td style="padding:5px 9px;"><?= e($t['nombre']) ?></td>
            <td style="padding:5px 9px;text-align:right;font-weight:600;"><?= num2($t['m2']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div style="color:#5b6478;font-size:13px;">Sin trabajos registrados todavía.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Por parroquia -->
  <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;margin-bottom:14px;">
    <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
      <i class="bi bi-geo-alt-fill"></i> Por parroquia
    </div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#22366F;color:#fff;">
        <th style="text-align:left;padding:7px 9px;">Parroquia</th>
        <th style="padding:7px 9px;">Edificaciones</th>
        <th style="padding:7px 9px;">m² de trabajo</th>
      </tr></thead>
      <tbody>
        <?php
        $parrList = [];
        foreach ($parroquias as $nombre => $info) {
            $parrList[] = ['nombre' => $nombre] + $info;
        }
        usort($parrList, fn($a,$b) => ($b['m2'] ?? 0) <=> ($a['m2'] ?? 0));
        foreach ($parrList as $i => $row): ?>
        <tr style="border-bottom:1px solid #eef0f5;<?= $i%2?'background:#fafbfe;':'' ?>">
          <td style="padding:6px 9px;font-weight:600;"><?= e($row['nombre']) ?></td>
          <td style="padding:6px 9px;text-align:center;"><?= ent2($row['edificios'] ?? 0) ?></td>
          <td style="padding:6px 9px;text-align:right;"><?= num2($row['m2'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Por edificio -->
  <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
      <div style="font-weight:700;color:#22366F;">
        <i class="bi bi-buildings-fill"></i>
        <?php
        $tituloOrden = [
          'obra'      => 'los que más obra requieren',
          'obra_asc'  => 'los que menos obra requieren',
          'pisos_desc'=> 'los de más pisos primero',
          'pisos_asc' => 'los de menos pisos primero',
          'aptos_asc' => 'los de menos apartamentos primero',
        ];
        ?>
        Por edificio (<?= $tituloOrden[$ordenEdif] ?? 'listado' ?>)
      </div>
      <?php
      // Conservar el filtro de parroquia al cambiar de orden / exportar.
      $baseQS = $parrF !== '' ? ('parroquia=' . urlencode($parrF) . '&') : '';
      $filtrosOrden = [
        'obra'       => 'Más obra',
        'obra_asc'   => 'Menos obra',
        'pisos_desc' => 'Más pisos',
        'pisos_asc'  => 'Menos pisos',
      ];
      ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div style="display:inline-flex;border:1px solid #d4d9e6;border-radius:8px;overflow:hidden;font-size:12.5px;">
          <?php $primero = true; foreach ($filtrosOrden as $val => $txt):
            $activo = ($ordenEdif === $val); ?>
          <a href="?<?= $baseQS ?>orden_edif=<?= $val ?>"
             style="padding:6px 12px;text-decoration:none;font-weight:600;<?= $primero ? '' : 'border-left:1px solid #d4d9e6;' ?>
                    <?= $activo ? 'background:#22366F;color:#fff;' : 'color:#22366F;background:#fff;' ?>">
            <?= $txt ?>
          </a>
          <?php $primero = false; endforeach; ?>
        </div>
        <a href="<?= APP_URL_BASE ?>dashboard/pdf_control_gubernamental.php?<?= $baseQS ?>orden_edif=<?= $ordenEdif ?>"
           target="_blank"
           style="display:inline-flex;align-items:center;gap:6px;padding:6px 13px;background:#A61C1C;color:#fff;
                  text-decoration:none;border-radius:8px;font-size:12.5px;font-weight:600;">
          <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
        </a>
      </div>
    </div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#22366F;color:#fff;">
        <th style="text-align:left;padding:7px 9px;">Edificación</th>
        <th style="text-align:left;padding:7px 9px;">Parroquia</th>
        <th style="padding:7px 9px;">Pisos</th>
        <th style="padding:7px 9px;">Apartamentos</th>
        <th style="padding:7px 9px;">Colapso</th>
        <th style="padding:7px 9px;">m² de trabajo</th>
      </tr></thead>
      <tbody>
        <?php foreach ($d['edificios'] as $i => $row):
          $col = strtolower($row['colapso']);
          $colColor = $col === 'total' ? '#A61C1C' : ($col === 'parcial' ? '#C9A227' : '#5b6478');
        ?>
        <tr style="border-bottom:1px solid #eef0f5;<?= $i%2?'background:#fafbfe;':'' ?>">
          <td style="padding:6px 9px;font-weight:600;"><?= e($row['nombre']) ?></td>
          <td style="padding:6px 9px;"><?= e($row['parroquia']) ?></td>
          <td style="padding:6px 9px;text-align:center;"><?= (int)$row['num_pisos'] ?></td>
          <td style="padding:6px 9px;text-align:center;<?= $ordenEdif === 'aptos_asc' ? 'font-weight:700;color:#22366F;' : '' ?>"><?= (int)$row['num_apartamentos'] ?></td>
          <td style="padding:6px 9px;text-align:center;color:<?= $colColor ?>;font-weight:600;"><?= e($row['colapso']) ?></td>
          <td style="padding:6px 9px;text-align:right;font-weight:600;"><?= num2($row['m2']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  </div><!-- /panel-resumen -->

  <!-- PANEL: Levantamientos (herramienta integrada) -->
  <div class="fase-panel" id="panel-levantamientos">
    <iframe class="fase-frame" data-src="<?= APP_URL_BASE ?>seguimiento/en_reconstruccion.php?embed=1" title="Levantamientos"></iframe>
  </div>

  <!-- PANEL: Requisiciones -->
  <div class="fase-panel" id="panel-requisiciones">
    <iframe class="fase-frame" data-src="<?= APP_URL_BASE ?>seguimiento/requisiciones.php?embed=1" title="Requisiciones"></iframe>
  </div>

  <script>
  function faseTab(tab, btn) {
    document.querySelectorAll('.fase-tab').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    document.querySelectorAll('.fase-panel').forEach(p => p.classList.remove('on'));
    var panel = document.getElementById('panel-' + tab);
    if (panel) {
      panel.classList.add('on');
      // Cargar el iframe solo la primera vez que se abre la pestaña (lazy).
      var fr = panel.querySelector('iframe.fase-frame');
      if (fr && !fr.src && fr.dataset.src) fr.src = fr.dataset.src;
    }
  }
  </script>

</div>

<?php include __DIR__ . '/_kpi_modal.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
