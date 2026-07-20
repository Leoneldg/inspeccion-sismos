<?php
/**
 * PDF COMPLETO DEL LEVANTAMIENTO.
 *
 * Todo lo registrado de una edificación: datos, resumen de visitas,
 * trabajos, materiales y el detalle piso por piso con cada ambiente.
 *
 * Uso: pdf_levantamiento.php?inspeccion=4449
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Número con coma decimal, como se escribe en Venezuela. */
function num($v, $dec = 2) {
    return number_format((float)$v, $dec, ',', '.');
}

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) exit('Indique la inspección.');

$insp = segInspeccion($inspeccionId);
if (!$insp) exit('No se encontró la inspección.');

$ed = recEdificio($inspeccionId);
$edificioId = (int)($ed['id'] ?? 0);
if ($edificioId <= 0) exit('Esta edificación no tiene levantamiento.');

// Todo el árbol de una vez.
$arbol   = recArbolAvance($edificioId);
$pisos   = $arbol['pisos'] ?? [];
$aptosR  = $arbol['aptos_reparar'] ?? [];
$visitas = $aptosR['visitas'] ?? [];
$trabajos = $arbol['detalle_trabajos'] ?? [];
$materiales = $arbol['materiales'] ?? [];
$global  = $arbol['global_acabados'] ?? [];
$autor   = recAutorLevantamiento($edificioId);

// Áreas comunes.
$areas = [];
try { $areas = recAreasComunesConNombre($edificioId); } catch (Throwable $e) {}

$cat = catalogoDecisionFinal();
$dec = $insp['decision_final'] ?? '';
$meta = $cat[$dec] ?? ['color' => '#767c94', 'corto' => 'Sin clasificar'];

$fecha = date('d/m/Y');
$hora  = date('H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10.5px; }
  .hoja { padding: 20px 24px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 10px; margin-bottom: 13px; }
  .cab h1 { margin: 0; font-size: 21px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 10px; color: #55617f; }

  .franja { color: #fff; padding: 9px 14px; border-radius: 7px; margin-bottom: 12px; }
  .franja .n { font-size: 17px; font-weight: 800; }
  .franja .c { font-size: 10.5px; opacity: .92; }

  h2 { font-size: 13px; color: #22366F; margin: 16px 0 8px;
       border-bottom: 1px solid #e0e4ee; padding-bottom: 4px; }

  .datos { display: table; width: 100%; }
  .datos .f { display: table-row; }
  .datos .c { display: table-cell; padding: 3px 10px 3px 0; width: 25%; }
  .datos .e { font-size: 8.5px; color: #767c94; text-transform: uppercase; }
  .datos .v { font-size: 11px; font-weight: 600; }

  .kpis { display: table; width: 100%; border-spacing: 5px 0; margin-bottom: 10px; }
  .kpis .k { display: table-cell; text-align: center; padding: 8px 4px;
             border-radius: 7px; border: 1px solid #e0e4ee; }
  .kpis .n { font-size: 17px; font-weight: 800; }
  .kpis .l { font-size: 8px; text-transform: uppercase; color: #55617f; }

  table.t { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  table.t th { background: #f1f3f8; color: #22366F; font-size: 8.5px; padding: 5px 6px;
               text-align: left; text-transform: uppercase; border-bottom: 1px solid #dde2ec; }
  table.t td { font-size: 9.5px; padding: 4px 6px; border-bottom: 1px solid #eef0f5; }
  table.t tr:nth-child(even) td { background: #fafbfe; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }

  .piso { page-break-inside: avoid; margin-bottom: 12px; }
  .piso-cab { background: #22366F; color: #fff; padding: 6px 12px;
              border-radius: 6px 6px 0 0; font-size: 11.5px; font-weight: 700; }
  .apto { border: 1px solid #e5e8f0; border-top: 0; padding: 7px 12px; }
  .apto-nom { font-size: 11px; font-weight: 700; color: #2a3140; }
  .etq { display: inline-block; font-size: 8px; font-weight: 700; border-radius: 8px;
         padding: 1px 7px; margin-left: 5px; }
  .amb { font-size: 9.5px; padding: 2px 0 2px 12px; color: #2a3140; }
  .amb .m { color: #22366F; font-weight: 700; }

  .mat { display: table; width: 100%; border-spacing: 5px; }
  .mat .m { display: table-cell; background: #fffdf5; border: 1px solid #C9A22744;
            border-radius: 7px; padding: 8px 10px; width: 25%; }
  .mat .c { font-size: 15px; font-weight: 800; color: #22366F; }
  .mat .n { font-size: 8.5px; color: #5b6478; }

  .pie { margin-top: 14px; padding-top: 7px; border-top: 1px solid #e8ebf3;
         font-size: 8px; color: #767c94; text-align: center; }
  .firma { display: table; width: 100%; margin-top: 26px; }
  .firma .f { display: table-cell; width: 50%; text-align: center; padding: 0 24px; }
  .firma .l { border-top: 1px solid #2a3140; padding-top: 4px; font-size: 9px; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Levantamiento técnico</h1>
    <div style="font-size:10px;color:#55617f;">Detalle completo de la edificación</div>
  </div>

  <!-- Identificación -->
  <div class="franja" style="background:<?= $meta['color'] ?>;">
    <div class="n"><?= esc($insp['nombre_edificio'] ?: 'Sin nombre') ?></div>
    <div class="c">
      <?= esc($meta['corto']) ?> · <?= esc($insp['codigo']) ?>
      <?php if (!empty($insp['parroquia'])): ?> · <?= esc($insp['parroquia']) ?><?php endif; ?>
    </div>
  </div>

  <div class="datos">
    <div class="f">
      <div class="c"><div class="e">Parroquia</div>
        <div class="v"><?= esc($insp['parroquia'] ?: '—') ?></div></div>
      <div class="c"><div class="e">Municipio</div>
        <div class="v"><?= esc($insp['municipio'] ?: '—') ?></div></div>
      <div class="c"><div class="e">Uso</div>
        <div class="v"><?= esc($insp['uso_edificacion'] ?: '—') ?></div></div>
      <div class="c"><div class="e">Pisos</div>
        <div class="v"><?= (int)($arbol['total_pisos'] ?? 0) ?></div></div>
    </div>
    <div class="f">
      <div class="c" style="width:50%;"><div class="e">Dirección</div>
        <div class="v" style="font-size:10px;">
          <?= esc(trim(implode(', ', array_filter([
              $insp['avenida_calle'] ?? '', $insp['sector'] ?? '',
              $insp['urbanizacion'] ?? '',
          ]))) ?: '—') ?>
        </div></div>
      <div class="c"><div class="e">Familias</div>
        <div class="v"><?= (int)($insp['familias'] ?? 0) ?></div></div>
      <div class="c"><div class="e">Personas</div>
        <div class="v"><?= (int)($insp['numero_personas'] ?? 0) ?></div></div>
    </div>
    <?php if (!empty($autor['creado_nombre'])): ?>
    <div class="f">
      <div class="c" style="width:50%;"><div class="e">Levantamiento realizado por</div>
        <div class="v" style="font-size:10px;"><?= esc($autor['creado_nombre']) ?></div></div>
      <div class="c"><div class="e">Fecha</div>
        <div class="v" style="font-size:10px;">
          <?= !empty($autor['creado_en'])
              ? date('d/m/Y', strtotime($autor['creado_en'])) : '—' ?>
        </div></div>
      <div class="c"><div class="e">Estado</div>
        <div class="v" style="font-size:10px;">
          <?= !empty($ed['completado']) ? 'Cerrado' : 'En proceso' ?>
        </div></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Resumen -->
  <h2>Resumen del levantamiento</h2>
  <div class="kpis">
    <div class="k" style="border-color:#22366F33;">
      <div class="n" style="color:#22366F;"><?= (int)($aptosR['total'] ?? 0) ?></div>
      <div class="l">Apartamentos</div>
    </div>
    <div class="k" style="border-color:#C9A22755;">
      <div class="n" style="color:#a8871f;"><?= (int)($aptosR['con_reparacion'] ?? 0) ?></div>
      <div class="l">A reparar</div>
    </div>
    <div class="k" style="border-color:#2E7D3233;">
      <div class="n" style="color:#2E7D32;"><?= (int)($aptosR['sin_reparacion'] ?? 0) ?></div>
      <div class="l">Sin daños</div>
    </div>
    <div class="k" style="border-color:#97a0b833;">
      <div class="n" style="color:#5b6478;"><?= (int)($aptosR['ambientes_a_reparar'] ?? 0) ?></div>
      <div class="l">Ambientes</div>
    </div>
    <div class="k" style="border-color:#2d448833;">
      <div class="n" style="color:#2d4488;"><?= num($arbol['m2_total'] ?? 0) ?></div>
      <div class="l">m² a reparar</div>
    </div>
  </div>

  <!-- Resultado de las visitas -->
  <?php
  $vis = [
      'inspeccionado'    => ['Inspeccionados', '#2E7D32'],
      'sin_dano'         => ['Sin daño', '#2E7D32'],
      'cuenta_propia'    => ['Reparan por cuenta propia', '#2d4488'],
      'permiso_denegado' => ['No dejó entrar', '#A61C1C'],
      'no_esta'          => ['Ocupante ausente', '#5b6478'],
      'sin_visitar'      => ['Sin visitar', '#a8871f'],
  ];
  $hayVis = false;
  foreach ($vis as $k => $v) if (!empty($visitas[$k])) $hayVis = true;
  ?>
  <?php if ($hayVis): ?>
  <table class="t">
    <thead><tr><th>Resultado de las visitas</th><th style="width:70px;text-align:center;">Cantidad</th></tr></thead>
    <tbody>
    <?php foreach ($vis as $k => $v):
        if (empty($visitas[$k])) continue; ?>
      <tr>
        <td style="color:<?= $v[1] ?>;font-weight:600;"><?= $v[0] ?></td>
        <td style="text-align:center;font-weight:700;"><?= (int)$visitas[$k] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Trabajos -->
  <?php if ($trabajos): ?>
  <h2>Trabajos a realizar</h2>
  <table class="t">
    <thead><tr>
      <th>Trabajo</th>
      <th style="width:66px;text-align:right;">m²</th>
      <th style="width:60px;text-align:center;">Ambientes</th>
      <th style="width:66px;text-align:center;">Aptos.</th>
    </tr></thead>
    <tbody>
    <?php foreach ($trabajos as $t): ?>
      <tr>
        <td style="font-weight:600;"><?= esc($t['nombre']) ?></td>
        <td style="text-align:right;font-weight:700;color:#22366F;"><?= num($t['m2']) ?></td>
        <td style="text-align:center;"><?= (int)$t['ambientes'] ?></td>
        <td style="text-align:center;"><?= (int)$t['apartamentos'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($global['friso']) || !empty($global['pintura'])): ?>
  <div style="background:#eef2fb;border-radius:7px;padding:8px 12px;font-size:10px;
              margin-bottom:8px;">
    <strong style="color:#22366F;">Superficie total a cubrir:</strong>
    <?php if (!empty($global['friso'])): ?>
      <?= num($global['friso']) ?> m² de friso
    <?php endif; ?>
    <?php if (!empty($global['pintura'])): ?>
      · <?= num($global['pintura']) ?> m² de pintura
    <?php endif; ?>
    <div style="font-size:8.5px;color:#767c94;">
      Una pared cubierta por ambas caras cuenta el doble de sus metros.
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Materiales -->
  <?php if ($materiales): ?>
  <h2>Materiales estimados</h2>
  <div class="mat">
    <?php $i = 0; foreach ($materiales as $mat => $cant): ?>
      <?php if ($i > 0 && $i % 4 === 0): ?></div><div class="mat"><?php endif; ?>
      <div class="m">
        <div class="c"><?= num($cant) ?></div>
        <div class="n"><?= esc($mat) ?></div>
      </div>
    <?php $i++; endforeach; ?>
    <?php // Rellenar la última fila para que no se estire
    while ($i % 4 !== 0): ?><div class="m" style="border:0;background:none;"></div>
    <?php $i++; endwhile; ?>
  </div>
  <div style="font-size:8.5px;color:#767c94;margin-bottom:8px;">
    Cálculo aproximado según los metros registrados. Verifique en obra antes de solicitar.
  </div>
  <?php endif; ?>

  <!-- Áreas comunes -->
  <?php
  $areasRep = array_filter($areas, fn($a) => !empty($a['necesita_reparacion']));
  ?>
  <?php if ($areasRep): ?>
  <h2>Áreas comunes a reparar</h2>
  <table class="t">
    <thead><tr>
      <th>Área</th>
      <th style="width:80px;">Estado</th>
      <th style="width:60px;text-align:right;">m²</th>
    </tr></thead>
    <tbody>
    <?php foreach ($areasRep as $a): ?>
      <tr>
        <td style="font-weight:600;"><?= esc($a['etiqueta']) ?></td>
        <td><?= esc($a['estado'] ?: '—') ?></td>
        <td style="text-align:right;">
          <?= !empty($a['metros_cuadrados']) ? num($a['metros_cuadrados']) : '—' ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Detalle piso por piso -->
  <h2>Detalle por piso</h2>
  <?php foreach ($pisos as $p): ?>
  <div class="piso">
    <div class="piso-cab">
      Piso <?= (int)$p['numero_piso'] ?>
      <span style="float:right;font-weight:600;font-size:9.5px;">
        <?= count($p['apartamentos'] ?? []) ?> apartamento(s)
      </span>
    </div>

    <?php foreach (($p['apartamentos'] ?? []) as $ap):
        $est = $ap['estado_visita'] ?? '';
        $ambRep = array_filter($ap['ambientes'] ?? [],
                               fn($x) => !empty($x['necesita_reparacion']));
        $etqs = [
            'sin_dano'         => ['Sin daño', '#2E7D32'],
            'cuenta_propia'    => ['Repara por cuenta propia', '#2d4488'],
            'permiso_denegado' => ['No dejó entrar', '#A61C1C'],
            'no_esta'          => ['Ocupante ausente', '#5b6478'],
            'no_requiere'      => ['No requiere ayuda', '#2E7D32'],
        ];
    ?>
    <div class="apto">
      <div class="apto-nom">
        Apto <?= esc($ap['identificador']) ?>
        <?php if (isset($etqs[$est])): ?>
        <span class="etq" style="background:<?= $etqs[$est][1] ?>22;
              color:<?= $etqs[$est][1] ?>;"><?= $etqs[$est][0] ?></span>
        <?php elseif ($ambRep): ?>
        <span class="etq" style="background:#C9A22722;color:#a8871f;">
          <?= count($ambRep) ?> ambiente(s) a reparar
        </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($ap['jefe_nombre'])): ?>
      <div style="font-size:9px;color:#5b6478;">
        <?= esc($ap['jefe_nombre']) ?>
        <?php if (!empty($ap['jefe_cedula'])): ?> · <?= esc($ap['jefe_cedula']) ?><?php endif; ?>
        <?php if (!empty($ap['jefe_telefono'])): ?> · <?= esc($ap['jefe_telefono']) ?><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($ap['visita_obs'])): ?>
      <div style="font-size:8.5px;color:#767c94;font-style:italic;">
        <?= esc($ap['visita_obs']) ?>
      </div>
      <?php endif; ?>

      <?php foreach ($ambRep as $am):
          $m2 = 0;
          foreach (($am['m2_por_parte'] ?? []) as $v) $m2 += (float)$v;
      ?>
      <div class="amb">
        · <?= esc($am['tipo']) ?> <?= (int)$am['numero'] ?>
        <?php if ($m2 > 0): ?>
          <span class="m"><?= num($m2) ?> m²</span>
        <?php endif; ?>
        <?php if (!empty($am['m2_por_parte'])): ?>
          <span style="color:#767c94;font-size:8.5px;">
            (<?php
              $ps = [];
              foreach ($am['m2_por_parte'] as $sup => $v) {
                  if ($v > 0) $ps[] = $sup . ' ' . num($v);
              }
              echo esc(implode(' · ', $ps));
            ?>)
          </span>
        <?php endif; ?>
        <?php if (!empty($am['fotos_antes'])): ?>
          <span style="color:#2E7D32;font-size:8.5px;">
            · <?= (int)$am['fotos_antes'] ?> foto(s)
          </span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <!-- Firmas -->
  <div class="firma">
    <div class="f"><div class="l">Firma del técnico</div></div>
    <div class="f"><div class="l">Firma del supervisor</div></div>
  </div>

  <div class="pie">
    Gestión de Obras Avanzadas · <?= esc($insp['codigo']) ?> ·
    Generado el <?= $fecha ?> a las <?= $hora ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/lev_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/lev_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Levantamiento_' . preg_replace('/[^A-Za-z0-9]/', '_', $insp['codigo'] ?? 'edif')
        . '_' . date('Y-m-d') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

// Si falta wkhtmltopdf, mostrar el HTML.
@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo $html;
