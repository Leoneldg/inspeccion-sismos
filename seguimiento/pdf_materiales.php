<?php
/**
 * PDF CONSOLIDADO DE MATERIALES.
 *
 * Todo lo que hace falta para los levantamientos cerrados: edificios,
 * apartamentos, metros y el material total con su margen de holgura.
 *
 * Uso: pdf_materiales.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function num($v, $d = 2) { return number_format((float)$v, $d, ',', '.'); }
function ent($v) { return number_format((int)$v, 0, ',', '.'); }

// El margen es el mismo en todo el sistema (constante MARGEN_MATERIALES).
$c = segConsolidadoMateriales();
$margen = $c['margen'];

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10.5px; }
  .hoja { padding: 20px 26px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 11px; margin-bottom: 14px; }
  .cab h1 { margin: 0; font-size: 23px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 10px; color: #55617f; }
  .cab .sub { font-size: 11px; color: #55617f; margin-top: 3px; }

  h2 { font-size: 13.5px; color: #22366F; margin: 18px 0 9px;
       border-bottom: 1px solid #e0e4ee; padding-bottom: 5px; }

  .kpis { display: table; width: 100%; border-spacing: 6px 0; margin-bottom: 12px; }
  .kpis .k { display: table-cell; text-align: center; padding: 11px 5px;
             border-radius: 8px; border: 1px solid #e0e4ee; }
  .kpis .n { font-size: 19px; font-weight: 800; }
  .kpis .l { font-size: 8.5px; text-transform: uppercase; color: #55617f;
             margin-top: 3px; }

  table.t { width: 100%; border-collapse: collapse; }
  table.t th { background: #f1f3f8; color: #22366F; font-size: 9px; padding: 6px 8px;
               text-align: left; text-transform: uppercase; border-bottom: 1px solid #dde2ec; }
  table.t td { font-size: 10px; padding: 6px 8px; border-bottom: 1px solid #eef0f5; }
  table.t tr:nth-child(even) td { background: #fafbfe; }
  table.t tfoot td { background: #22366F; color: #fff; font-weight: 700;
                     border: 0; font-size: 10.5px; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }

  .mat-cab { background: #C9A227; color: #22366F; padding: 11px 16px;
             border-radius: 8px 8px 0 0; font-size: 14px; font-weight: 800; }
  .mat-caja { border: 2px solid #C9A227; border-top: 0; border-radius: 0 0 8px 8px;
              padding: 14px 16px; background: #fffdf5; }
  .mat { display: table; width: 100%; border-spacing: 6px; }
  .mat .m { display: table-cell; background: #fff; border: 1px solid #C9A22755;
            border-radius: 8px; padding: 11px 13px; width: 33%; }
  .mat .c { font-size: 19px; font-weight: 800; color: #22366F; line-height: 1; }
  .mat .u { font-size: 10px; color: #5b6478; margin-top: 3px; }

  .aviso { background: #eef2fb; border-radius: 7px; padding: 9px 13px;
           font-size: 10px; color: #22366F; margin-bottom: 11px; }

  .pie { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 8.5px; color: #767c94; text-align: center; }
  .firma { display: table; width: 100%; margin-top: 30px; }
  .firma .f { display: table-cell; width: 33%; text-align: center; padding: 0 18px; }
  .firma .l { border-top: 1px solid #2a3140; padding-top: 5px; font-size: 9px; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Materiales requeridos</h1>
    <div class="sub">
      Consolidado de los levantamientos cerrados ·
      Margen de holgura del <?= num($margen, 0) ?>%
    </div>
  </div>

  <?php if ($c['edificios'] === 0): ?>
    <p style="color:#767c94;padding:40px;text-align:center;">
      Todavía no hay levantamientos cerrados. El material se calcula
      únicamente sobre los que ya están completos.
    </p>
  <?php else: ?>

  <!-- Cifras generales -->
  <h2>Alcance de la obra</h2>
  <div class="kpis">
    <div class="k" style="border-color:#22366F33;">
      <div class="n" style="color:#22366F;"><?= ent($c['edificios']) ?></div>
      <div class="l">Edificios</div>
    </div>
    <div class="k" style="border-color:#2d448833;">
      <div class="n" style="color:#2d4488;"><?= ent($c['apartamentos']) ?></div>
      <div class="l">Apartamentos</div>
    </div>
    <div class="k" style="border-color:#C9A22755;">
      <div class="n" style="color:#a8871f;"><?= ent($c['aptos_reparar']) ?></div>
      <div class="l">A reparar</div>
    </div>
    <div class="k" style="border-color:#97a0b833;">
      <div class="n" style="color:#5b6478;"><?= ent($c['ambientes']) ?></div>
      <div class="l">Ambientes</div>
    </div>
    <div class="k" style="border-color:#2E7D3233;">
      <div class="n" style="color:#2E7D32;"><?= num($c['m2_total']) ?></div>
      <div class="l">m² a reparar</div>
    </div>
  </div>

  <div class="aviso">
    <strong><?= ent($c['familias']) ?> familias</strong> ·
    <strong><?= ent($c['personas']) ?> personas</strong> ·
    <strong><?= count($c['parroquias']) ?> parroquias</strong>
    <?php if ($c['friso'] > 0 || $c['pintura'] > 0): ?>
    <br>
    Superficie a cubrir:
    <?php if ($c['friso'] > 0): ?><strong><?= num($c['friso']) ?> m²</strong> de friso<?php endif; ?>
    <?php if ($c['pintura'] > 0): ?> · <strong><?= num($c['pintura']) ?> m²</strong> de pintura<?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Material: lo más importante, va primero -->
  <?php if ($c['materiales']): ?>
  <div class="mat-cab">
    MATERIAL TOTAL QUE SE NECESITA
  </div>
  <div class="mat-caja">
    <div class="mat">
      <?php $i = 0; foreach ($c['materiales'] as $m): ?>
        <?php if ($i > 0 && $i % 3 === 0): ?></div><div class="mat"><?php endif; ?>
        <div class="m">
          <div class="c"><?= num($m['cantidad']) ?></div>
          <?= badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#8a6d1a', '8.5px') ?>
          <div class="u"><?= esc($m['unidad']) ?> · <?= esc($m['material']) ?></div>
        </div>
      <?php $i++; endforeach; ?>
      <?php while ($i % 3 !== 0): ?>
        <div class="m" style="border:0;background:none;"></div>
      <?php $i++; endwhile; ?>
    </div>
    <div style="font-size:9.5px;color:#8a6d1a;margin-top:9px;">
      Las cantidades incluyen un <?= num($margen, 0) ?>% de holgura por
      desperdicio, roturas y cortes. Los materiales que se compran por
      unidad entera (bloques, sacos) están redondeados hacia arriba.
    </div>
  </div>
  <?php endif; ?>

  <!-- Trabajos -->
  <?php if ($c['trabajos']): ?>
  <h2>Trabajos por ejecutar</h2>
  <table class="t">
    <thead><tr>
      <th>Trabajo</th>
      <th style="width:90px;text-align:right;">Metros</th>
      <th style="width:60px;text-align:right;">%</th>
    </tr></thead>
    <tbody>
    <?php foreach ($c['trabajos'] as $t):
        $pct = $c['m2_total'] > 0 ? round($t['m2'] / $c['m2_total'] * 100) : 0; ?>
      <tr>
        <td style="font-weight:600;"><?= esc($t['nombre']) ?></td>
        <td style="text-align:right;font-weight:700;color:#22366F;">
          <?= num($t['m2']) ?> m²</td>
        <td style="text-align:right;color:#767c94;"><?= $pct ?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td>TOTAL</td>
      <td style="text-align:right;"><?= num($c['m2_total']) ?> m²</td>
      <td style="text-align:right;">100%</td>
    </tr></tfoot>
  </table>
  <?php endif; ?>

  <!-- Por parroquia -->
  <?php if (count($c['parroquias']) > 1): ?>
  <h2>Distribución por parroquia</h2>
  <table class="t">
    <thead><tr>
      <th>Parroquia</th>
      <th style="width:66px;text-align:center;">Edificios</th>
      <th style="width:78px;text-align:center;">Apartamentos</th>
      <th style="width:66px;text-align:center;">A reparar</th>
      <th style="width:78px;text-align:right;">Metros</th>
      <th style="width:62px;text-align:center;">Familias</th>
    </tr></thead>
    <tbody>
    <?php foreach ($c['parroquias'] as $pn => $p): ?>
      <tr>
        <td style="font-weight:600;"><?= esc($pn) ?></td>
        <td style="text-align:center;font-weight:700;"><?= ent($p['edificios']) ?></td>
        <td style="text-align:center;"><?= ent($p['apartamentos']) ?></td>
        <td style="text-align:center;color:#a8871f;font-weight:600;">
          <?= ent($p['aptos_reparar']) ?></td>
        <td style="text-align:right;"><?= num($p['m2']) ?></td>
        <td style="text-align:center;"><?= ent($p['familias']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td>TOTAL</td>
      <td style="text-align:center;"><?= ent($c['edificios']) ?></td>
      <td style="text-align:center;"><?= ent($c['apartamentos']) ?></td>
      <td style="text-align:center;"><?= ent($c['aptos_reparar']) ?></td>
      <td style="text-align:right;"><?= num($c['m2_total']) ?></td>
      <td style="text-align:center;"><?= ent($c['familias']) ?></td>
    </tr></tfoot>
  </table>
  <?php endif; ?>

  <!-- Firmas -->
  <div class="firma">
    <div class="f"><div class="l">Elaborado por</div></div>
    <div class="f"><div class="l">Revisado por</div></div>
    <div class="f"><div class="l">Aprobado por</div></div>
  </div>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Generado el <?= $fecha ?> a las <?= $hora ?>
    por <?= esc($quien) ?><br>
    Cálculo estimado sobre los metros registrados en los levantamientos
    cerrados. Verifique en obra antes de solicitar.
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/mat_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/mat_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Materiales_requeridos_' . date('Y-m-d') . '.pdf';

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
