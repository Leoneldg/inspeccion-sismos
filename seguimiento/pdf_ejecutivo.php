<?php
/**
 * PDF EJECUTIVO · UNA PÁGINA.
 *
 * Lo esencial del programa para una lectura rápida: cuánto se lleva,
 * cómo está repartido y qué material hace falta.
 *
 * El gráfico se dibuja en SVG porque wkhtmltopdf no ejecuta JavaScript.
 *
 * Uso: pdf_ejecutivo.php
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

/**
 * Dibuja un sector de la torta.
 * Los ángulos van en grados, empezando arriba.
 */
function sectorTorta(float $cx, float $cy, float $r, float $ini, float $fin, string $color): string
{
    // Un sector de 360° no se puede dibujar con arco: se usa un círculo.
    if ($fin - $ini >= 359.99) {
        return "<circle cx=\"$cx\" cy=\"$cy\" r=\"$r\" fill=\"$color\"/>";
    }
    $rad = M_PI / 180;
    $x1 = $cx + $r * cos(($ini - 90) * $rad);
    $y1 = $cy + $r * sin(($ini - 90) * $rad);
    $x2 = $cx + $r * cos(($fin - 90) * $rad);
    $y2 = $cy + $r * sin(($fin - 90) * $rad);
    $grande = ($fin - $ini) > 180 ? 1 : 0;

    return sprintf(
        '<path d="M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z" fill="%s"/>',
        $cx, $cy, $x1, $y1, $r, $r, $grande, $x2, $y2, $color
    );
}

$c = segConsolidadoMateriales();

// Clasificación por color, de los levantamientos cerrados.
$porColor = [];
try {
    $conds = ['re.completado = 1'];
    $params = [];
    aplicarScopeEstado($conds, $params, 'i');
    aplicarScopeParroquia($conds, $params, 'i');
    $st = db()->prepare("
        SELECT i.decision_final AS dec, COUNT(*) AS n
          FROM inspecciones i
          JOIN rec_edificio re ON re.inspeccion_id = i.id
         WHERE " . implode(' AND ', $conds) . "
         GROUP BY i.decision_final
    ");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $porColor[$r['dec']] = (int)$r['n'];
} catch (Throwable $e) {}

$COL = [
    'Edificación Insegura - Acceso No Permitido'   => ['Rojo', '#A61C1C'],
    'Acceso Restringido - Precaución al Entrar'    => ['Amarillo', '#C9A227'],
    'Edificación Inspeccionada - Acceso Permitido' => ['Verde', '#2E7D32'],
    'Derrumbado'                                   => ['Derrumbado', '#2B2B2B'],
];

// Las cinco parroquias con más edificios; el resto se agrupa.
$parr = $c['parroquias'];
arsort($parr);
$topParr = array_slice($parr, 0, 6, true);
$restoN = 0;
$i = 0;
foreach ($parr as $pn => $p) {
    if ($i++ >= 6) $restoN += $p['edificios'];
}

$fecha = date('d/m/Y');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10px; }
  .hoja { padding: 16px 22px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 9px; margin-bottom: 12px; }
  .cab h1 { margin: 0; font-size: 22px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; }
  .cab .sub { font-size: 10.5px; color: #55617f; }

  .kpis { display: table; width: 100%; border-spacing: 5px 0; margin-bottom: 13px; }
  .kpis .k { display: table-cell; text-align: center; padding: 12px 4px;
             border-radius: 8px; }
  .kpis .n { font-size: 22px; font-weight: 800; line-height: 1; }
  .kpis .l { font-size: 8px; text-transform: uppercase; margin-top: 4px;
             letter-spacing: .3px; }

  .fila { display: table; width: 100%; border-spacing: 8px 0; }
  .fila .col { display: table-cell; vertical-align: top; }

  .caja { border: 1px solid #e0e4ee; border-radius: 8px; padding: 10px 12px; }
  .caja h3 { margin: 0 0 8px; font-size: 11px; color: #22366F;
             text-transform: uppercase; letter-spacing: .3px; }

  .leyenda { font-size: 9.5px; }
  .leyenda .li { padding: 3px 0; }
  .leyenda .pt { display: inline-block; width: 9px; height: 9px;
                 border-radius: 2px; margin-right: 5px; }
  .leyenda .v { float: right; font-weight: 700; }

  table.t { width: 100%; border-collapse: collapse; }
  table.t th { font-size: 8px; color: #767c94; text-transform: uppercase;
               text-align: left; padding: 3px 4px; border-bottom: 1px solid #e0e4ee; }
  table.t td { font-size: 9.5px; padding: 4px; border-bottom: 1px solid #f2f4f8; }

  .mat-cab { background: #C9A227; color: #22366F; padding: 8px 14px;
             border-radius: 8px 8px 0 0; font-size: 12.5px; font-weight: 800; }
  .mat-caja { border: 2px solid #C9A227; border-top: 0; border-radius: 0 0 8px 8px;
              padding: 11px 14px; background: #fffdf5; }
  .mat { display: table; width: 100%; border-spacing: 5px; }
  .mat .m { display: table-cell; background: #fff; border: 1px solid #C9A22755;
            border-radius: 7px; padding: 9px 11px; width: 25%; text-align: center; }
  .mat .c { font-size: 16px; font-weight: 800; color: #22366F; line-height: 1; }
  .mat .u { font-size: 8px; color: #5b6478; margin-top: 3px; }

  .pie { margin-top: 12px; font-size: 8px; color: #767c94; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?></div>
    <h1>Programa de reconstrucción</h1>
    <div class="sub">Resumen ejecutivo · Levantamientos completados</div>
  </div>

  <?php if ($c['edificios'] === 0): ?>
    <p style="color:#767c94;padding:60px;text-align:center;font-size:12px;">
      Todavía no hay levantamientos cerrados.
    </p>
  <?php else: ?>

  <!-- Las cinco cifras que importan -->
  <div class="kpis">
    <div class="k" style="background:#22366F;">
      <div class="n" style="color:#fff;"><?= ent($c['edificios']) ?></div>
      <div class="l" style="color:#ffffffcc;">Edificaciones</div>
    </div>
    <div class="k" style="background:#2d4488;">
      <div class="n" style="color:#fff;"><?= ent($c['apartamentos']) ?></div>
      <div class="l" style="color:#ffffffcc;">Apartamentos</div>
    </div>
    <div class="k" style="background:#C9A227;">
      <div class="n" style="color:#22366F;"><?= ent($c['aptos_reparar']) ?></div>
      <div class="l" style="color:#22366Fcc;">A reparar</div>
    </div>
    <div class="k" style="background:#2E7D32;">
      <div class="n" style="color:#fff;"><?= ent($c['familias']) ?></div>
      <div class="l" style="color:#ffffffcc;">Familias</div>
    </div>
    <div class="k" style="background:#5b6478;">
      <div class="n" style="color:#fff;"><?= num($c['m2_total'], 0) ?></div>
      <div class="l" style="color:#ffffffcc;">m² a reparar</div>
    </div>
  </div>

  <!-- Torta y parroquias, lado a lado -->
  <div class="fila">
    <div class="col" style="width:38%;">
      <div class="caja">
        <h3>Estado de las edificaciones</h3>
        <?php
        $totC = array_sum($porColor);
        if ($totC > 0):
            $svg = '';
            $ang = 0;
            foreach ($COL as $dec => $m) {
                $n = $porColor[$dec] ?? 0;
                if ($n <= 0) continue;
                $delta = $n / $totC * 360;
                $svg .= sectorTorta(75, 75, 68, $ang, $ang + $delta, $m[1]);
                $ang += $delta;
            }
        ?>
        <div style="text-align:center;margin-bottom:8px;">
          <svg width="150" height="150" viewBox="0 0 150 150">
            <?= $svg ?>
            <circle cx="75" cy="75" r="34" fill="#fff"/>
            <text x="75" y="72" text-anchor="middle" font-size="19"
                  font-weight="bold" fill="#22366F"><?= ent($totC) ?></text>
            <text x="75" y="87" text-anchor="middle" font-size="8"
                  fill="#767c94">edificios</text>
          </svg>
        </div>

        <div class="leyenda">
          <?php foreach ($COL as $dec => $m):
              $n = $porColor[$dec] ?? 0;
              if ($n <= 0) continue;
              $pct = round($n / $totC * 100);
          ?>
          <div class="li">
            <span class="pt" style="background:<?= $m[1] ?>;"></span>
            <?= $m[0] ?>
            <span class="v"><?= ent($n) ?> · <?= $pct ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="color:#767c94;font-size:9.5px;">Sin datos de clasificación.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col" style="width:62%;">
      <div class="caja">
        <h3>Dónde está el trabajo</h3>
        <table class="t">
          <thead><tr>
            <th>Parroquia</th>
            <th style="width:52px;text-align:center;">Edif.</th>
            <th style="width:60px;text-align:center;">Aptos.</th>
            <th style="width:62px;text-align:center;">A reparar</th>
            <th style="width:58px;text-align:right;">m²</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topParr as $pn => $p): ?>
            <tr>
              <td style="font-weight:600;"><?= esc($pn) ?></td>
              <td style="text-align:center;font-weight:700;"><?= ent($p['edificios']) ?></td>
              <td style="text-align:center;"><?= ent($p['apartamentos']) ?></td>
              <td style="text-align:center;color:#a8871f;font-weight:700;">
                <?= ent($p['aptos_reparar']) ?></td>
              <td style="text-align:right;"><?= num($p['m2'], 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($restoN > 0): ?>
            <tr>
              <td style="color:#767c94;font-style:italic;">
                Otras <?= count($parr) - count($topParr) ?> parroquias</td>
              <td style="text-align:center;color:#767c94;"><?= ent($restoN) ?></td>
              <td colspan="3"></td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>

        <?php if ($c['friso'] > 0 || $c['pintura'] > 0): ?>
        <div style="background:#eef2fb;border-radius:6px;padding:7px 10px;
                    margin-top:8px;font-size:9.5px;color:#22366F;">
          <strong>Superficie a intervenir:</strong>
          <?php if ($c['friso'] > 0): ?><?= num($c['friso'], 0) ?> m² de friso<?php endif; ?>
          <?php if ($c['pintura'] > 0): ?> · <?= num($c['pintura'], 0) ?> m² de pintura<?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Material -->
  <?php if ($c['materiales']): ?>
  <div style="margin-top:13px;">
    <div class="mat-cab">MATERIAL QUE SE REQUIERE</div>
    <div class="mat-caja">
      <div class="mat">
        <?php $i = 0; foreach (array_slice($c['materiales'], 0, 8) as $m): ?>
          <?php if ($i > 0 && $i % 4 === 0): ?></div><div class="mat"><?php endif; ?>
          <div class="m">
            <div class="c"><?= num($m['cantidad'], 0) ?></div>
            <div class="u"><?= esc($m['unidad']) ?><br><?= esc($m['material']) ?></div>
          </div>
        <?php $i++; endforeach; ?>
        <?php while ($i % 4 !== 0): ?>
          <div class="m" style="border:0;background:none;"></div>
        <?php $i++; endwhile; ?>
      </div>
      <div style="font-size:8.5px;color:#8a6d1a;margin-top:7px;">
        Incluye 10% de holgura por desperdicio y roturas.
        Calculado sobre <?= ent($c['edificios']) ?> levantamientos completados.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · <?= $fecha ?> ·
    Cifras de los levantamientos cerrados a la fecha
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/eje_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/eje_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 8mm --margin-bottom 8mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Resumen_ejecutivo_' . date('Y-m-d') . '.pdf';

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
