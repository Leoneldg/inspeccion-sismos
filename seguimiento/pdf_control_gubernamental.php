<?php
/**
 * PDF · DASHBOARD TÉCNICO / CONSTRUCCIÓN.
 *
 * Versión imprimible del panorama de obra: materiales a comprar,
 * metros² de trabajo y daño estructural, por parroquia, por cantidad
 * de pisos y por edificio.
 *
 * Uso: dashboard/pdf_control_gubernamental.php[?parroquia=X]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/dashboard_tecnico.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n2($v)  { return number_format((float)$v, 2, ',', '.'); }
function e0($v)  { return number_format((int)$v, 0, ',', '.'); }

$parrF = trim($_GET['parroquia'] ?? '');
if ($parrF !== '' && !puedeAccederParroquia($parrF)) $parrF = '';
$filtros = $parrF !== '' ? ['parroquia' => $parrF] : [];

$d = techDashboard($filtros);
$cons = $d['consolidado'];
$dano = $d['dano'];
$materiales = $cons['materiales'] ?? [];
$trabajos   = $cons['trabajos'] ?? [];
$parroquias = $cons['parroquias'] ?? [];
$m2Total    = $cons['m2_total'] ?? 0;

usort($materiales, fn($a,$b) => ($b['cantidad'] ?? 0) <=> ($a['cantidad'] ?? 0));
usort($trabajos,   fn($a,$b) => ($b['m2'] ?? 0) <=> ($a['m2'] ?? 0));
$parrList = [];
foreach ($parroquias as $nombre => $info) $parrList[] = ['nombre' => $nombre] + $info;
usort($parrList, fn($a,$b) => ($b['m2'] ?? 0) <=> ($a['m2'] ?? 0));

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10px; }
  .hoja { padding: 18px 24px; }
  .salto { page-break-before: always; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 10px; margin-bottom: 12px; }
  .cab h1 { margin: 0; font-size: 21px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; }
  .cab .sub { font-size: 10.5px; color: #55617f; margin-top: 2px; }

  h2 { font-size: 12.5px; color: #fff; background: #22366F; margin: 14px 0 8px;
       padding: 5px 9px; border-radius: 5px; }

  .kpis { display: table; width: 100%; border-spacing: 6px 0; margin-bottom: 8px; }
  .kpis .c { display: table-cell; width: 25%; border-radius: 8px; padding: 10px;
             color: #fff; text-align: center; }
  .kpis .n { font-size: 19px; font-weight: 800; }
  .kpis .l { font-size: 8.5px; margin-top: 2px; }

  .dano { display: table; width: 100%; border-spacing: 5px 0; }
  .dano .c { display: table-cell; border: 1px solid #e0e4ee; border-radius: 6px;
             padding: 8px; text-align: center; }
  .dano .n { font-size: 18px; font-weight: 800; }
  .dano .l { font-size: 8.5px; color: #55617f; }

  table.t { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.t th { background: #22366F; color: #fff; font-size: 8.5px; padding: 5px 7px;
               text-align: left; text-transform: uppercase; }
  table.t th.r, table.t td.r { text-align: right; }
  table.t th.c, table.t td.c { text-align: center; }
  table.t td { font-size: 9.5px; padding: 4px 7px; border-bottom: 1px solid #eef0f5; }
  table.t tr:nth-child(even) td { background: #fafbfe; }

  .dos { display: table; width: 100%; border-spacing: 8px 0; }
  .dos > div { display: table-cell; width: 50%; vertical-align: top; }

  .nota { margin-top: 12px; font-size: 8px; color: #7a8398;
          border-top: 1px solid #e0e4ee; padding-top: 7px; }
</style>
</head>
<body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?> · <?= $hora ?><br><?= esc($quien) ?></div>
    <h1>Panorama técnico de obra</h1>
    <div class="sub">
      Materiales, metros² de trabajo y daño estructural<?= $parrF ? ' · ' . esc($parrF) : '' ?>
    </div>
  </div>

  <!-- KPIs -->
  <div class="kpis">
    <div class="c" style="background:#22366F;">
      <div class="n"><?= e0($cons['edificios'] ?? 0) ?></div>
      <div class="l">EDIFICACIONES CON OBRA</div>
    </div>
    <div class="c" style="background:#2E7D32;">
      <div class="n"><?= n2($m2Total) ?></div>
      <div class="l">M² DE TRABAJO TOTAL</div>
    </div>
    <div class="c" style="background:#8B4513;">
      <div class="n"><?= n2($cons['friso'] ?? 0) ?></div>
      <div class="l">M² DE FRISO</div>
    </div>
    <div class="c" style="background:#5b6478;">
      <div class="n"><?= n2($cons['pintura'] ?? 0) ?></div>
      <div class="l">M² DE PINTURA</div>
    </div>
  </div>

  <!-- Daño estructural -->
  <h2>Daño estructural (colapso)</h2>
  <div class="dano">
    <div class="c"><div class="n" style="color:#A61C1C;"><?= e0($dano['colapso_total']) ?></div><div class="l">Colapso total</div></div>
    <div class="c"><div class="n" style="color:#C9A227;"><?= e0($dano['colapso_parcial']) ?></div><div class="l">Colapso parcial</div></div>
    <div class="c"><div class="n" style="color:#2E7D32;"><?= e0($dano['sin_colapso']) ?></div><div class="l">Sin colapso</div></div>
  </div>

  <!-- Materiales + trabajos -->
  <div class="dos">
    <div>
      <h2>Materiales a comprar (total)</h2>
      <?php if ($materiales): ?>
      <table class="t">
        <thead><tr><th>Material</th><th class="r">Cantidad</th></tr></thead>
        <tbody>
          <?php foreach ($materiales as $m): ?>
          <tr>
            <td><?= esc($m['material']) ?></td>
            <td class="r"><?= n2($m['cantidad']) ?> <?= esc($m['unidad']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div style="font-size:9px;color:#7a8398;">Sin materiales registrados todavía.</div><?php endif; ?>
    </div>
    <div>
      <h2>Metros² por tipo de trabajo</h2>
      <?php if ($trabajos): ?>
      <table class="t">
        <thead><tr><th>Trabajo</th><th class="r">m²</th></tr></thead>
        <tbody>
          <?php foreach ($trabajos as $t): ?>
          <tr><td><?= esc($t['nombre']) ?></td><td class="r"><?= n2($t['m2']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div style="font-size:9px;color:#7a8398;">Sin trabajos registrados todavía.</div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Segunda página -->
<div class="hoja salto">
  <div class="dos">
    <div>
      <h2>Por parroquia</h2>
      <table class="t">
        <thead><tr><th>Parroquia</th><th class="c">Edif.</th><th class="r">m²</th></tr></thead>
        <tbody>
          <?php foreach ($parrList as $row): ?>
          <tr>
            <td><?= esc($row['nombre']) ?></td>
            <td class="c"><?= e0($row['edificios'] ?? 0) ?></td>
            <td class="r"><?= n2($row['m2'] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div>
      <h2>Por cantidad de pisos</h2>
      <table class="t">
        <thead><tr><th>Altura</th><th class="c">Edif.</th><th class="r">m²</th></tr></thead>
        <tbody>
          <?php foreach ($d['pisos'] as $row): ?>
          <tr>
            <td><?= esc($row['etiqueta']) ?></td>
            <td class="c"><?= e0($row['edificios']) ?></td>
            <td class="r"><?= n2($row['m2']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <h2>Por edificio (los que más obra requieren)</h2>
  <table class="t">
    <thead><tr>
      <th>Edificación</th><th>Parroquia</th><th class="c">Pisos</th>
      <th class="c">Colapso</th><th class="r">m² de trabajo</th>
    </tr></thead>
    <tbody>
      <?php foreach ($d['edificios'] as $row):
        $col = strtolower($row['colapso']);
        $cc = $col === 'total' ? '#A61C1C' : ($col === 'parcial' ? '#C9A227' : '#5b6478');
      ?>
      <tr>
        <td><?= esc($row['nombre']) ?></td>
        <td><?= esc($row['parroquia']) ?></td>
        <td class="c"><?= (int)$row['num_pisos'] ?></td>
        <td class="c" style="color:<?= $cc ?>;font-weight:700;"><?= esc($row['colapso']) ?></td>
        <td class="r" style="font-weight:700;"><?= n2($row['m2']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="nota">
    Cifras basadas en los levantamientos técnicos cerrados y completos al
    <?= $fecha ?>. Los metros² y materiales incluyen el margen de holgura
    configurado. Verifique en obra antes de ejecutar compras.
  </div>
</div>

</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/dashtec_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/dashtec_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Panorama_tecnico_' . date('Y-m-d') . '.pdf';
if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}
@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo $html;
