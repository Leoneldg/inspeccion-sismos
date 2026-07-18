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

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function colPct(int $p): string {
    if ($p >= 100) return '#2E7D32';
    if ($p >= 75)  return '#5a9e3f';
    if ($p > 0)    return '#a8871f';
    return '#5b6478';
}

// Modo: una parroquia concreta, o TODAS las asignadas al responsable.
$misParroquias = parroquiasDelUsuario();
if ($parroquia !== '') {
    if (!puedeAccederParroquia($parroquia)) {
        http_response_code(403);
        exit('No tiene asignada esta parroquia.');
    }
    $lista = [$parroquia];
} else {
    if (!$misParroquias) { http_response_code(400); exit('Indique una parroquia.'); }
    $lista = $misParroquias;
}
$esGeneral = count($lista) > 1;
$cat = catalogoDecisionFinal();

// Datos por parroquia + consolidado general.
$datos = [];
$gTotal = 0; $gSuma = 0; $gCulm = 0;
$gColor = ['rojo'=>0,'amarillo'=>0,'verde'=>0,'derrumbado'=>0];
$gProg  = [];
foreach ($lista as $pa) {
    $dd = recPanelParroquia($estado, $pa);
    $dd['progreso'] = asigProgresoPorMiembro($estado, $pa, 'gdc');
    $dd['asigs']    = asigDeParroquia($estado, $pa);
    $ee = $dd['edificaciones'] ?? [];
    recOrdenarPorColor($ee);
    $dd['edificaciones'] = $ee;
    $n  = count($ee);
    $su = array_sum(array_column($ee, 'avance'));
    $dd['total']      = $n;
    $dd['avance']     = $n > 0 ? (int)round($su / $n) : 0;
    $dd['culminadas'] = count(array_filter($ee, fn($x) => ($x['avance'] ?? 0) >= 100));
    $gTotal += $n; $gSuma += $su; $gCulm += $dd['culminadas'];
    foreach ($gColor as $k => $_) $gColor[$k] += (int)($dd['por_color'][$k] ?? 0);
    foreach ($dd['progreso'] as $m => $pr) {
        if (!isset($gProg[$m])) $gProg[$m] = ['total'=>0,'culminadas'=>0,'en_proceso'=>0,'sin_comenzar'=>0,'suma'=>0,'avance'=>0,'parroquias'=>[]];
        foreach (['total','culminadas','en_proceso','sin_comenzar','suma'] as $k) $gProg[$m][$k] += $pr[$k];
        $gProg[$m]['parroquias'][] = $pa;
    }
    $datos[$pa] = $dd;
}
foreach ($gProg as $m => $pr) {
    $gProg[$m]['avance'] = $pr['total'] > 0 ? (int)round($pr['suma'] / $pr['total']) : 0;
}
uasort($gProg, fn($a, $b) => $b['avance'] <=> $a['avance']);
$gAvance = $gTotal > 0 ? (int)round($gSuma / $gTotal) : 0;

// Variables que usa el resto de la plantilla.
$parroquia = $lista[0];
$d     = $datos[$parroquia];
$asigs = $d['asigs'];
$resAp = recResumenAptosParroquia($estado, $parroquia);
$edifs = $d['edificaciones'];
$pc    = $gColor;
$avg   = $gAvance;

$tramos = ['listo'=>0,'avanzado'=>0,'medio'=>0,'inicial'=>0,'sin'=>0];
foreach ($datos as $dd) foreach ($dd['edificaciones'] as $e) {
    $a = (int)($e['avance'] ?? 0);
    if ($a >= 100)    $tramos['listo']++;
    elseif ($a >= 75) $tramos['avanzado']++;
    elseif ($a >= 25) $tramos['medio']++;
    elseif ($a > 0)   $tramos['inicial']++;
    else              $tramos['sin']++;
}

$fecha = date('d/m/Y');
$hora  = date('H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 12.5px; }
  .hoja { padding: 26px 30px; }
  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 12px; margin-bottom: 16px; }
  .cab h1 { margin: 0; font-size: 34px; color: #22366F; letter-spacing: -.5px; font-weight: 800; }
  .cab .sub { color: #55617f; font-size: 13px; margin-top: 4px; }
  .cab .fecha { float: right; text-align: right; color: #55617f; font-size: 12px; font-weight: 600; }

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
  table.det th { background: #22366F; color: #fff; font-size: 10.5px; padding: 8px 5px;
                 text-align: left; text-transform: uppercase; letter-spacing: .3px; }
  table.det td { font-size: 11.5px; padding: 7px 5px; border-bottom: 1px solid #dde2ec; }
  .simb { font-size: 15px; font-weight: 700; line-height: 1; }
  .letra { display:inline-block; width:17px; height:17px; line-height:17px; text-align:center;
           border:2px solid; border-radius:4px; font-weight:800; font-size:10px; }
  table.det tr:nth-child(even) td { background: #fafbfe; }
  .mini { background: #e8ebf3; border-radius: 6px; height: 11px; width: 76px; display: inline-block; overflow: hidden; border: 1px solid #cfd6e4; }
  .mini > div { height: 100%; }
  .pt { font-weight: 700; text-align: right; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }

  .equipo td { font-size: 9.5px; padding: 4px 5px; border-bottom: 1px solid #f0f2f7; }
  .equipo .rol { font-size: 8px; text-transform: uppercase; color: #97a0b8; }

  .salto { page-break-before: always; }
  .pie { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 8.5px; color: #97a0b8; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="fecha"><?= $fecha ?><br><?= $hora ?></div>
    <h1><?= $esGeneral ? 'MIS PARROQUIAS' : esc(mb_strtoupper($parroquia, 'UTF-8')) ?></h1>
    <div class="sub">Informe de reconstrucción · <?= esc($estado) ?><?php if ($esGeneral): ?>
      · <?= count($lista) ?> parroquias: <?= esc(implode(', ', $lista)) ?><?php endif; ?></div>
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

  <?php if ($esGeneral): ?>
  <!-- Total por parroquia (solo en el informe general) -->
  <div class="bloque">
    <h2>Total por parroquia</h2>
    <table class="det">
      <thead><tr>
        <th>Parroquia</th>
        <th style="width:62px;">Total</th>
        <th style="width:74px;">Culminadas</th>
        <th style="width:74px;">En proceso</th>
        <th style="width:80px;">Avance</th>
        <th style="width:42px;"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($datos as $pa => $dd): $c = colPct((int)$dd['avance']); ?>
      <tr>
        <td style="font-weight:700;color:#22366F;"><?= esc(mb_strtoupper($pa, 'UTF-8')) ?></td>
        <td><strong><?= (int)$dd['total'] ?></strong></td>
        <td style="color:#2E7D32;"><strong><?= (int)$dd['culminadas'] ?></strong></td>
        <td style="color:#a8871f;"><strong><?= (int)$dd['total'] - (int)$dd['culminadas'] ?></strong></td>
        <td><span class="mini"><span style="display:block;width:<?= (int)$dd['avance'] ?>%;background:<?= $c ?>;height:100%;"></span></span></td>
        <td class="pt" style="color:<?= $c ?>;"><?= (int)$dd['avance'] ?>%</td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:#eef2fb;">
        <td style="font-weight:800;color:#22366F;">TOTAL GENERAL</td>
        <td><strong><?= $gTotal ?></strong></td>
        <td style="color:#2E7D32;"><strong><?= $gCulm ?></strong></td>
        <td style="color:#a8871f;"><strong><?= $gTotal - $gCulm ?></strong></td>
        <td><span class="mini"><span style="display:block;width:<?= $gAvance ?>%;background:<?= colPct($gAvance) ?>;height:100%;"></span></span></td>
        <td class="pt" style="color:<?= colPct($gAvance) ?>;"><?= $gAvance ?>%</td>
      </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Asignaciones y progreso del equipo de trabajo -->
  <?php if ($gProg): ?>
  <div class="bloque">
    <h2>Asignaciones del equipo de trabajo<?= $esGeneral ? ' (consolidado)' : '' ?></h2>
    <table style="width:100%;border-collapse:separate;border-spacing:6px;">
      <tr>
      <?php $i = 0; foreach ($gProg as $miembro => $pr):
        $c = colPct((int)$pr['avance']);
        if ($i > 0 && $i % 2 === 0) echo '</tr><tr>';
        $i++;
      ?>
        <td style="width:50%;border:1px solid #dde3ef;border-radius:9px;padding:10px 12px;vertical-align:top;">
          <span style="font-weight:800;font-size:18px;float:right;color:<?= $c ?>;"><?= (int)$pr['avance'] ?>%</span>
          <span style="font-weight:700;color:#22366F;font-size:13.5px;"><?= esc($miembro) ?></span>
          <div style="background:#eef0f6;border-radius:20px;height:10px;overflow:hidden;margin:7px 0 6px;border:1px solid #dde3ef;">
            <div style="width:<?= (int)$pr['avance'] ?>%;background:<?= $c ?>;height:100%;"></div>
          </div>
          <div style="font-size:11px;color:#3a4256;">
            <strong><?= (int)$pr['total'] ?></strong> asignadas &nbsp;·&nbsp;
            <span style="color:#2E7D32;"><strong><?= (int)$pr['culminadas'] ?></strong> listas</span> &nbsp;·&nbsp;
            <span style="color:#a8871f;"><strong><?= (int)$pr['en_proceso'] ?></strong> en obra</span> &nbsp;·&nbsp;
            <span style="color:#5b6478;"><strong><?= (int)$pr['sin_comenzar'] ?></strong> sin iniciar</span>
          </div>
          <?php if ($esGeneral): ?>
          <div style="font-size:10px;color:#767c94;margin-top:3px;">
            <?= esc(implode(', ', array_unique($pr['parroquias']))) ?>
          </div>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
      <?php if ($i % 2 === 1) echo '<td style="border:0;"></td>'; ?>
      </tr>
    </table>
  </div>
  <?php endif; ?>

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

  <!-- Detalle completo, una seccion por parroquia -->
  <?php $primeraP = true; foreach ($datos as $pa => $dd):
      $edifs = $dd['edificaciones'];
      $asigs = $dd['asigs'];
      $resAp = recResumenAptosParroquia($estado, $pa);
  ?>
  <div class="bloque <?= (!$primeraP && $esGeneral) ? 'salto' : '' ?>">
    <?php if ($esGeneral): ?>
    <div style="background:#22366F;color:#fff;padding:8px 12px;border-radius:7px;font-size:15px;font-weight:700;margin-bottom:10px;">
      PARROQUIA <?= esc(mb_strtoupper($pa, 'UTF-8')) ?>
      <span style="float:right;font-weight:600;font-size:13px;">
        <?= (int)$dd['total'] ?> edificaciones · <?= (int)$dd['avance'] ?>% de avance
      </span>
    </div>
    <?php endif; ?>
    <h2>PARROQUIA <?= esc(mb_strtoupper($pa, 'UTF-8')) ?> · Detalle por edificación (<?= count($edifs) ?>)</h2>
    <div style="background:#f4f7fd;border-radius:8px;padding:9px 12px;margin-bottom:10px;font-size:11px;">
      <strong>Cómo leer esta tabla:</strong>
      <span class="letra" style="color:#C9A227;border-color:#C9A227;">A</span> amarillo, precaución &nbsp;
      <span class="letra" style="color:#A61C1C;border-color:#A61C1C;">R</span> rojo, no entrar &nbsp;
      <span class="letra" style="color:#2E7D32;border-color:#2E7D32;">V</span> verde, habitable &nbsp;
      <span class="letra" style="color:#2B2B2B;border-color:#2B2B2B;">D</span> derrumbada &nbsp;·&nbsp;
      La barra y el número indican cuánto avanzó la reconstrucción.
    </div>
    <table class="det">
      <thead><tr>
        <th style="width:22px;">#</th>
        <th style="width:30px;">Clas.</th>
        <th>Edificación</th>
        <th style="width:80px;">Código</th>
        <th style="width:92px;">Ente</th>
        <th style="width:78px;">Responsable</th>
        <th style="width:56px;">Aptos</th>
        <th style="width:70px;">Avance</th>
        <th style="width:34px;"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($edifs as $i => $ed):
        $av = (int)($ed['avance'] ?? 0);
        $col = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#C9A227' : '#b9bfcd'));
        $cd = $cat[$ed['decision_final'] ?? '']['color'] ?? '#767c94';
        $sim = recSimboloDecision($ed['decision_final'] ?? null);
      ?>
      <tr>
        <td style="color:#97a0b8;"><?= $i + 1 ?></td>
        <td><span class="letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"><?= $sim['letra'] ?></span></td>
        <td><?= esc($ed['nombre'] ?? 'Sin nombre') ?></td>
        <td style="color:#767c94;"><?= esc($ed['codigo'] ?? '') ?></td>
        <td style="color:#55617f;"><?= esc($ed['ente'] ?? '—') ?></td>
        <td style="color:#2d4488;"><?= esc($asigs[(int)$ed['id']]['gdc'] ?? '—') ?></td>
        <td style="color:#55617f;font-size:9px;">
            <?php $ra = $resAp[(int)$ed['id']] ?? null; ?>
            <?= $ra ? $ra['culminados'] . '/' . $ra['total'] : '—' ?>
        </td>
        <td><span class="mini"><span style="display:block;width:<?= $av ?>%;background:<?= $col ?>;height:100%;"></span></span></td>
        <td class="pt" style="color:<?= $col ?>;"><?= $av ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $primeraP = false; endforeach; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Seguimiento y Control de la Reconstrucción ·
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
