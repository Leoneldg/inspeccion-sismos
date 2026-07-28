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

// Orden de la tabla por edificio: 'obra' (más metros primero, por
// defecto) o 'aptos_asc' (menos apartamentos primero).
$ordenEdif = ($_GET['orden_edif'] ?? '') === 'aptos_asc' ? 'aptos_asc' : 'obra';

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
        Materiales, metros² de trabajo y daño estructural<?= $parrF ? ' · ' . e($parrF) : '' ?>
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

  <!-- Daño estructural -->
  <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;margin-bottom:14px;">
    <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
      <i class="bi bi-exclamation-octagon-fill"></i> Daño estructural (colapso)
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;">
      <div style="border:1px solid #eceef4;border-radius:8px;padding:11px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#A61C1C;"><?= ent2($dano['colapso_total']) ?></div>
        <div style="font-size:12px;color:#5b6478;">Colapso total</div>
      </div>
      <div style="border:1px solid #eceef4;border-radius:8px;padding:11px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#C9A227;"><?= ent2($dano['colapso_parcial']) ?></div>
        <div style="font-size:12px;color:#5b6478;">Colapso parcial</div>
      </div>
      <div style="border:1px solid #eceef4;border-radius:8px;padding:11px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#2E7D32;"><?= ent2($dano['sin_colapso']) ?></div>
        <div style="font-size:12px;color:#5b6478;">Sin colapso</div>
      </div>
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

  <!-- Por cantidad de pisos -->
  <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;margin-bottom:14px;">
    <div style="font-weight:700;color:#22366F;margin-bottom:10px;">
      <i class="bi bi-building"></i> Por cantidad de pisos
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#22366F;color:#fff;">
        <th style="text-align:left;padding:7px 9px;">Altura</th>
        <th style="padding:7px 9px;">Edificaciones</th>
        <th style="padding:7px 9px;">m² de trabajo</th>
      </tr></thead>
      <tbody>
        <?php foreach ($d['pisos'] as $i => $row): ?>
        <tr style="border-bottom:1px solid #eef0f5;<?= $i%2?'background:#fafbfe;':'' ?>">
          <td style="padding:6px 9px;"><?= e($row['etiqueta']) ?></td>
          <td style="padding:6px 9px;text-align:center;"><?= ent2($row['edificios']) ?></td>
          <td style="padding:6px 9px;text-align:right;"><?= num2($row['m2']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Por edificio -->
  <div style="background:#fff;border:1px solid #e0e4ee;border-radius:10px;padding:14px;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <div style="font-weight:700;color:#22366F;">
        <i class="bi bi-buildings-fill"></i>
        <?= $ordenEdif === 'aptos_asc' ? 'Por edificio (los de menos apartamentos primero)' : 'Por edificio (los que más obra requieren)' ?>
      </div>
      <?php
      // Enlaces de orden, conservando el filtro de parroquia si lo hay.
      $baseQS = $parrF !== '' ? ('parroquia=' . urlencode($parrF) . '&') : '';
      ?>
      <div style="display:inline-flex;border:1px solid #d4d9e6;border-radius:8px;overflow:hidden;font-size:12.5px;">
        <a href="?<?= $baseQS ?>orden_edif=obra"
           style="padding:6px 12px;text-decoration:none;font-weight:600;
                  <?= $ordenEdif !== 'aptos_asc' ? 'background:#22366F;color:#fff;' : 'color:#22366F;background:#fff;' ?>">
          Más obra
        </a>
        <a href="?<?= $baseQS ?>orden_edif=aptos_asc"
           style="padding:6px 12px;text-decoration:none;font-weight:600;border-left:1px solid #d4d9e6;
                  <?= $ordenEdif === 'aptos_asc' ? 'background:#22366F;color:#fff;' : 'color:#22366F;background:#fff;' ?>">
          Menos apartamentos
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

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
