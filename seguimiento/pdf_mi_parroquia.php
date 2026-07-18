<?php
/**
 * INFORME EJECUTIVO DE PARROQUIA (PDF).
 * Pensado para llevarlo impreso a una reunión: resumen arriba,
 * detalle completo abajo, ordenado por avance.
 * ?parroquia=Sucre&estado=Distrito Capital
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$parroquia = trim($_GET['parroquia'] ?? '');
$estado    = trim($_GET['estado'] ?? 'Distrito Capital');
if ($parroquia === '') { http_response_code(400); exit('Falta la parroquia.'); }

// El responsable solo puede sacar el informe de sus parroquias.
if (!puedeAccederParroquia($parroquia)) {
    http_response_code(403);
    exit('No tiene asignada esta parroquia.');
}

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$d     = recPanelParroquia($estado, $parroquia);
$edifs = $d['edificaciones'] ?? [];
$pc    = $d['por_color'] ?? [];
$cat   = catalogoDecisionFinal();

// Promedio general y agrupación por tramos.
$avg = $edifs ? (int)round(array_sum(array_column($edifs, 'avance')) / count($edifs)) : 0;
$tramos = ['listo'=>0,'avanzado'=>0,'medio'=>0,'inicial'=>0,'sin'=>0];
foreach ($edifs as $e) {
    $a = (int)($e['avance'] ?? 0);
    if ($a >= 100)    $tramos['listo']++;
    elseif ($a >= 75) $tramos['avanzado']++;
    elseif ($a >= 25) $tramos['medio']++;
    elseif ($a > 0)   $tramos['inicial']++;
    else              $tramos['sin']++;
}
recOrdenarPorColor($edifs);

$fecha = date('d/m/Y');
$hora  = date('H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #2a3140; font-size: 11px; }
  .hoja { padding: 26px 30px; }
  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 12px; margin-bottom: 16px; }
  .cab h1 { margin: 0; font-size: 21px; color: #22366F; letter-spacing: -.3px; }
  .cab .sub { color: #767c94; font-size: 11px; margin-top: 3px; }
  .cab .fecha { float: right; text-align: right; color: #767c94; font-size: 10px; }

  .bloque { margin-bottom: 18px; }
  .bloque h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px;
               color: #55617f; margin: 0 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e8ebf3; }

  .destacado { background: #f4f7fd; border-radius: 9px; padding: 14px 16px; margin-bottom: 14px; }
  .destacado .pct { font-size: 40px; font-weight: 800; color: #22366F; line-height: 1; }
  .destacado .lbl { font-size: 11px; color: #55617f; }
  .barra-g { background: #e3e7f1; border-radius: 10px; height: 16px; overflow: hidden; margin-top: 8px; }
  .barra-g > div { height: 100%; background: #C9A227; }

  table.res { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 4px; }
  table.res td { text-align: center; padding: 9px 4px; border-radius: 8px; border: 1px solid #e0e4ee; }
  table.res .n { font-size: 19px; font-weight: 800; }
  table.res .l { font-size: 8px; text-transform: uppercase; color: #666; }

  table.det { width: 100%; border-collapse: collapse; }
  table.det thead { display: table-header-group; }
  table.det tr { page-break-inside: avoid; }
  table.det th { background: #22366F; color: #fff; font-size: 9px; padding: 6px 5px;
                 text-align: left; text-transform: uppercase; letter-spacing: .3px; }
  table.det td { font-size: 9.5px; padding: 5px; border-bottom: 1px solid #eef0f5; }
  table.det tr:nth-child(even) td { background: #fafbfe; }
  .mini { background: #e8ebf3; border-radius: 6px; height: 8px; width: 70px; display: inline-block; overflow: hidden; }
  .mini > div { height: 100%; }
  .pt { font-weight: 700; text-align: right; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }

  .equipo td { font-size: 9.5px; padding: 4px 5px; border-bottom: 1px solid #f0f2f7; }
  .equipo .rol { font-size: 8px; text-transform: uppercase; color: #97a0b8; }

  .pie { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 8.5px; color: #97a0b8; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="fecha"><?= $fecha ?><br><?= $hora ?></div>
    <h1><?= esc(mb_strtoupper($parroquia, 'UTF-8')) ?></h1>
    <div class="sub">Informe de reconstrucción · <?= esc($estado) ?></div>
  </div>

  <!-- Resumen ejecutivo -->
  <div class="destacado">
    <table style="width:100%;"><tr>
      <td style="width:130px;vertical-align:middle;">
        <div class="pct"><?= $avg ?>%</div>
        <div class="lbl">Avance general</div>
      </td>
      <td style="vertical-align:middle;">
        <div style="font-size:12px;color:#55617f;">
          <strong style="font-size:17px;color:#22366F;"><?= count($edifs) ?></strong> edificaciones en reconstrucción
          &nbsp;·&nbsp; <strong style="color:#2E7D32;"><?= $tramos['listo'] ?></strong> culminadas
          &nbsp;·&nbsp; <strong style="color:#C9A227;"><?= count($edifs) - $tramos['listo'] ?></strong> en proceso
        </div>
        <div class="barra-g"><div style="width:<?= $avg ?>%;"></div></div>
      </td>
    </tr></table>
  </div>

  <!-- Distribución por avance -->
  <div class="bloque">
    <h2>Distribución por avance</h2>
    <table class="res"><tr>
      <td style="border-color:#97a0b855;"><div class="n" style="color:#767c94;"><?= $tramos['sin'] ?></div><div class="l">Sin iniciar</div></td>
      <td style="border-color:#C9A22755;"><div class="n" style="color:#C9A227;"><?= $tramos['inicial'] ?></div><div class="l">1 a 24%</div></td>
      <td style="border-color:#C9A22755;"><div class="n" style="color:#C9A227;"><?= $tramos['medio'] ?></div><div class="l">25 a 74%</div></td>
      <td style="border-color:#2E7D3255;"><div class="n" style="color:#5a9e3f;"><?= $tramos['avanzado'] ?></div><div class="l">75 a 99%</div></td>
      <td style="border-color:#2E7D3255;"><div class="n" style="color:#2E7D32;"><?= $tramos['listo'] ?></div><div class="l">Culminadas</div></td>
    </tr></table>
  </div>

  <!-- Clasificación estructural -->
  <div class="bloque">
    <h2>Clasificación de las edificaciones</h2>
    <table class="res"><tr>
      <td style="border-color:#A61C1C55;"><div class="n" style="color:#A61C1C;"><?= (int)($pc['rojo'] ?? 0) ?></div><div class="l">Rojo</div></td>
      <td style="border-color:#C9A22755;"><div class="n" style="color:#C9A227;"><?= (int)($pc['amarillo'] ?? 0) ?></div><div class="l">Amarillo</div></td>
      <td style="border-color:#2E7D3255;"><div class="n" style="color:#2E7D32;"><?= (int)($pc['verde'] ?? 0) ?></div><div class="l">Verde</div></td>
      <td style="border-color:#2B2B2B55;"><div class="n" style="color:#2B2B2B;"><?= (int)($pc['derrumbado'] ?? 0) ?></div><div class="l">Derrumbado</div></td>
    </tr></table>
  </div>

  <!-- Equipo desplegado -->
  <?php $encs = $d['encargados'] ?? []; $frentes = $d['frentes'] ?? []; $tipos = $d['frente_tipos'] ?? []; ?>
  <?php if ($encs || $frentes): ?>
  <div class="bloque">
    <h2>Equipo desplegado</h2>
    <table class="equipo" style="width:100%;">
      <?php foreach ($encs as $r): ?>
      <tr>
        <td style="width:180px;"><span class="rol">Responsable de parroquia</span><br>
            <strong style="color:#22366F;"><?= esc($r['nombre']) ?></strong></td>
        <td><?= esc($r['telefono'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($frentes as $f): ?>
      <tr>
        <td><span class="rol"><?= esc($tipos[$f['tipo']] ?? $f['tipo']) ?></span><br>
            <?= esc($f['nombre']) ?>
            <?php if (!empty($f['sector'])): ?>
              <span style="color:#8a6d1a;font-size:8px;">(<?= esc($f['sector']) ?>)</span>
            <?php endif; ?></td>
        <td><?= esc($f['telefono'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <!-- Detalle completo -->
  <div class="bloque">
    <h2>Detalle por edificación (<?= count($edifs) ?>)</h2>
    <table class="det">
      <thead><tr>
        <th style="width:22px;">#</th>
        <th style="width:14px;"></th>
        <th>Edificación</th>
        <th style="width:80px;">Código</th>
        <th style="width:110px;">Ente responsable</th>
        <th style="width:78px;">Avance</th>
        <th style="width:34px;"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($edifs as $i => $ed):
        $av = (int)($ed['avance'] ?? 0);
        $col = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#C9A227' : '#b9bfcd'));
        $cd = $cat[$ed['decision_final'] ?? '']['color'] ?? '#767c94';
      ?>
      <tr>
        <td style="color:#97a0b8;"><?= $i + 1 ?></td>
        <td><span class="dot" style="background:<?= $cd ?>;"></span></td>
        <td><?= esc($ed['nombre'] ?? 'Sin nombre') ?></td>
        <td style="color:#767c94;"><?= esc($ed['codigo'] ?? '') ?></td>
        <td style="color:#55617f;"><?= esc($ed['ente'] ?? '—') ?></td>
        <td><span class="mini"><span style="display:block;width:<?= $av ?>%;background:<?= $col ?>;height:100%;"></span></span></td>
        <td class="pt" style="color:<?= $col ?>;"><?= $av ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="pie">
    Sistema de Seguimiento y Control de Reconstrucción ·
    Generado el <?= $fecha ?> a las <?= $hora ?> por <?= esc($_SESSION['user_nombre'] ?? $_SESSION['nombre'] ?? 'usuario del sistema') ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

// Generar el PDF con wkhtmltopdf.
$tmpHtml = sys_get_temp_dir() . '/parr_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/parr_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 10mm --margin-bottom 10mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Informe_' . preg_replace('/[^A-Za-z0-9]+/', '_', $parroquia) . '_' . date('Y-m-d') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

// Si wkhtmltopdf no está disponible, se muestra el HTML para imprimir desde el navegador.
@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</body>', '<script>window.onload=function(){setTimeout(function(){window.print();},400);};</script></body>', $html);
