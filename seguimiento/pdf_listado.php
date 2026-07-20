<?php
/**
 * LISTADO DE EDIFICACIONES EN PDF.
 *
 * Agrupado por parroquia y, dentro de cada una, por color de decisión.
 * Recibe los mismos filtros del buscador del dashboard.
 *
 * Uso: pdf_listado.php?parroquia=&uso=Vivienda&color=&ente_id=&q=
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// --- Filtros, los mismos del buscador ---
$q         = trim($_GET['q'] ?? '');
$parroquia = trim($_GET['parroquia'] ?? '');
$estadoF   = trim($_GET['estado'] ?? '');
$enteId    = trim($_GET['ente_id'] ?? '');
$colorF    = trim($_GET['color'] ?? '');
$uso       = trim($_GET['uso'] ?? '');
$fase      = trim($_GET['fase'] ?? 'todas');

$conds = [];
$params = [];
aplicarScopeEstado($conds, $params, 'i');
aplicarScopeParroquia($conds, $params, 'i');

if ($fase === 'obra')  $conds[] = 're.completado = 1';
if ($q !== '') {
    $conds[] = '(i.nombre_edificio LIKE :q OR i.codigo LIKE :q2)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}
if ($parroquia !== '') { $conds[] = 'i.parroquia = :parr';  $params['parr'] = $parroquia; }
if ($estadoF !== '')   { $conds[] = 'i.estado = :est';      $params['est']  = $estadoF; }
if ($uso !== '')       { $conds[] = 'i.uso_edificacion = :uso'; $params['uso'] = $uso; }
// El filtro envía 'verde', 'rojo'…: se traduce a la decisión completa.
$mapaColor = [
    'verde'      => 'Edificación Inspeccionada - Acceso Permitido',
    'amarillo'   => 'Acceso Restringido - Precaución al Entrar',
    'rojo'       => 'Edificación Insegura - Acceso No Permitido',
    'derrumbado' => 'Derrumbado',
];
if ($colorF !== '' && isset($mapaColor[$colorF])) {
    $conds[] = 'i.decision_final = :dec';
    $params['dec'] = $mapaColor[$colorF];
}
if ($enteId !== '')    { $conds[] = 'so.ente_id = :ente';   $params['ente'] = (int)$enteId; }

$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

try {
    $st = db()->prepare("
        SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.municipio,
               i.decision_final, i.uso_edificacion, i.num_pisos,
               i.familias, i.numero_personas,
               TRIM(CONCAT_WS(', ', NULLIF(i.avenida_calle,''), NULLIF(i.sector,''),
                              NULLIF(i.urbanizacion,''))) AS direccion,
               ent.nombre AS ente_nombre,
               re.completado,
               fr.numero AS frente_numero, fr.nombre AS frente_nombre
          FROM inspecciones i
          LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
          LEFT JOIN seguimiento_obras so ON so.inspeccion_id = i.id
          LEFT JOIN entes ent ON ent.id = so.ente_id
          LEFT JOIN asignacion_frente_obra afo ON afo.inspeccion_id = i.id
          LEFT JOIN frente fr ON fr.id = afo.frente_id
          $where
         ORDER BY i.parroquia, i.nombre_edificio
    ");
    $st->execute($params);
    $filas = $st->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo generar el listado: ' . (APP_DEBUG ? $e->getMessage() : 'error de consulta'));
}

$cat = catalogoDecisionFinal();

// Orden de los colores: primero lo más grave.
$ORDEN = [
    'Edificación Insegura - Acceso No Permitido'      => ['ROJO', '#A61C1C', 'Acceso no permitido'],
    'Acceso Restringido - Precaución al Entrar'       => ['AMARILLO', '#C9A227', 'Acceso restringido'],
    'Edificación Inspeccionada - Acceso Permitido'    => ['VERDE', '#2E7D32', 'Acceso permitido'],
    'Derrumbado'                                      => ['DERRUMBADO', '#2B2B2B', ''],
];

// Agrupar por parroquia y color.
$porParroquia = [];
$totalColor = [];
foreach ($filas as $f) {
    $parr = $f['parroquia'] ?: 'Sin parroquia';
    $dec  = $f['decision_final'] ?: '';
    $porParroquia[$parr][$dec][] = $f;
    $totalColor[$dec] = ($totalColor[$dec] ?? 0) + 1;
}
ksort($porParroquia);

// Texto de los filtros aplicados.
$textoFiltros = [];
if ($q !== '')         $textoFiltros[] = 'Búsqueda: ' . $q;
$textoFiltros[] = $parroquia !== '' ? ('Parroquia: ' . $parroquia) : 'Todas las parroquias';
if ($uso !== '')       $textoFiltros[] = 'Uso: ' . $uso;
if ($colorF !== '' && isset($mapaColor[$colorF])) {
    $textoFiltros[] = 'Decisión: ' . mb_strtoupper($colorF, 'UTF-8');
}
if ($enteId !== '') {
    try {
        $stE = db()->prepare('SELECT nombre FROM entes WHERE id = :id');
        $stE->execute(['id' => (int)$enteId]);
        $nomE = $stE->fetchColumn();
        if ($nomE) $textoFiltros[] = 'Ente: ' . $nomE;
    } catch (Throwable $e) {}
}
if ($fase === 'obra')  $textoFiltros[] = 'Solo en reconstrucción';

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 11px; }
  .hoja { padding: 22px 26px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 11px; margin-bottom: 14px; }
  .cab h1 { margin: 0; font-size: 24px; color: #22366F; font-weight: 800; }
  .cab .fecha { float: right; text-align: right; color: #55617f;
                font-size: 11px; font-weight: 600; }
  .filtros { background: #eef2fb; border-radius: 7px; padding: 8px 12px;
             font-size: 10.5px; color: #22366F; margin-top: 8px; }

  .resumen { display: table; width: 100%; border-spacing: 5px 0; margin-bottom: 16px; }
  .resumen .c { display: table-cell; text-align: center; padding: 9px 4px;
                border-radius: 7px; border: 1px solid #e0e4ee; }
  .resumen .n { font-size: 19px; font-weight: 800; }
  .resumen .l { font-size: 8.5px; text-transform: uppercase; color: #444; }

  .parroquia { page-break-inside: avoid; margin-bottom: 18px; }
  .parr-cab { background: #22366F; color: #fff; padding: 8px 13px;
              border-radius: 7px 7px 0 0; font-size: 13.5px; font-weight: 800; }
  .parr-cab .n { float: right; font-size: 11px; font-weight: 600; opacity: .9; }
  .mini-res { padding: 7px 13px; background: #f7f9fd; font-size: 10px; }
  .chip { display: inline-block; padding: 2px 9px; border-radius: 10px;
          font-weight: 700; margin-right: 5px; }

  .color-cab { color: #fff; padding: 5px 12px; font-size: 11px;
               font-weight: 700; margin-top: 7px; }
  table { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  th { background: #f1f3f8; color: #22366F; font-size: 9px; padding: 5px 6px;
       text-align: left; text-transform: uppercase; border-bottom: 1px solid #dde2ec; }
  td { font-size: 10px; padding: 5px 6px; border-bottom: 1px solid #eef0f5; }
  tr:nth-child(even) td { background: #fafbfe; }

  .pie { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 8.5px; color: #767c94; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="fecha"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Listado de edificaciones</h1>
    <div class="filtros">
      <strong>Filtros:</strong> <?= esc(implode('  ·  ', $textoFiltros)) ?>
    </div>
  </div>

  <?php if (!$filas): ?>
    <p style="color:#767c94;padding:30px;text-align:center;">
      No hay edificaciones que coincidan con estos filtros.
    </p>
  <?php else: ?>

  <!-- Resumen general -->
  <div class="resumen">
    <div class="c" style="border-color:#22366F33;">
      <div class="n" style="color:#22366F;"><?= count($filas) ?></div>
      <div class="l">Total</div>
    </div>
    <div class="c" style="border-color:#2d448833;">
      <div class="n" style="color:#2d4488;"><?= count($porParroquia) ?></div>
      <div class="l">Parroquias</div>
    </div>
    <?php foreach ($ORDEN as $dec => $meta): ?>
    <div class="c" style="border-color:<?= $meta[1] ?>33;">
      <div class="n" style="color:<?= $meta[1] ?>;"><?= (int)($totalColor[$dec] ?? 0) ?></div>
      <div class="l"><?= $meta[0] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Una sección por parroquia -->
  <?php foreach ($porParroquia as $parr => $grupos):
      $totalParr = array_sum(array_map('count', $grupos)); ?>
  <div class="parroquia">
    <div class="parr-cab">
      <?= esc(mb_strtoupper($parr, 'UTF-8')) ?>
      <span class="n"><?= $totalParr ?> edificación<?= $totalParr === 1 ? '' : 'es' ?></span>
    </div>

    <div class="mini-res">
      <?php foreach ($ORDEN as $dec => $meta):
          $n = count($grupos[$dec] ?? []);
          if (!$n) continue; ?>
      <span class="chip" style="background:<?= $meta[1] ?>18;color:<?= $meta[1] ?>;">
        <?= $n ?> <?= $meta[0] ?>
      </span>
      <?php endforeach; ?>
    </div>

    <?php foreach ($ORDEN as $dec => $meta):
        $lista = $grupos[$dec] ?? [];
        if (!$lista) continue; ?>
      <div class="color-cab" style="background:<?= $meta[1] ?>;">
        <?= $meta[0] ?><?= $meta[2] ? ' · ' . $meta[2] : '' ?>
        <span style="float:right;"><?= count($lista) ?></span>
      </div>
      <table>
        <thead><tr>
          <th style="width:22px;">#</th>
          <th>Edificación</th>
          <th style="width:96px;">Código</th>
          <th style="width:86px;">Uso</th>
          <th style="width:108px;">Ente</th>
          <th style="width:74px;">Frente</th>
          <th style="width:44px;">Fam.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista as $k => $f): ?>
          <tr>
            <td style="text-align:center;color:#767c94;"><?= $k + 1 ?></td>
            <td>
              <strong><?= esc($f['nombre_edificio'] ?: 'Sin nombre') ?></strong>
              <?php if (!empty($f['direccion'])): ?>
              <div style="font-size:8.5px;color:#767c94;">
                <?= esc(mb_strimwidth($f['direccion'], 0, 46, '…', 'UTF-8')) ?>
              </div>
              <?php endif; ?>
            </td>
            <td style="color:#55617f;"><?= esc($f['codigo']) ?></td>
            <td style="font-size:9px;"><?= esc($f['uso_edificacion'] ?: '—') ?></td>
            <td style="font-size:9px;"><?= esc($f['ente_nombre'] ?: 'Sin asignar') ?></td>
            <td style="font-size:9px;">
              <?= !empty($f['frente_numero']) ? 'Frente ' . (int)$f['frente_numero'] : '—' ?>
            </td>
            <td style="text-align:center;"><?= (int)$f['familias'] ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>

    <?php // Las que no tienen decisión registrada
    foreach ($grupos as $dec => $lista):
        if (isset($ORDEN[$dec])) continue; ?>
      <div class="color-cab" style="background:#767c94;">
        SIN CLASIFICAR <span style="float:right;"><?= count($lista) ?></span>
      </div>
      <table><tbody>
      <?php foreach ($lista as $k => $f): ?>
        <tr>
          <td style="width:22px;text-align:center;color:#767c94;"><?= $k + 1 ?></td>
          <td><strong><?= esc($f['nombre_edificio'] ?: 'Sin nombre') ?></strong></td>
          <td style="width:96px;color:#55617f;"><?= esc($f['codigo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Generado el <?= $fecha ?> a las <?= $hora ?>
    por <?= esc($quien) ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/lst_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/lst_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 9mm --margin-bottom 9mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Listado_' . ($parroquia !== '' ? preg_replace('/[^A-Za-z0-9]/', '_', $parroquia) . '_' : '')
        . date('Y-m-d') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

// Si wkhtmltopdf no está disponible, mostrar el HTML.
@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo $html;
