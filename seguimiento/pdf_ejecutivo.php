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

/**
 * El badge de sacos de cemento gris vive en includes/seguimiento.php
 * (badgeSacosCementoGris) para que todas las vistas usen el mismo calculo.
 */
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
    $conds = ['re.completado = 1', recSqlEdificioCompleto('re')];
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

// Todas las parroquias donde hubo levantamientos, de mayor a menor.
$parr = $c['parroquias'];
uasort($parr, fn($a, $b) => $b['edificios'] <=> $a['edificios']);
$topParr = $parr;
$restoN = 0;

$fecha = date('d/m/Y');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10px; }
  .hoja { padding: 12px 20px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 7px; margin-bottom: 9px; }
  .cab h1 { margin: 0; font-size: 19px; color: #22366F; font-weight: 800; }
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

  .caja { border: 1px solid #e0e4ee; border-radius: 8px; padding: 8px 11px; }
  .caja h3 { margin: 0 0 9px; font-size: 13px; color: #22366F;
             text-transform: uppercase; letter-spacing: .4px; font-weight: 800; }

  .leyenda { font-size: 9.5px; }
  .leyenda .li { padding: 3px 0; }
  .leyenda .pt { display: inline-block; width: 9px; height: 9px;
                 border-radius: 2px; margin-right: 5px; }
  .leyenda .v { float: right; font-weight: 700; }

  table.t { width: 100%; border-collapse: collapse; }
  table.t th { font-size: 10px; color: #55617f; text-transform: uppercase;
               text-align: left; padding: 5px 6px; font-weight: 700;
               border-bottom: 1px solid #dde2ec; letter-spacing: .3px; }
  table.t td { font-size: 12px; padding: 6px; border-bottom: 1px solid #f2f4f8; }

  .mat-cab { background: #C9A227; color: #22366F; padding: 11px 16px;
             border-radius: 8px 8px 0 0; font-size: 16px; font-weight: 800;
             letter-spacing: .5px; }
  .mat-caja { border: 2px solid #C9A227; border-top: 0; border-radius: 0 0 8px 8px;
              padding: 9px 12px; background: #fffdf5; }
  .mat { display: table; width: 100%; border-spacing: 4px; }
  .mat .m { display: table-cell; background: #fff; border: 1px solid #C9A22755;
            border-radius: 7px; padding: 7px 9px; width: 25%; text-align: center; }
  .mat .c { font-size: 23px; font-weight: 800; color: #22366F; line-height: 1; }
  .mat .u { font-size: 10px; color: #2a3140; margin-top: 5px; font-weight: 600;
            line-height: 1.3; }

  .pie { margin-top: 9px; font-size: 8px; color: #767c94; text-align: center; }
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

  <!-- Las dos cifras principales -->
  <div style="display:table;width:100%;border-spacing:7px 0;margin-bottom:9px;">
    <div style="display:table-cell;width:50%;background:#22366F;
                border-radius:9px;padding:13px 14px;text-align:center;">
      <div style="font-size:34px;font-weight:800;color:#fff;line-height:1;">
        <?= ent($c['edificios']) ?>
      </div>
      <div style="font-size:10px;color:#ffffffcc;text-transform:uppercase;
                  margin-top:6px;letter-spacing:.4px;">
        Edificios con levantamiento técnico
      </div>
    </div>
    <div style="display:table-cell;width:50%;background:#2d4488;
                border-radius:9px;padding:13px 14px;text-align:center;">
      <div style="font-size:34px;font-weight:800;color:#fff;line-height:1;">
        <?= ent($c['apartamentos']) ?>
      </div>
      <div style="font-size:10px;color:#ffffffcc;text-transform:uppercase;
                  margin-top:6px;letter-spacing:.4px;">
        Apartamentos totales
      </div>
    </div>
  </div>

  <!-- Las dos cifras de trabajo -->
  <?php
  // Total de trabajo a ejecutar: la suma de las tres fases. Una misma
  // pared se cuenta en varias porque pasa por varias etapas.
  $etK  = $c['por_etapa'] ?? [];
  $m2Real = ($etK['demolicion']['m2'] ?? 0)
          + ($etK['construccion']['m2'] ?? 0)
          + ($etK['revestimiento']['m2'] ?? 0);
  ?>
  <div style="display:table;width:100%;border-spacing:7px 0;margin-bottom:10px;">
    <div style="display:table-cell;width:50%;background:#C9A227;
                border-radius:9px;padding:11px 14px;text-align:center;">
      <div style="font-size:26px;font-weight:800;color:#22366F;line-height:1;">
        <?= ent($c['aptos_reparar']) ?>
      </div>
      <div style="font-size:9.5px;color:#22366Fcc;text-transform:uppercase;
                  margin-top:5px;letter-spacing:.3px;">
        Apartamentos a reparar
      </div>
    </div>
    <div style="display:table-cell;width:50%;background:#5b6478;
                border-radius:9px;padding:11px 14px;text-align:center;">
      <div style="font-size:26px;font-weight:800;color:#fff;line-height:1;">
        <?= num($m2Real, 0) ?>
      </div>
      <div style="font-size:9.5px;color:#ffffffcc;text-transform:uppercase;
                  margin-top:5px;letter-spacing:.3px;">
        m² totales a intervenir
      </div>
    </div>
  </div>

  <!-- Material por etapa -->
  <?php
  $et = $c['por_etapa'] ?? [];
  $dem = $et['demolicion'] ?? [];
  $con = $et['construccion'] ?? [];
  $rev = $et['revestimiento'] ?? [];
  ?>

  <div class="mat-cab">MATERIAL QUE SE REQUIERE, POR ETAPA</div>
  <div class="mat-caja">

    <!-- DEMOLICIÓN -->
    <?php if (!empty($dem['m2'])): ?>
    <div style="margin-bottom:11px;">
      <div style="font-size:14px;font-weight:800;color:#A61C1C;
                  margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px;">
        Demolición · <?= num($dem['m2'], 0) ?> m² a tumbar
      </div>
      <div style="display:table;width:100%;border-spacing:5px;">
        <div style="display:table-cell;width:33%;background:#fff;
                    border:1px solid #A61C1C44;border-radius:7px;
                    padding:9px 11px;text-align:center;">
          <div style="font-size:24px;font-weight:800;color:#A61C1C;line-height:1;">
            <?= num($dem['m3'], 1) ?></div>
          <div style="font-size:10.5px;color:#2a3140;margin-top:5px;font-weight:600;">
            m³ de escombro</div>
        </div>
        <div style="display:table-cell;width:33%;background:#fff;
                    border:1px solid #A61C1C44;border-radius:7px;
                    padding:9px 11px;text-align:center;">
          <div style="font-size:24px;font-weight:800;color:#A61C1C;line-height:1;">
            <?= ent($dem['sacos']) ?></div>
          <div style="font-size:10.5px;color:#2a3140;margin-top:5px;font-weight:600;">
            sacos de 0,05 m³</div>
        </div>
        <div style="display:table-cell;width:33%;background:#fff;
                    border:1px solid #A61C1C44;border-radius:7px;
                    padding:9px 11px;text-align:center;">
          <div style="font-size:24px;font-weight:800;color:#A61C1C;line-height:1;">
            <?= num($dem['camiones'], 1) ?></div>
          <div style="font-size:10.5px;color:#2a3140;margin-top:5px;font-weight:600;">
            viajes de camión (7 m³)</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- CONSTRUCCIÓN -->
    <?php if (!empty($con['materiales'])): ?>
    <div style="margin-bottom:11px;">
      <div style="font-size:14px;font-weight:800;color:#22366F;
                  margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px;">
        Reconstrucción · <?= num($con['m2'], 0) ?> m² de pared a levantar
      </div>
      <div class="mat">
        <?php $i = 0; foreach ($con['materiales'] as $m): ?>
          <?php if ($i > 0 && $i % 4 === 0): ?></div><div class="mat"><?php endif; ?>
          <div class="m" style="border-color:#22366F44;">
            <div class="c"><?= num($m['cantidad'], $m['unidad'] === 'saco' || $m['unidad'] === 'unidad' ? 0 : 2) ?></div>
            <?= badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#22366F', '8px') ?>
            <div class="u"><?= esc($m['unidad']) ?><br><?= esc($m['material']) ?></div>
          </div>
        <?php $i++; endforeach; ?>
        <?php while ($i % 4 !== 0): ?>
          <div class="m" style="border:0;background:none;"></div>
        <?php $i++; endwhile; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- REVESTIMIENTO -->
    <?php if (!empty($rev['materiales'])): ?>
    <div>
      <div style="font-size:14px;font-weight:800;color:#a8871f;
                  margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px;">
        Revestimiento · <?= num($rev['m2'], 0) ?> m² a frisar y pintar
      </div>
      <div class="mat">
        <?php $i = 0; foreach ($rev['materiales'] as $m): ?>
          <?php if ($i > 0 && $i % 4 === 0): ?></div><div class="mat"><?php endif; ?>
          <div class="m">
            <div class="c"><?= num($m['cantidad'], $m['unidad'] === 'm3' ? 2 : 0) ?></div>
            <?= badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#a8871f', '8px') ?>
            <div class="u"><?= esc($m['unidad']) ?><br><?= esc($m['material']) ?></div>
          </div>
        <?php $i++; endforeach; ?>
        <?php while ($i % 4 !== 0): ?>
          <div class="m" style="border:0;background:none;"></div>
        <?php $i++; endwhile; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- TOTAL CONSOLIDADO -->
    <?php $tot = $et['total'] ?? []; ?>
    <?php if ($tot): ?>
    <div style="margin-top:13px;padding-top:12px;border-top:2px solid #C9A227;">
      <div style="font-size:14px;font-weight:800;color:#22366F;
                  margin-bottom:8px;text-transform:uppercase;letter-spacing:.4px;">
        Total a pedir
      </div>
      <div class="mat">
        <?php $i = 0; foreach ($tot as $m): ?>
          <?php if ($i > 0 && $i % 4 === 0): ?></div><div class="mat"><?php endif; ?>
          <div class="m" style="border-color:#22366F55;background:#f7f9fd;">
            <div class="c" style="font-size:24px;">
              <?= num($m['cantidad'], $m['unidad'] === 'm3' ? 2 : 0) ?></div>
            <?= badgeSacosCementoGris($m['material'], (float)$m['cantidad'], $m['unidad'], '#22366F', '8px') ?>
            <div class="u"><?= esc($m['unidad']) ?><br><?= esc($m['material']) ?></div>
          </div>
        <?php $i++; endforeach; ?>
        <?php while ($i % 4 !== 0): ?>
          <div class="m" style="border:0;background:none;"></div>
        <?php $i++; endwhile; ?>
      </div>

    </div>
    <?php endif; ?>

    <div style="font-size:8.5px;color:#8a6d1a;margin-top:7px;">
      Incluye 10% de holgura. Escombro a 0,15 m³/m². Revestimiento por ambas caras.
    </div>
    <?php if (!empty($et['avisos']['materiales_sin_etapa'])): ?>
    <div style="font-size:8px;color:#A61C1C;margin-top:4px;">
      ⚠ Hay materiales en la receta sin clasificar por etapa (no se sumaron aquí):
      <?= esc(implode(' · ', $et['avisos']['materiales_sin_etapa'])) ?>.
      Clasifíquelos en Configuración › Materiales y rendimientos.
    </div>
    <?php endif; ?>
  </div>

  <!-- Torta y parroquias, lado a lado -->
  <div class="fila">
    <div class="col" style="width:32%;">
      <div class="caja">
        <h3>Apartamentos</h3>
        <?php
        $apTot = (int)$c['apartamentos'];
        $apRep = (int)$c['aptos_reparar'];
        $apSin = max(0, $apTot - $apRep);
        if ($apTot > 0):
            $angRep = $apRep / $apTot * 360;
            $svg  = sectorTorta(75, 75, 68, 0, $angRep, '#C9A227');
            $svg .= sectorTorta(75, 75, 68, $angRep, 360, '#2E7D32');
        ?>
        <div style="text-align:center;margin-bottom:9px;">
          <svg width="126" height="126" viewBox="0 0 150 150">
            <?= $svg ?>
            <circle cx="75" cy="75" r="36" fill="#fff"/>
            <text x="75" y="71" text-anchor="middle" font-size="21"
                  font-weight="bold" fill="#22366F"><?= ent($apTot) ?></text>
            <text x="75" y="87" text-anchor="middle" font-size="9"
                  fill="#5b6478">apartamentos</text>
          </svg>
        </div>

        <div style="font-size:12.5px;">
          <div style="padding:6px 0;border-bottom:1px solid #f2f4f8;">
            <span style="display:inline-block;width:11px;height:11px;
                  border-radius:2px;background:#C9A227;margin-right:6px;"></span>
            <strong style="color:#a8871f;">A reparar</strong>
            <span style="float:right;font-weight:800;color:#a8871f;">
              <?= ent($apRep) ?> · <?= round($apRep / $apTot * 100) ?>%
            </span>
          </div>
          <div style="padding:5px 0;">
            <span style="display:inline-block;width:11px;height:11px;
                  border-radius:2px;background:#2E7D32;margin-right:6px;"></span>
            <strong style="color:#2E7D32;">Sin daños</strong>
            <span style="float:right;font-weight:800;color:#2E7D32;">
              <?= ent($apSin) ?> · <?= round($apSin / $apTot * 100) ?>%
            </span>
          </div>
        </div>
        <?php else: ?>
        <p style="color:#767c94;font-size:10px;">Sin apartamentos registrados.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col" style="width:68%;">
      <div class="caja">
        <h3>Dónde está el trabajo</h3>
        <table class="t">
          <thead><tr>
            <th>Parroquia</th>
            <th style="width:60px;text-align:center;">Edif.</th>
            <th style="width:68px;text-align:center;">Aptos.</th>
            <th style="width:72px;text-align:center;">A reparar</th>
            <th style="width:66px;text-align:right;">m²</th>
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
