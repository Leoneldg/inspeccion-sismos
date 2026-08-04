<?php
/**
 * FASE 1 · INSPECCIONES.
 *
 * El estado de los inmuebles inspeccionados: el semáforo global y el
 * desglose por parroquia (verde/amarillo/rojo + personas). Es la foto de
 * la magnitud del daño, la base sobre la que se decide a quién atender
 * primero. Aquí vive la data que se está migrando.
 *
 * Reutiliza dashResumenGeneral() y dashPorParroquia() existentes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../seguimiento/dashboard_gubernamental.php';

requierePermiso('seguimiento', 'ver');

$resumen = dashResumenGeneral();
$parroquias = dashPorParroquia();

$activeModule = 'fase1';
$pageTitle = 'Fase 1 · Inspecciones';

$totInmuebles = (int)$resumen['total_edificaciones'];
$v = (int)$resumen['verde']; $a = (int)$resumen['amarillo']; $r = (int)$resumen['rojo'];
$totSem = max(1, $v + $a + $r);

include __DIR__ . '/../includes/header.php';
?>
<style>
.f1-wrap { max-width: 1140px; margin: 0 auto; padding: 4px 6px 40px; }
.f1-top { display: flex; align-items: center; gap: 12px; margin: 6px 2px 18px; }
.f1-top h1 { font-size: 23px; font-weight: 700; color: #22366F; margin: 0; }
.f1-card { background: #fff; border: 1px solid #e5e8f0; border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.f1-h { font-size: 14px; font-weight: 700; color: #22366F; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }

.f1-sem { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 4px; }
.f1-s { border-radius: 12px; padding: 16px; text-align: center; }
.f1-s .n { font-size: 34px; font-weight: 800; line-height: 1; }
.f1-s .l { font-size: 13px; font-weight: 600; margin-top: 5px; }
.f1-s .p { font-size: 12px; margin-top: 2px; opacity: .85; }

.f1-tabla { width: 100%; border-collapse: collapse; }
.f1-tabla th { text-align: left; font-size: 11.5px; color: #9aa1b4; font-weight: 600; padding: 8px; text-transform: uppercase; letter-spacing: .3px; }
.f1-tabla td { font-size: 13.5px; padding: 9px 8px; border-top: 1px solid #f2f4f8; }
.f1-bar { display: flex; height: 20px; border-radius: 6px; overflow: hidden; min-width: 130px; }
.f1-bar > div { height: 100%; }
.f1-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; }
.f1-buscar { width: 100%; max-width: 320px; padding: 9px 13px; border: 1px solid #d8dce6; border-radius: 9px; font-size: 14px; margin-bottom: 12px; }
</style>

<div class="f1-wrap">

  <div class="f1-top">
    <div style="width:40px;height:40px;border-radius:11px;background:#2E7D32;display:flex;align-items:center;justify-content:center;color:#fff;">
      <i class="bi bi-1-circle-fill" style="font-size:20px;"></i>
    </div>
    <div>
      <h1>Fase 1 · Inspecciones</h1>
      <div style="font-size:13px;color:#5b6478;"><?= number_format($totInmuebles,0,',','.') ?> inmuebles inspeccionados · semáforo por parroquia</div>
    </div>
  </div>

  <!-- PESTAÑAS: unifican la herramienta de la fase -->
  <div class="fase-tabs" style="display:flex;gap:4px;border-bottom:1px solid #e6e9f0;margin:16px 0;flex-wrap:wrap;">
    <button class="fase-tab on" data-tab="resumen" onclick="faseTab('resumen',this)">Resumen</button>
    <button class="fase-tab" data-tab="edificaciones" onclick="faseTab('edificaciones',this)">Edificaciones</button>
  </div>
  <style>
    .fase-tab { padding:9px 15px; font-size:13px; color:#5b6478; background:none; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; font-weight:500; }
    .fase-tab.on { color:#22366F; border-bottom-color:#22366F; }
    .fase-tab:hover { color:#22366F; }
    .fase-panel { display:none; }
    .fase-panel.on { display:block; }
    .fase-frame { width:100%; height:calc(100vh - 220px); min-height:520px; border:1px solid #e6e9f0; border-radius:11px; }
  </style>

  <div class="fase-panel on" id="panel-resumen">

  <!-- SEMÁFORO GLOBAL -->
  <div class="f1-card">
    <div class="f1-h"><i class="bi bi-stoplights"></i> Semáforo general de habitabilidad</div>
    <div class="f1-sem">
      <div class="f1-s" style="background:#E7F4EC;">
        <div class="n" style="color:#2E7D32;"><?= number_format($v,0,',','.') ?></div>
        <div class="l" style="color:#2E7D32;">Habitables</div>
        <div class="p" style="color:#2E7D32;"><?= round($v*100/$totSem) ?>% · <?= number_format((int)$resumen['personas_verde'],0,',','.') ?> personas</div>
      </div>
      <div class="f1-s" style="background:#FDF7E7;">
        <div class="n" style="color:#A66A00;"><?= number_format($a,0,',','.') ?></div>
        <div class="l" style="color:#A66A00;">Precaución</div>
        <div class="p" style="color:#A66A00;"><?= round($a*100/$totSem) ?>% · <?= number_format((int)$resumen['personas_amarillo'],0,',','.') ?> personas</div>
      </div>
      <div class="f1-s" style="background:#FCEBEB;">
        <div class="n" style="color:#A61C1C;"><?= number_format($r,0,',','.') ?></div>
        <div class="l" style="color:#A61C1C;">Inseguras</div>
        <div class="p" style="color:#A61C1C;"><?= round($r*100/$totSem) ?>% · <?= number_format((int)$resumen['personas_rojo'],0,',','.') ?> personas</div>
      </div>
    </div>
  </div>

  <!-- DESGLOSE POR PARROQUIA -->
  <div class="f1-card">
    <div class="f1-h"><i class="bi bi-geo-alt"></i> Desglose por parroquia</div>
    <input type="text" id="f1-buscar" class="f1-buscar" placeholder="Buscar parroquia…" onkeyup="f1Filtrar(this.value)">
    <table class="f1-tabla">
      <thead><tr><th>Parroquia</th><th>Total</th><th>Distribución</th><th>Verde</th><th>Amar.</th><th>Rojo</th><th>Personas</th></tr></thead>
      <tbody id="f1-cuerpo">
        <?php foreach ($parroquias as $p):
          $t = max(1, (int)$p['total']);
          $pv = (int)$p['verde']; $pa = (int)$p['amarillo']; $pr = (int)$p['rojo']; $ps = (int)$p['sin'];
        ?>
        <tr class="f1-fila" data-parr="<?= e(mb_strtolower($p['parroquia'])) ?>">
          <td style="font-weight:600;"><?= e($p['parroquia']) ?></td>
          <td><?= number_format((int)$p['total'],0,',','.') ?></td>
          <td>
            <div class="f1-bar">
              <?php if ($pv): ?><div style="width:<?= $pv*100/$t ?>%;background:#2E7D32;"></div><?php endif; ?>
              <?php if ($pa): ?><div style="width:<?= $pa*100/$t ?>%;background:#C9A227;"></div><?php endif; ?>
              <?php if ($pr): ?><div style="width:<?= $pr*100/$t ?>%;background:#A61C1C;"></div><?php endif; ?>
              <?php if ($ps): ?><div style="width:<?= $ps*100/$t ?>%;background:#c4c9d6;"></div><?php endif; ?>
            </div>
          </td>
          <td><span class="f1-chip" style="color:#2E7D32;"><?= $pv ?></span></td>
          <td><span class="f1-chip" style="color:#A66A00;"><?= $pa ?></span></td>
          <td><span class="f1-chip" style="color:#A61C1C;"><?= $pr ?></span></td>
          <td><?= number_format((int)$p['personas'],0,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  </div><!-- /panel-resumen -->

  <!-- PANEL: Edificaciones (herramienta integrada) -->
  <div class="fase-panel" id="panel-edificaciones">
    <iframe class="fase-frame" data-src="<?= APP_URL_BASE ?>seguimiento/index.php?embed=1" title="Edificaciones"></iframe>
  </div>

</div>

<script>
function faseTab(tab, btn) {
    document.querySelectorAll('.fase-tab').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    document.querySelectorAll('.fase-panel').forEach(p => p.classList.remove('on'));
    var panel = document.getElementById('panel-' + tab);
    if (panel) {
        panel.classList.add('on');
        var fr = panel.querySelector('iframe.fase-frame');
        if (fr && !fr.src && fr.dataset.src) fr.src = fr.dataset.src;
    }
}
</script>

<script>
function f1Filtrar(q) {
    q = (q || '').toLowerCase().trim();
    document.querySelectorAll('.f1-fila').forEach(function (fila) {
        var parr = fila.getAttribute('data-parr') || '';
        fila.style.display = (!q || parr.indexOf(q) !== -1) ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
