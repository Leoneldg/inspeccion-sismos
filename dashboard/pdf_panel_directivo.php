<?php
/**
 * PDF del PANEL DIRECTIVO.
 *
 * Mismo contenido que dashboard/panel_directivo.php, en una hoja para
 * imprimir o compartir. Usa wkhtmltopdf con respaldo a HTML si no está
 * disponible (mismo patrón que pdf_ejecutivo.php).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/panel_directivo.php';

requierePermiso('seguimiento', 'ver');

$rolAdmin = str_contains(mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8'), 'administrador');
if (!usuarioEsMaster() && !$rolAdmin) {
    http_response_code(403);
    exit('El panel directivo es solo para administradores.');
}

$d = panelDirectivoDatos();
$fecha = date('d/m/Y');

$colorPct = function (int $p): string {
    if ($p >= 100) return '#1D9E75';
    if ($p > 0)    return '#EF9F27';
    return '#E24B4A';
};

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Helvetica Neue', Arial, sans-serif; }
  body { color: #2a3140; padding: 20px 26px; }
  .cab { background: #22366F; color: #fff; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; }
  .cab h1 { font-size: 22px; font-weight: 800; }
  .cab .sub { font-size: 12px; opacity: .9; margin-top: 3px; }
  .card { border: 1px solid #e0e4ee; border-radius: 10px; padding: 15px 18px; margin-bottom: 14px; }
  .h { font-size: 14px; font-weight: 800; color: #22366F; margin-bottom: 11px; }

  .gen { display: table; width: 100%; }
  .gen .num { display: table-cell; font-size: 46px; font-weight: 800; width: 130px; vertical-align: middle; }
  .gen .bar-wrap { display: table-cell; vertical-align: middle; padding-left: 18px; }
  .barra { height: 16px; background: #eef0f6; border-radius: 20px; overflow: hidden; }
  .barra > div { height: 100%; }
  .gen .nota { font-size: 11px; color: #5b6478; margin-top: 6px; }

  .etapas { display: table; width: 100%; border-spacing: 10px 0; }
  .etapa { display: table-cell; width: 33%; border-radius: 9px; padding: 12px 14px; }
  .etapa .n { font-size: 26px; font-weight: 800; }
  .etapa .l { font-size: 11.5px; font-weight: 700; margin-top: 3px; }

  table.list { width: 100%; border-collapse: collapse; }
  table.list td { font-size: 12.5px; padding: 6px 4px; border-bottom: 1px solid #f2f4f8; }
  .barmini { height: 8px; background: #eef0f6; border-radius: 20px; overflow: hidden; width: 130px; }
  .barmini > div { height: 100%; }
  .pct { font-weight: 800; text-align: right; }

  .obra { display: table; width: 100%; border-spacing: 10px 0; margin-bottom: 12px; }
  .metric { display: table-cell; background: #f6f8fc; border-radius: 9px; padding: 11px 14px; }
  .metric .l { font-size: 11px; color: #5b6478; }
  .metric .n { font-size: 22px; font-weight: 800; color: #22366F; }
  .mat { display: table; width: 100%; font-size: 12.5px; padding: 5px 0; border-bottom: 1px solid #f4f6fa; }
  .mat .a { display: table-cell; }
  .mat .b { display: table-cell; text-align: right; font-weight: 700; color: #22366F; }

  .pie { text-align: center; font-size: 10px; color: #9aa1b4; margin-top: 16px; }
</style>
</head><body>

  <div class="cab">
    <h1>Panel directivo</h1>
    <div class="sub">Avance de la obra · <?= $fecha ?></div>
  </div>

  <div class="card">
    <div class="h">Avance general de la obra</div>
    <div class="gen">
      <div class="num" style="color:<?= $colorPct($d['general']['avance']) ?>;"><?= $d['general']['avance'] ?>%</div>
      <div class="bar-wrap">
        <div class="barra"><div style="width:<?= $d['general']['avance'] ?>%;background:<?= $colorPct($d['general']['avance']) ?>;"></div></div>
        <div class="nota">Promedio de <?= $d['general']['con_obra'] ?> edificaciones con levantamiento cerrado.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="h">Las que más faltan</div>
    <table class="list">
      <?php foreach (array_slice($d['edificios'], 0, 10) as $e): $c = $colorPct($e['avance']); ?>
      <tr>
        <td><b><?= e($e['nombre']) ?></b><br><span style="font-size:10.5px;color:#9aa1b4;"><?= e($e['parroquia']) ?></span></td>
        <td style="width:140px;"><div class="barmini"><div style="width:<?= $e['avance'] ?>%;background:<?= $c ?>;"></div></div></td>
        <td class="pct" style="color:<?= $c ?>;width:46px;"><?= $e['avance'] ?>%</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <div class="h">Materiales y metros de la obra</div>
    <div class="obra">
      <div class="metric"><div class="l">Metros² a intervenir</div><div class="n"><?= number_format($d['obra']['m2'], 0, ',', '.') ?></div></div>
      <div class="metric"><div class="l">Apartamentos</div><div class="n"><?= number_format($d['obra']['apartamentos'], 0, ',', '.') ?></div></div>
      <div class="metric"><div class="l">Edificaciones</div><div class="n"><?= $d['general']['total'] ?></div></div>
    </div>
    <?php foreach (array_slice($d['obra']['materiales'], 0, 8) as $m): ?>
    <div class="mat"><span class="a"><?= e($m['material']) ?></span>
      <span class="b"><?= number_format($m['cantidad'], 0, ',', '.') ?> <?= e($m['unidad']) ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="h">En qué etapa van</div>
    <div class="etapas">
      <div class="etapa" style="background:#FCEBEB;"><div class="n" style="color:#A61C1C;"><?= $d['etapas']['sin_empezar'] ?></div><div class="l" style="color:#A61C1C;">Sin empezar</div></div>
      <div class="etapa" style="background:#FDF3E7;"><div class="n" style="color:#A66A00;"><?= $d['etapas']['en_trabajo'] ?></div><div class="l" style="color:#A66A00;">En trabajo</div></div>
      <div class="etapa" style="background:#E7F4EC;"><div class="n" style="color:#2E7D32;"><?= $d['etapas']['terminadas'] ?></div><div class="l" style="color:#2E7D32;">Terminadas</div></div>
    </div>
  </div>

  <div class="pie">Gestión de Obras Avanzadas · <?= $fecha ?> · Panel directivo</div>

</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/pd_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/pd_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 8mm --margin-bottom 8mm --margin-left 6mm --margin-right 6mm '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $outLines, $code);

$nombre = 'Panel_directivo_' . date('Y-m-d') . '.pdf';

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
