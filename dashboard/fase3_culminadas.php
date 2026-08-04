<?php
/**
 * FASE 3 · CULMINADAS.
 *
 * Los edificios ya terminados: el logro de la gestión, lo que se muestra
 * al pueblo y a la presidencia. Familias que volvieron a su hogar.
 *
 * Reutiliza panelDirectivoDatos() (avance por edificio + estado) y
 * filtra las que están al 100%. No recalcula nada por su cuenta.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/panel_directivo.php';

requierePermiso('seguimiento', 'ver');

$datos = panelDirectivoDatos();
$culminadas = array_values(array_filter($datos['edificios'], fn($e) => $e['estado'] === 'terminada'));
$totalObra  = (int)$datos['general']['total'];
$nCulm      = count($culminadas);
$pctCulm    = $totalObra > 0 ? round($nCulm * 100 / $totalObra) : 0;

$activeModule = 'fase3';
$pageTitle = 'Fase 3 · Culminadas';

include __DIR__ . '/../includes/header.php';
?>
<style>
.f3-wrap { max-width: 1140px; margin: 0 auto; padding: 4px 6px 40px; }
.f3-top { display: flex; align-items: center; gap: 12px; margin: 6px 2px 18px; }
.f3-top h1 { font-size: 23px; font-weight: 700; color: #1D6E56; margin: 0; }
.f3-hero { background: linear-gradient(135deg, #1D6E56, #2E7D32); color: #fff; border-radius: 16px; padding: 26px 28px; margin-bottom: 18px; }
.f3-hero .big { font-size: 52px; font-weight: 800; line-height: 1; }
.f3-hero .lbl { font-size: 15px; opacity: .95; margin-top: 6px; }
.f3-hero .sub { font-size: 13px; opacity: .85; margin-top: 10px; }
.f3-card { background: #fff; border: 1px solid #e5e8f0; border-radius: 14px; padding: 18px 20px; }
.f3-h { font-size: 14px; font-weight: 700; color: #1D6E56; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
.f3-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.f3-item { border: 1px solid #e5e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
.f3-check { width: 40px; height: 40px; border-radius: 50%; background: #E7F4EC; color: #2E7D32; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.f3-vacio { text-align: center; padding: 40px 20px; color: #5b6478; }
</style>

<div class="f3-wrap">

  <div class="f3-top">
    <div style="width:40px;height:40px;border-radius:11px;background:#1D6E56;display:flex;align-items:center;justify-content:center;color:#fff;">
      <i class="bi bi-3-circle-fill" style="font-size:20px;"></i>
    </div>
    <div>
      <h1>Fase 3 · Culminadas</h1>
      <div style="font-size:13px;color:#5b6478;">Edificaciones entregadas · el logro de la gestión</div>
    </div>
  </div>

  <div class="f3-hero">
    <div class="big"><?= number_format($nCulm, 0, ',', '.') ?></div>
    <div class="lbl">edificaciones reconstruidas y entregadas</div>
    <div class="sub"><?= $pctCulm ?>% del total en obra · familias de vuelta en su hogar</div>
  </div>

  <div class="f3-card">
    <div class="f3-h"><i class="bi bi-check2-circle"></i> Edificaciones entregadas</div>
    <?php if (!$nCulm): ?>
      <div class="f3-vacio">
        <i class="bi bi-hourglass-split" style="font-size:40px;color:#c4c9d6;"></i>
        <div style="margin-top:10px;font-weight:600;">Aún no hay obras culminadas al 100%.</div>
        <div style="margin-top:4px;font-size:13px;">Las edificaciones aparecerán aquí a medida que se completen en la Fase 2.</div>
      </div>
    <?php else: ?>
      <div class="f3-list">
        <?php foreach ($culminadas as $e): ?>
        <div class="f3-item">
          <div class="f3-check"><i class="bi bi-check-lg"></i></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:14.5px;font-weight:700;color:#2a3140;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <?= e($e['nombre']) ?>
            </div>
            <div style="font-size:12px;color:#5b6478;"><?= e($e['parroquia']) ?></div>
          </div>
          <div style="font-size:15px;font-weight:800;color:#2E7D32;">100%</div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
