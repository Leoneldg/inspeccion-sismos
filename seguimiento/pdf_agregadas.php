<?php
/**
 * INFORME PDF — Edificaciones agregadas en campo.
 *
 * Las que no estaban en el listado original. Incluye su clasificación,
 * el estado de la etiqueta, la ubicación y quién las registró.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$lista = segEdificacionesAgregadas();
$cat   = catalogoDecisionFinal();

// Resumen
$porColor = ['amarillo' => 0, 'rojo' => 0, 'verde' => 0, 'derrumbado' => 0];
$sinEtq = 0; $conFoto = 0; $porParroquia = []; $porUsuario = [];
foreach ($lista as $e) {
    $sim = recSimboloDecision($e['decision_final'] ?? null);
    $k = mb_strtolower($sim['texto'], 'UTF-8');
    if (isset($porColor[$k])) $porColor[$k]++;
    if (!empty($e['sin_etiqueta'])) $sinEtq++;
    if ((int)($e['fotos_etiqueta'] ?? 0) > 0) $conFoto++;
    $p = $e['parroquia'] ?: 'Sin parroquia';
    $porParroquia[$p] = ($porParroquia[$p] ?? 0) + 1;
    $u = $e['registrada_por'] ?: 'Sin registro';
    $porUsuario[$u] = ($porUsuario[$u] ?? 0) + 1;
}
arsort($porParroquia);
arsort($porUsuario);

// Agrupar el detalle por parroquia.
$agrupadas = [];
foreach ($lista as $e) {
    $agrupadas[$e['parroquia'] ?: 'Sin parroquia'][] = $e;
}
ksort($agrupadas);

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 12px; }
  .hoja { padding: 26px 30px; }
  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 12px; margin-bottom: 16px; }
  .cab h1 { margin: 0; font-size: 26px; color: #22366F; font-weight: 800; }
  .cab .sub { color: #55617f; font-size: 12.5px; margin-top: 4px; }
  .cab .fecha { float: right; text-align: right; color: #55617f; font-size: 12px; font-weight: 600; }

  h2 { font-size: 12.5px; text-transform: uppercase; letter-spacing: .5px; color: #22366F;
       margin: 17px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e8ebf3; font-weight: 700; }

  .destacado { background: #f4f7fd; border-radius: 9px; padding: 14px 16px; margin-bottom: 14px; }
  .destacado .n { font-size: 42px; font-weight: 800; color: #2E7D32; line-height: 1; }
  .destacado .l { font-size: 12px; color: #55617f; }

  table.res { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 4px; }
  table.res td { text-align: center; padding: 10px 4px; border-radius: 8px; border: 1px solid #e0e4ee; }
  table.res .n { font-size: 21px; font-weight: 800; }
  table.res .l { font-size: 9px; text-transform: uppercase; color: #444; }

  table.det { width: 100%; border-collapse: collapse; }
  table.det thead { display: table-header-group; }
  table.det tr { page-break-inside: avoid; }
  table.det th { background: #22366F; color: #fff; font-size: 10px; padding: 7px 5px;
                 text-align: left; text-transform: uppercase; letter-spacing: .3px; }
  table.det td { font-size: 11px; padding: 6px 5px; border-bottom: 1px solid #dde2ec; }
  table.det tr:nth-child(even) td { background: #fafbfe; }

  .letra { display:inline-block; width:18px; height:18px; line-height:18px; text-align:center;
           border:2px solid; border-radius:4px; font-weight:800; font-size:10.5px; }
  .parr-cab { background:#eef2fb; padding:7px 11px; border-radius:7px; font-weight:700;
              color:#22366F; font-size:12.5px; margin:14px 0 7px; }
  .chip { font-size:9.5px; padding:2px 7px; border-radius:9px; white-space:nowrap; }
  .pie { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 9px; color: #767c94; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="fecha"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Edificaciones agregadas</h1>
    <div class="sub">Registradas en campo, fuera del listado original</div>
  </div>

  <?php if (!$lista): ?>
    <p style="color:#767c94;">Todavía no hay edificaciones agregadas en campo.</p>
  <?php else: ?>

  <!-- Resumen -->
  <div class="destacado">
    <table style="width:100%;"><tr>
      <td style="width:130px;vertical-align:middle;">
        <div class="n"><?= count($lista) ?></div>
        <div class="l">Edificaciones</div>
      </td>
      <td style="vertical-align:middle;font-size:12.5px;color:#55617f;">
        Encontradas durante el trabajo de campo en
        <strong style="color:#22366F;"><?= count($porParroquia) ?></strong> parroquia(s),
        registradas por <strong style="color:#22366F;"><?= count($porUsuario) ?></strong> persona(s).
      </td>
    </tr></table>
  </div>

  <h2>Clasificación</h2>
  <table class="res"><tr>
    <td style="border-color:#C9A22755;"><div class="n" style="color:#a8871f;"><?= $porColor['amarillo'] ?></div><div class="l">A · Amarillo</div></td>
    <td style="border-color:#A61C1C55;"><div class="n" style="color:#A61C1C;"><?= $porColor['rojo'] ?></div><div class="l">R · Rojo</div></td>
    <td style="border-color:#2E7D3255;"><div class="n" style="color:#2E7D32;"><?= $porColor['verde'] ?></div><div class="l">V · Verde</div></td>
    <td style="border-color:#2B2B2B55;"><div class="n" style="color:#2B2B2B;"><?= $porColor['derrumbado'] ?></div><div class="l">D · Derrumbado</div></td>
  </tr></table>

  <h2>Estado de la etiqueta</h2>
  <table class="res"><tr>
    <td style="border-color:#2E7D3255;"><div class="n" style="color:#2E7D32;"><?= $conFoto ?></div><div class="l">Con foto</div></td>
    <td style="border-color:#C9A22755;"><div class="n" style="color:#a8871f;"><?= $sinEtq ?></div><div class="l">No tiene etiqueta</div></td>
    <td style="border-color:#97a0b855;"><div class="n" style="color:#5b6478;"><?= count($lista) - $conFoto - $sinEtq ?></div><div class="l">Pendiente</div></td>
  </tr></table>

  <h2>Quién las registró</h2>
  <table class="det">
    <thead><tr><th>Persona</th><th style="width:90px;">Edificaciones</th></tr></thead>
    <tbody>
    <?php foreach ($porUsuario as $u => $n): ?>
      <tr><td><?= esc($u) ?></td><td><strong><?= $n ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Detalle por parroquia -->
  <h2>Detalle</h2>
  <div style="background:#f4f7fd;border-radius:8px;padding:9px 12px;margin-bottom:10px;font-size:10.5px;">
    <strong>Cómo leer:</strong>
    <span class="letra" style="color:#C9A227;border-color:#C9A227;">A</span> amarillo &nbsp;
    <span class="letra" style="color:#A61C1C;border-color:#A61C1C;">R</span> rojo &nbsp;
    <span class="letra" style="color:#2E7D32;border-color:#2E7D32;">V</span> verde &nbsp;
    <span class="letra" style="color:#2B2B2B;border-color:#2B2B2B;">D</span> derrumbada
  </div>

  <?php foreach ($agrupadas as $parr => $edifs): ?>
    <div class="parr-cab">
      <?= esc(mb_strtoupper($parr, 'UTF-8')) ?>
      <span style="float:right;font-weight:600;font-size:11px;"><?= count($edifs) ?> edificación(es)</span>
    </div>
    <table class="det">
      <thead><tr>
        <th style="width:26px;">Cl.</th>
        <th>Edificación</th>
        <th style="width:84px;">Código</th>
        <th style="width:104px;">Ubicación</th>
        <th style="width:74px;">Etiqueta</th>
        <th style="width:92px;">Registró</th>
        <th style="width:62px;">Fecha</th>
      </tr></thead>
      <tbody>
      <?php foreach ($edifs as $e):
        $sim = recSimboloDecision($e['decision_final'] ?? null);
        $tieneFoto = (int)($e['fotos_etiqueta'] ?? 0) > 0;
        $sinE = !empty($e['sin_etiqueta']);
      ?>
      <tr>
        <td><span class="letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"><?= $sim['letra'] ?></span></td>
        <td>
          <strong><?= esc($e['nombre_edificio'] ?: 'Sin nombre') ?></strong>
          <?php if (!empty($e['direccion'])): ?>
            <div style="font-size:9.5px;color:#767c94;"><?= esc(mb_strimwidth($e['direccion'], 0, 60, '…', 'UTF-8')) ?></div>
          <?php endif; ?>
        </td>
        <td style="color:#55617f;"><?= esc($e['codigo']) ?></td>
        <td style="font-size:9.5px;color:#55617f;">
          <?php if (!empty($e['latitud'])): ?>
            <?= number_format((float)$e['latitud'], 5) ?>,<br><?= number_format((float)$e['longitud'], 5) ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <?php if ($tieneFoto): ?>
            <span class="chip" style="background:#2E7D3218;color:#2E7D32;">Con foto</span>
          <?php elseif ($sinE): ?>
            <span class="chip" style="background:#C9A22722;color:#8a6d1a;">No tiene</span>
            <?php if (!empty($e['etiqueta_motivo'])): ?>
              <div style="font-size:8.5px;color:#8a6d1a;"><?= esc($e['etiqueta_motivo']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="chip" style="background:#f1f2f6;color:#5b6478;">Pendiente</span>
          <?php endif; ?>
        </td>
        <td style="font-size:10px;color:#55617f;"><?= esc($e['registrada_por'] ?: '—') ?></td>
        <td style="font-size:9.5px;color:#767c94;">
          <?= !empty($e['registrada_en']) ? date('d/m/y', strtotime($e['registrada_en'])) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Edificaciones agregadas en campo ·
    Generado el <?= $fecha ?> a las <?= $hora ?> por <?= esc($quien) ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/agr_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/agr_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 10mm --margin-bottom 10mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Edificaciones_agregadas_' . date('Y-m-d') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</body>', '<script>window.onload=function(){setTimeout(function(){window.print();},400);};</script></body>', $html);
