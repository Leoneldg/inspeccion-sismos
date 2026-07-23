<?php
/**
 * PDF · MATERIALES CONSOLIDADOS DE VARIOS EDIFICIOS, POR PISO.
 *
 * Recibe los edificios seleccionados y arma el informe de material a
 * comprar: total general, desglose por piso (pisos equivalentes de
 * todos los edificios sumados) y áreas comunes.
 *
 * Uso: seguimiento/pdf_materiales_multi.php?edificios[]=1&edificios[]=2...
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/materiales_multi.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n2($v)  { return number_format((float)$v, 2, ',', '.'); }

// Edificios seleccionados (y validar que el usuario puede verlos: la
// función de datos ya aplica el scope, así que IDs fuera de alcance
// simplemente no traen resultados).
$ids = $_GET['edificios'] ?? [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_filter(array_map('intval', $ids)));

$data = matConsolidadoPorPiso($ids);

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');
$numEdif = count($data['edificios']);

/**
 * Dibuja una tabla de materiales (nombre => cantidad).
 */
function tablaMateriales(array $mats): string {
    if (!$mats) return '<div class="vacio">Sin materiales.</div>';
    $h = '<table class="t"><thead><tr><th>Material</th><th class="r">Cantidad</th></tr></thead><tbody>';
    foreach ($mats as $nombre => $cant) {
        $h .= '<tr><td>' . esc($nombre) . '</td><td class="r">' . n2($cant) . '</td></tr>';
    }
    $h .= '</tbody></table>';
    return $h;
}

/**
 * Dibuja la lista de trabajos (nombre => m²) como línea compacta.
 */
function lineaTrabajos(array $porTrabajo): string {
    if (!$porTrabajo) return '';
    $partes = [];
    foreach ($porTrabajo as $nombre => $m2) {
        $partes[] = esc($nombre) . ': ' . n2($m2) . ' m²';
    }
    return '<div class="trab">' . implode(' · ', $partes) . '</div>';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10px; }
  .hoja { padding: 18px 24px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 10px; margin-bottom: 12px; }
  .cab h1 { margin: 0; font-size: 20px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; }
  .cab .sub { font-size: 10.5px; color: #55617f; margin-top: 2px; }

  h2 { font-size: 12.5px; color: #fff; background: #22366F; margin: 14px 0 6px;
       padding: 5px 9px; border-radius: 5px; }
  h2.total { background: #2E7D32; }
  h2.comun { background: #8B4513; }

  .lista-edif { font-size: 9px; color: #55617f; margin-bottom: 4px; }

  table.t { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.t th { background: #eef2fb; color: #22366F; font-size: 8.5px; padding: 4px 7px;
               text-align: left; text-transform: uppercase; }
  table.t th.r, table.t td.r { text-align: right; }
  table.t td { font-size: 9.5px; padding: 3px 7px; border-bottom: 1px solid #eef0f5; }
  table.t tr:nth-child(even) td { background: #fafbfe; }

  .trab { font-size: 8px; color: #7a8398; margin: 2px 0 8px; font-style: italic; }
  .vacio { font-size: 9px; color: #9aa2b4; padding: 4px 0; }

  .piso-bloque { margin-bottom: 6px; }
  .dos { display: table; width: 100%; border-spacing: 8px 0; }
  .dos > div { display: table-cell; width: 50%; vertical-align: top; }

  .nota { margin-top: 14px; font-size: 8px; color: #7a8398;
          border-top: 1px solid #e0e4ee; padding-top: 7px; }
</style>
</head>
<body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?> · <?= $hora ?><br><?= esc($quien) ?></div>
    <h1>Materiales por piso — varios edificios</h1>
    <div class="sub">
      Material consolidado de <?= $numEdif ?> edificación<?= $numEdif === 1 ? '' : 'es' ?>,
      desglosado por piso y áreas comunes.
    </div>
  </div>

  <?php if ($numEdif === 0): ?>
    <div class="vacio">No se recibieron edificios válidos para el informe.</div>
  <?php else: ?>

    <!-- Edificios incluidos -->
    <div class="lista-edif">
      <strong>Edificaciones incluidas:</strong>
      <?php
      $noms = [];
      foreach ($data['edificios'] as $e) {
          $noms[] = $e['nombre'] . ($e['parroquia'] ? ' (' . $e['parroquia'] . ')' : '');
      }
      echo esc(implode(' · ', $noms));
      ?>
    </div>

    <!-- TOTAL GENERAL -->
    <h2 class="total">Total general a comprar</h2>
    <?= tablaMateriales($data['total']) ?>

    <!-- POR PISO -->
    <?php if ($data['por_piso']): ?>
    <h2>Desglose por piso <span style="font-weight:400;font-size:9px;">(pisos equivalentes de todos los edificios, sumados)</span></h2>
    <div class="dos">
      <?php
      // Repartir los pisos en dos columnas para aprovechar la hoja.
      $mitad = (int)ceil(count($data['por_piso']) / 2);
      $cols = [array_slice($data['por_piso'], 0, $mitad), array_slice($data['por_piso'], $mitad)];
      foreach ($cols as $colPisos): ?>
      <div>
        <?php foreach ($colPisos as $piso): ?>
        <div class="piso-bloque">
          <div style="font-weight:700;color:#22366F;font-size:11px;margin-bottom:2px;">
            <?= esc($piso['etiqueta']) ?>
          </div>
          <?= lineaTrabajos($piso['por_trabajo']) ?>
          <?= tablaMateriales($piso['materiales']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ÁREAS COMUNES -->
    <?php if (!empty($data['areas_comunes']['materiales'])): ?>
    <h2 class="comun">Áreas comunes (de todos los edificios)</h2>
    <?= lineaTrabajos($data['areas_comunes']['por_trabajo']) ?>
    <?= tablaMateriales($data['areas_comunes']['materiales']) ?>
    <?php endif; ?>

    <div class="nota">
      Cantidades calculadas con el margen de holgura configurado en el
      sistema. Los materiales que se compran por unidad entera (bloques,
      sacos, piezas) se redondean hacia arriba. El desglose "por piso"
      suma los pisos equivalentes de todos los edificios elegidos (el
      piso 1 de todos juntos, el piso 2 de todos juntos, etc.). Verifique
      en obra antes de ejecutar compras.
    </div>

  <?php endif; ?>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/matmulti_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/matmulti_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Materiales_por_piso_' . date('Y-m-d') . '.pdf';
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
