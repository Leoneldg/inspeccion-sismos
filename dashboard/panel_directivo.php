<?php
/**
 * PANEL DIRECTIVO.
 *
 * Vista rápida y amigable del avance de la obra, pensada para los
 * directivos (que entran por el rol administrador). Responde de un
 * vistazo, en orden de importancia:
 *   1) Avance general + las edificaciones que más faltan.
 *   2) Materiales y metros totales de la obra.
 *   3) Etapas: sin empezar / en trabajo / terminadas.
 *
 * Trae un botón para bajar el panel en PDF (pdf_panel_directivo.php).
 * Reutiliza panelDirectivoDatos() (includes/panel_directivo.php).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/panel_directivo.php';

requierePermiso('seguimiento', 'ver');

// Solo administradores y master ven el panel directivo.
$rolAdmin = str_contains(mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8'), 'administrador');
if (!usuarioEsMaster() && !$rolAdmin) {
    flash('error', 'El panel directivo es solo para administradores.');
    header('Location: ' . APP_URL_BASE . 'dashboard/index.php');
    exit;
}

$d = panelDirectivoDatos();
$activeModule = 'panel_directivo';
$pageTitle = 'Panel directivo';

$colorPct = function (int $p): string {
    if ($p >= 100) return '#1D9E75';
    if ($p > 0)    return '#EF9F27';
    return '#E24B4A';
};

include __DIR__ . '/../includes/header.php';
?>
<style>
.pd-wrap { max-width: 1100px; margin: 0 auto; padding: 4px 6px 40px; }
.pd-top { display: flex; align-items: center; gap: 12px; margin: 6px 2px 18px; flex-wrap: wrap; }
.pd-top h1 { font-size: 22px; font-weight: 700; color: #22366F; margin: 0; }
.pd-pdf { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; background: #A61C1C;
    color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; }
.pd-card { background: #fff; border: 1px solid #e5e8f0; border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.pd-h { font-size: 15px; font-weight: 700; color: #22366F; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }

.pd-gen { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
.pd-gen-num { font-size: 52px; font-weight: 800; line-height: 1; }
.pd-gen-barra { flex: 1; min-width: 200px; }
.pd-barra { height: 18px; background: #eef0f6; border-radius: 20px; overflow: hidden; }
.pd-barra > div { height: 100%; transition: width .4s; }

.pd-etapas { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.pd-etapa { border-radius: 11px; padding: 14px 16px; }
.pd-etapa .n { font-size: 30px; font-weight: 800; line-height: 1; }
.pd-etapa .l { font-size: 12.5px; margin-top: 4px; font-weight: 600; }

.pd-list { display: flex; flex-direction: column; gap: 8px; }
.pd-row { display: flex; align-items: center; gap: 12px; padding: 9px 12px; border: 1px solid #eef0f5; border-radius: 10px; }
.pd-row .nom { flex: 1; min-width: 0; }
.pd-row .nom b { font-size: 14px; color: #2a3140; }
.pd-row .nom span { font-size: 11.5px; color: #9aa1b4; display: block; }
.pd-row .mini { width: 120px; height: 9px; background: #eef0f6; border-radius: 20px; overflow: hidden; }
.pd-row .mini > div { height: 100%; }
.pd-row .pct { width: 44px; text-align: right; font-weight: 800; font-size: 15px; }

.pd-obra { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 14px; }
.pd-metric { background: #f6f8fc; border-radius: 11px; padding: 13px 15px; }
.pd-metric .l { font-size: 12px; color: #5b6478; }
.pd-metric .n { font-size: 24px; font-weight: 800; color: #22366F; margin-top: 2px; }
.pd-mat { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f4f6fa; font-size: 13.5px; }
.pd-mat b { color: #22366F; }
</style>

<div class="pd-wrap">

  <div class="pd-top">
    <h1><i class="bi bi-speedometer2"></i> Panel directivo</h1>
    <a class="pd-pdf" href="<?= APP_URL_BASE ?>dashboard/pdf_panel_directivo.php" target="_blank">
      <i class="bi bi-file-earmark-pdf"></i> Descargar en PDF
    </a>
  </div>

  <!-- 1) AVANCE GENERAL + LAS QUE MÁS FALTAN -->
  <div class="pd-card">
    <div class="pd-h"><i class="bi bi-graph-up-arrow"></i> Avance general de la obra</div>
    <div class="pd-gen">
      <div class="pd-gen-num" style="color:<?= $colorPct($d['general']['avance']) ?>;">
        <?= $d['general']['avance'] ?>%
      </div>
      <div class="pd-gen-barra">
        <div class="pd-barra">
          <div style="width:<?= $d['general']['avance'] ?>%;background:<?= $colorPct($d['general']['avance']) ?>;"></div>
        </div>
        <div style="font-size:12.5px;color:#5b6478;margin-top:6px;">
          Promedio de <?= $d['general']['con_obra'] ?> edificaciones con levantamiento cerrado.
        </div>
      </div>
    </div>
  </div>

  <div class="pd-card">
    <div class="pd-h"><i class="bi bi-exclamation-circle"></i> Las que más faltan</div>
    <div class="pd-list">
      <?php
      $masFaltan = array_slice($d['edificios'], 0, 8);
      if (!$masFaltan): ?>
        <div style="color:#5b6478;font-size:13px;">Todavía no hay edificaciones con levantamiento cerrado.</div>
      <?php else: foreach ($masFaltan as $e): $c = $colorPct($e['avance']); ?>
        <div class="pd-row">
          <div class="nom"><b><?= e($e['nombre']) ?></b><span><?= e($e['parroquia']) ?></span></div>
          <div class="mini"><div style="width:<?= $e['avance'] ?>%;background:<?= $c ?>;"></div></div>
          <div class="pct" style="color:<?= $c ?>;"><?= $e['avance'] ?>%</div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- 2) MATERIALES Y METROS TOTALES -->
  <div class="pd-card">
    <div class="pd-h"><i class="bi bi-box-seam"></i> Materiales y metros de la obra</div>
    <div class="pd-obra">
      <div class="pd-metric">
        <div class="l">Metros² a intervenir</div>
        <div class="n"><?= number_format($d['obra']['m2'], 0, ',', '.') ?></div>
      </div>
      <div class="pd-metric">
        <div class="l">Apartamentos</div>
        <div class="n"><?= number_format($d['obra']['apartamentos'], 0, ',', '.') ?></div>
      </div>
      <div class="pd-metric">
        <div class="l">Edificaciones</div>
        <div class="n"><?= $d['general']['total'] ?></div>
      </div>
    </div>
    <?php $tops = array_slice($d['obra']['materiales'], 0, 6); if ($tops): ?>
    <div style="font-size:12.5px;color:#5b6478;font-weight:600;margin-bottom:6px;">Principales materiales</div>
    <?php foreach ($tops as $m): ?>
      <div class="pd-mat"><span><?= e($m['material']) ?></span>
        <b><?= number_format($m['cantidad'], 0, ',', '.') ?> <?= e($m['unidad']) ?></b></div>
    <?php endforeach; endif; ?>
  </div>

  <!-- 3) ETAPAS -->
  <div class="pd-card">
    <div class="pd-h"><i class="bi bi-signpost-split"></i> En qué etapa van</div>
    <div class="pd-etapas">
      <div class="pd-etapa" style="background:#FCEBEB;">
        <div class="n" style="color:#A61C1C;"><?= $d['etapas']['sin_empezar'] ?></div>
        <div class="l" style="color:#A61C1C;">Sin empezar</div>
      </div>
      <div class="pd-etapa" style="background:#FDF3E7;">
        <div class="n" style="color:#A66A00;"><?= $d['etapas']['en_trabajo'] ?></div>
        <div class="l" style="color:#A66A00;">En trabajo</div>
      </div>
      <div class="pd-etapa" style="background:#E7F4EC;">
        <div class="n" style="color:#2E7D32;"><?= $d['etapas']['terminadas'] ?></div>
        <div class="l" style="color:#2E7D32;">Terminadas</div>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
