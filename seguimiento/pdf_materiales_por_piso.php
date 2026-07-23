<?php
/**
 * PDF · MATERIALES POR PISO (todos los edificios).
 *
 * Total consolidado de materiales para un número de piso, sumando
 * todos los edificios del alcance, más el desglose de qué necesita
 * cada construcción. Sirve para comprar por lote y repartir.
 *
 * Uso: pdf_materiales_por_piso.php?piso=3[&parroquia=Catedral]
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

// --- Parámetros ---
if (!isset($_GET['piso']) || $_GET['piso'] === '') {
    http_response_code(400);
    exit('Indique el piso.');
}
$numeroPiso = (int)$_GET['piso'];

$parroquia = trim($_GET['parroquia'] ?? '');
if ($parroquia !== '' && !puedeAccederParroquia($parroquia)) {
    $parroquia = '';
}
$filtros = $parroquia !== '' ? ['parroquia' => $parroquia] : [];

$res = recMaterialesPorPisoConDesglose($numeroPiso, $filtros);
$total = $res['total'] ?? [];
$porEdificio = $res['por_edificio'] ?? [];
ksort($total, SORT_NATURAL | SORT_FLAG_CASE);

$etiqueta = $numeroPiso === 0 ? 'Planta baja' : ('Piso ' . $numeroPiso);
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
  .cab h1 { margin: 0; font-size: 22px; color: #22366F; font-weight: 800; }
  .cab .der { float: right; text-align: right; font-size: 10px; color: #55617f; }
  .cab .sub { font-size: 11px; color: #55617f; margin-top: 3px; }

  h2 { font-size: 13.5px; color: #22366F; margin: 16px 0 8px;
       border-bottom: 1px solid #e0e4ee; padding-bottom: 5px; }
  h3 { font-size: 11.5px; color: #22366F; margin: 12px 0 5px; }

  table.t { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.t th { background: #22366F; color: #fff; font-size: 9px; padding: 6px 9px;
               text-align: left; text-transform: uppercase; }
  table.t th.r, table.t td.r { text-align: right; }
  table.t td { font-size: 10.5px; padding: 5px 9px; border-bottom: 1px solid #eef0f5; }
  table.t tr:nth-child(even) td { background: #fafbfe; }

  .edif { border: 1px solid #dde2ec; border-radius: 7px; margin-bottom: 10px;
          overflow: hidden; page-break-inside: avoid; }
  .edif .th { background: #eef2fb; color: #22366F; font-weight: 700;
              padding: 6px 10px; font-size: 11px; }
  .edif table.mini { width: 100%; border-collapse: collapse; }
  .edif table.mini td { font-size: 10px; padding: 4px 10px; border-bottom: 1px solid #f0f2f7; }
  .edif table.mini td.r { text-align: right; font-weight: 600; }

  .nota { margin-top: 14px; font-size: 9px; color: #7a8398;
          border-top: 1px solid #e0e4ee; padding-top: 8px; }
  .vacio { color: #7a8398; font-size: 11px; padding: 20px 0; text-align: center; }
</style>
</head>
<body>
<div class="hoja">

  <div class="cab">
    <div class="der">
      <?= $fecha ?> · <?= $hora ?><br>
      <?= esc($quien) ?>
    </div>
    <h1>Materiales · <?= esc($etiqueta) ?></h1>
    <div class="sub">
      Total de todos los edificios<?= $parroquia !== '' ? ' de ' . esc($parroquia) : '' ?>
      y desglose por construcción.
    </div>
  </div>

  <?php if (!$total): ?>
    <div class="vacio">
      No hay materiales registrados para <?= esc(mb_strtolower($etiqueta)) ?> todavía.
    </div>
  <?php else: ?>

    <!-- TOTAL general -->
    <h2>Total a comprar (<?= esc($etiqueta) ?>)</h2>
    <table class="t">
      <thead><tr><th>Material</th><th class="r">Cantidad</th></tr></thead>
      <tbody>
        <?php foreach ($total as $mat => $cant): ?>
        <tr>
          <td><?= esc($mat) ?></td>
          <td class="r"><?= num($cant) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Desglose por edificio -->
    <?php if ($porEdificio): ?>
    <h2>Qué necesita cada edificio</h2>
    <?php foreach ($porEdificio as $ed): ?>
      <div class="edif">
        <div class="th"><?= esc($ed['nombre']) ?></div>
        <table class="mini">
          <?php foreach ($ed['materiales'] as $mat => $cant): ?>
          <tr>
            <td><?= esc($mat) ?></td>
            <td class="r"><?= num($cant) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

  <?php endif; ?>

  <div class="nota">
    Cálculo estimado sobre los metros registrados en los levantamientos.
    Verifique en obra antes de solicitar. Las áreas comunes (pasillos,
    escaleras, fachada) no se incluyen en el filtro por piso porque
    pertenecen al edificio completo, no a un piso.
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/matpiso_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/matpiso_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$slug = $numeroPiso === 0 ? 'planta_baja' : ('piso_' . $numeroPiso);
$nombre = 'Materiales_' . $slug . '_' . date('Y-m-d') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

// Si wkhtmltopdf falla, mostrar el HTML como respaldo (imprimible).
@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo $html;
