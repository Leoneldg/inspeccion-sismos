<?php
/**
 * PDF DEL LISTADO DE RECONSTRUCCIÓN.
 *
 * Todos los levantamientos con su estado: en proceso, incompletos o
 * completos. Agrupado por día, igual que la pantalla.
 *
 * Uso: pdf_reconstruccion.php
 *      pdf_reconstruccion.php?parroquia=Sucre
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ent($v) { return number_format((int)$v, 0, ',', '.'); }

$parrF = trim($_GET['parroquia'] ?? '');
$lista = segEnReconstruccion($parrF !== '' ? ['parroquia' => $parrF] : []);

// Contar por estado.
$cuenta = ['proceso' => 0, 'incompleto' => 0, 'completo' => 0];
foreach ($lista as $e) {
    $est = $e['lev_estado'] ?? 'proceso';
    if (isset($cuenta[$est])) $cuenta[$est]++;
}

// Agrupar por día, lo más reciente primero.
$porDia = [];
foreach ($lista as $e) {
    $f = !empty($e['creado_en']) ? substr($e['creado_en'], 0, 10) : 'sin-fecha';
    $porDia[$f][] = $e;
}
krsort($porDia);

function diaLegible(string $f): string
{
    if ($f === 'sin-fecha') return 'Sin fecha';
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($f);
    return (int)date('d', $t) . ' de ' . $meses[(int)date('n', $t)]
         . ' de ' . date('Y', $t);
}

$ESTADOS = [
    'proceso'    => ['En proceso', '#C9A227'],
    'incompleto' => ['Incompleto', '#A61C1C'],
    'completo'   => ['Completo', '#2E7D32'],
];

$fecha = date('d/m/Y');
$hora  = date('H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10px; }
  .hoja { padding: 18px 24px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 10px; margin-bottom: 12px; }
  .cab h1 { margin: 0; font-size: 21px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; }
  .cab .sub { font-size: 10.5px; color: #55617f; }

  .kpis { display: table; width: 100%; border-spacing: 6px 0; margin-bottom: 13px; }
  .kpis .k { display: table-cell; text-align: center; padding: 11px 5px;
             border-radius: 8px; border: 1px solid; }
  .kpis .n { font-size: 20px; font-weight: 800; line-height: 1; }
  .kpis .l { font-size: 8.5px; text-transform: uppercase; color: #55617f;
             margin-top: 4px; }

  .dia { background: #22366F; color: #fff; padding: 6px 12px; border-radius: 6px;
         font-size: 11px; font-weight: 700; margin: 12px 0 5px; }
  .dia .n { float: right; font-weight: 600; font-size: 9.5px; opacity: .9; }

  table.t { width: 100%; border-collapse: collapse; }
  table.t th { background: #f1f3f8; color: #22366F; font-size: 8.5px; padding: 5px 7px;
               text-align: left; text-transform: uppercase; border-bottom: 1px solid #dde2ec; }
  table.t td { font-size: 9.5px; padding: 5px 7px; border-bottom: 1px solid #eef0f5;
               vertical-align: top; }
  table.t tr:nth-child(even) td { background: #fafbfe; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }

  .etq { display: inline-block; font-size: 8px; font-weight: 700; border-radius: 8px;
         padding: 1px 7px; white-space: nowrap; }
  .letra { display: inline-block; width: 17px; height: 17px; line-height: 15px;
           border: 2px solid; border-radius: 4px; text-align: center;
           font-weight: 800; font-size: 9px; }

  .pie { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 8px; color: #767c94; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Levantamientos en curso</h1>
    <div class="sub">
      <?php if ($parrF): ?>Parroquia: <?= esc($parrF) ?><?php else: ?>Todas las parroquias<?php endif; ?>
    </div>
  </div>

  <?php if (!$lista): ?>
    <p style="color:#767c94;padding:40px;text-align:center;">
      No hay levantamientos iniciados.
    </p>
  <?php else: ?>

  <div class="kpis">
    <div class="k" style="border-color:#22366F33;background:#22366F0a;">
      <div class="n" style="color:#22366F;"><?= ent(count($lista)) ?></div>
      <div class="l">Total</div>
    </div>
    <div class="k" style="border-color:#C9A22755;background:#C9A2270a;">
      <div class="n" style="color:#a8871f;"><?= ent($cuenta['proceso']) ?></div>
      <div class="l">En proceso</div>
    </div>
    <div class="k" style="border-color:#A61C1C33;background:#A61C1C0a;">
      <div class="n" style="color:#A61C1C;"><?= ent($cuenta['incompleto']) ?></div>
      <div class="l">Incompletos</div>
    </div>
    <div class="k" style="border-color:#2E7D3233;background:#2E7D320a;">
      <div class="n" style="color:#2E7D32;"><?= ent($cuenta['completo']) ?></div>
      <div class="l">Completos</div>
    </div>
  </div>

  <?php if ($cuenta['incompleto'] > 0): ?>
  <div style="background:#fdf0f0;border:1px solid #A61C1C33;border-radius:7px;
              padding:8px 12px;font-size:9.5px;color:#A61C1C;margin-bottom:11px;">
    <strong><?= ent($cuenta['incompleto']) ?> levantamiento(s) se cerraron con
    datos faltantes:</strong> ambientes sin foto, sin metros o sin tipo de trabajo.
  </div>
  <?php endif; ?>

  <?php foreach ($porDia as $dia => $items): ?>
  <div class="dia">
    <?= esc(diaLegible($dia)) ?>
    <span class="n"><?= count($items) ?></span>
  </div>

  <table class="t">
    <thead><tr>
      <th style="width:22px;"></th>
      <th>Edificación</th>
      <th style="width:88px;">Parroquia</th>
      <th style="width:78px;">Avance</th>
      <th style="width:96px;">Estado</th>
      <th style="width:92px;">Levantó</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $e):
        $est = $e['lev_estado'] ?? 'proceso';
        $m = $ESTADOS[$est];
        $sim = recSimboloDecision($e['decision_final'] ?? null);
        $quien = $e['cerrado_por_nombre'] ?: ($e['creado_por_nombre'] ?? '');
    ?>
      <tr>
        <td>
          <span class="letra" style="color:<?= $sim['color'] ?>;
                border-color:<?= $sim['color'] ?>;"><?= $sim['letra'] ?></span>
        </td>
        <td>
          <strong><?= esc($e['nombre_edificio'] ?: 'Sin nombre') ?></strong>
          <div style="font-size:8px;color:#767c94;"><?= esc($e['codigo']) ?></div>
        </td>
        <td style="font-size:9px;"><?= esc($e['parroquia'] ?: '—') ?></td>
        <td style="font-size:9px;">
          <?= (int)$e['n_pisos'] ?> piso<?= (int)$e['n_pisos'] === 1 ? '' : 's' ?><br>
          <span style="color:#767c94;">
            <?= (int)$e['aptos_hechos'] ?>/<?= (int)$e['n_aptos'] ?> aptos
          </span>
        </td>
        <td>
          <span class="etq" style="background:<?= $m[1] ?>22;color:<?= $m[1] ?>;">
            <?= $m[0] ?>
          </span>
          <?php if ($est === 'proceso'): ?>
          <div style="font-size:8px;color:#767c94;"><?= (int)($e['lev_pct'] ?? 0) ?>%</div>
          <?php elseif ($est === 'incompleto' && !empty($e['lev_fallas'])): ?>
          <div style="font-size:8px;color:#A61C1C;">
            <?= (int)$e['lev_fallas'] ?> dato(s) sin completar
          </div>
          <?php endif; ?>
        </td>
        <td style="font-size:8.5px;"><?= esc($quien ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Generado el <?= $fecha ?> a las <?= $hora ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/rec_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/rec_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Levantamientos_' . ($parrF ? preg_replace('/[^A-Za-z0-9]/', '_', $parrF) . '_' : '')
        . date('Y-m-d') . '.pdf';

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
