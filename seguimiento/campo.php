<?php
/**
 * MODO CAMPO · captura rápida de seguimiento.
 *
 * Pantalla optimizada para el teléfono, pensada para que el técnico en
 * campo trabaje UN elemento a la vez (apartamento, local o área común):
 * ve la foto del antes, toma la del durante, mueve la barra de avance y
 * pasa al siguiente pendiente, con el mínimo de toques y scroll.
 *
 * NO reemplaza remodelacion.php (la ficha completa del supervisor). Es
 * una vista alterna del MISMO edificio, y reutiliza todo lo existente:
 *   - Datos:   arbol_avance.php  (recArbolAvance)
 *   - Fotos:   subir_foto_rec.php
 *   - Avance:  guardar_avance_ambiente.php / guardar_avance_area.php
 *   - Offline: obras-offline.js / obras-fotos.js
 *   - Compresión de foto en el teléfono (comprimirFoto, incluida aquí).
 *
 * El técnico elige primero el piso y avanza dentro de él. El recorrido
 * incluye apartamentos, locales y áreas comunes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

// El Modo campo es solo para los sistematizadores (los que cargan
// avance en la calle). Los directivos usan el rol admin y ven el
// panel de reportes, no esta pantalla de captura.
if (!esSistematizador()) {
    flash('error', 'El Modo campo es solo para sistematizadores.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$puedeCargar = true;

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) {
    flash('error', 'Edificio no especificado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$ed = recEdificio($inspeccionId);
$edificioId = (int)($ed['id'] ?? 0);
if ($edificioId <= 0) {
    flash('error', 'Este edificio todavía no tiene levantamiento.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$pageTitle    = 'Modo campo: ' . $insp['nombre_edificio'];
$pageSubtitle = trim(($insp['parroquia'] ?? '') . ' · ' . ($insp['municipio'] ?? ''), ' ·');
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.cm-wrap { max-width: 480px; margin: 0 auto; padding: 0 4px 90px; }
.cm-card { background: #fff; border: 1px solid #e5e8f0; border-radius: 14px; overflow: hidden; }
.cm-hidden { display: none !important; }

.cm-pisos { display: flex; gap: 8px; overflow-x: auto; padding: 4px 2px 10px; }
.cm-piso-btn { flex-shrink: 0; padding: 10px 16px; border-radius: 11px; border: 1px solid #d8dce6;
    background: #fff; font-size: 15px; font-weight: 600; color: #2a3140; cursor: pointer; }
.cm-piso-btn.activo { background: #22366F; color: #fff; border-color: #22366F; }
.cm-piso-btn .cm-mini { display: block; font-size: 11px; font-weight: 500; opacity: .8; margin-top: 2px; }

.cm-chips { display: flex; gap: 6px; overflow-x: auto; padding: 8px 10px; background: #f6f8fc;
    border-bottom: 1px solid #eef0f5; }
.cm-chip { flex-shrink: 0; font-size: 12px; padding: 4px 11px; border-radius: 20px; cursor: pointer;
    border: 1px solid #d8dce6; background: #fff; color: #5b6478; }
.cm-chip.ok { background: #E7F4EC; color: #2E7D32; border-color: #2E7D3233; }
.cm-chip.proceso { background: #FDF3E7; color: #A66A00; border-color: #A66A0033; }
.cm-chip.actual { background: #22366F; color: #fff; border-color: #22366F; font-weight: 600; }

.cm-fases { display: flex; gap: 8px; margin: 14px 0; }
.cm-fase { flex: 1; text-align: center; padding: 11px 4px; border-radius: 11px; background: #f4f6fa;
    color: #9aa1b4; border: 2px solid transparent; }
.cm-fase i { font-size: 22px; }
.cm-fase .cm-fase-txt { font-size: 11px; margin-top: 3px; font-weight: 600; }
.cm-fase.ok { background: #E7F4EC; color: #2E7D32; }
.cm-fase.activa { background: #E9EEF9; color: #22366F; border-color: #22366F; }

.cm-btn-cam { width: 100%; padding: 16px; border-radius: 13px; background: #22366F; color: #fff;
    border: 0; font-size: 17px; font-weight: 600; display: flex; align-items: center;
    justify-content: center; gap: 10px; cursor: pointer; }
.cm-btn-cam:disabled { opacity: .5; }
.cm-btn-cam.listo { background: #1D9E75; }

.cm-slider-wrap { margin-top: 16px; transition: opacity .3s; }
.cm-slider-wrap.bloq { opacity: .4; pointer-events: none; }
.cm-slider-row { display: flex; justify-content: space-between; font-size: 13px; color: #5b6478; margin-bottom: 8px; }
.cm-slider-row b { color: #2a3140; font-size: 15px; }
.cm-range { width: 100%; height: 30px; }

.cm-nav { display: flex; gap: 8px; margin-top: 16px; }
.cm-btn-sec { flex: 1; padding: 13px; border-radius: 11px; background: #fff; border: 1px solid #d8dce6;
    font-size: 14px; font-weight: 600; color: #2a3140; cursor: pointer; }
.cm-btn-prim { flex: 2; padding: 13px; border-radius: 11px; background: #22366F; color: #fff; border: 0;
    font-size: 15px; font-weight: 600; cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px; }

.cm-fotos-mini { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.cm-fotos-mini img { width: 62px; height: 62px; object-fit: cover; border-radius: 8px; border: 1px solid #d8dce6; }

.cm-vacio { text-align: center; padding: 40px 20px; color: #5b6478; }
.cm-vacio i { font-size: 46px; color: #1D9E75; }

.cm-barra-piso { height: 10px; background: #eef0f6; border-radius: 20px; overflow: hidden; margin: 4px 0 0; }
.cm-barra-piso > div { height: 100%; transition: width .3s; }

.cm-toast { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); background: #2a3140;
    color: #fff; padding: 11px 18px; border-radius: 10px; font-size: 14px; z-index: 4000; opacity: 0;
    transition: opacity .25s; pointer-events: none; }
.cm-toast.ver { opacity: 1; }
</style>

<div class="cm-wrap">

  <div style="display:flex;align-items:center;gap:10px;margin:6px 2px 12px;">
    <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $inspeccionId ?>"
       style="width:38px;height:38px;border-radius:10px;background:#fff;border:1px solid #d8dce6;
              display:flex;align-items:center;justify-content:center;color:#22366F;text-decoration:none;">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div style="flex:1;min-width:0;">
      <div style="font-size:16px;font-weight:700;color:#22366F;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= e($insp['nombre_edificio']) ?>
      </div>
      <div style="font-size:12px;color:#5b6478;">Modo campo · toque y avance</div>
    </div>
    <div id="cm-avance-gen" style="font-size:20px;font-weight:800;color:#22366F;">—</div>
  </div>

  <div id="cm-pisos" class="cm-pisos"></div>

  <div id="cm-cargando" style="text-align:center;padding:40px;color:#5b6478;">
    <i class="bi bi-arrow-repeat" style="font-size:28px;"></i>
    <div style="margin-top:8px;">Cargando el edificio…</div>
  </div>

  <div id="cm-panel" class="cm-hidden"></div>
</div>

<input type="file" id="cm-file-cam" accept="image/*" capture="environment" class="cm-hidden">
<input type="file" id="cm-file-gal" accept="image/*" class="cm-hidden">
<div id="cm-toast" class="cm-toast"></div>

<script>
const CM_INSP = <?= $inspeccionId ?>;
const CM_EDIF = <?= $edificioId ?>;
const CM_URL  = '<?= APP_URL_BASE ?>seguimiento/';
const CM_PUEDE = <?= $puedeCargar ? 'true' : 'false' ?>;
</script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-fotos.js"></script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-offline.js"></script>
<script src="<?= APP_URL_BASE ?>seguimiento/campo.js?v=1"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
