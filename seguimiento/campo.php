<?php
/**
 * MODO CAMPO · fase de intervención.
 *
 * Segunda fase del edificio. El levantamiento técnico ya dejó el plan
 * (qué ambientes reparar y qué partidas tiene cada uno); aquí se registra
 * la ejecución de ese plan.
 *
 * La pantalla son dos vistas:
 *
 *   RESULTADOS  Solo lectura. Indicadores, avance por piso y la tira
 *               antes / durante / después de cada espacio. La abre
 *               cualquiera que pueda ver seguimiento, incluidos los
 *               directivos: es la vista de consulta del edificio.
 *
 *   REPORTAR    Captura. Solo el sistematizador. Se baja piso →
 *               apartamento → ambiente → partidas, y en cada partida se
 *               suben las fotos del durante y del después.
 *
 * El porcentaje no se escribe en ninguna parte: sale de las partidas
 * reportadas, ponderadas por metros cuadrados (ver includes/intervencion.php).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/intervencion.php';

requierePermiso('seguimiento', 'ver');

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
    flash('error', 'Este edificio todavía no tiene levantamiento técnico. '
                 . 'La intervención se reporta sobre el plan que deja el levantamiento.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/remodelacion.php?inspeccion=' . $inspeccionId);
    exit;
}

// Reportar es del sistematizador; ver resultados, de cualquiera.
$puedeReportar = esSistematizador();

$pageTitle    = 'Intervención: ' . $insp['nombre_edificio'];
$pageSubtitle = trim(($insp['parroquia'] ?? '') . ' · ' . ($insp['municipio'] ?? ''), ' ·');
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.iv-wrap { max-width: 560px; margin: 0 auto; padding: 0 4px 90px; }
.iv-card { background:#fff; border:1px solid #e5e8f0; border-radius:14px; overflow:hidden; margin-bottom:12px; }
.iv-oculto { display:none !important; }

/* --- Cabecera --- */
.iv-top { display:flex; align-items:center; gap:10px; margin:6px 2px 12px; }
.iv-volver { width:38px; height:38px; border-radius:10px; background:#fff; border:1px solid #d8dce6;
    display:flex; align-items:center; justify-content:center; color:#22366F; text-decoration:none; flex-shrink:0; }
.iv-titulo { font-size:16px; font-weight:700; color:#22366F; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* --- Pestañas --- */
.iv-tabs { display:flex; gap:6px; margin-bottom:14px; }
.iv-tab { flex:1; padding:11px 8px; border-radius:11px; border:1px solid #d8dce6; background:#fff;
    font-size:14px; font-weight:600; color:#5b6478; cursor:pointer; display:flex;
    align-items:center; justify-content:center; gap:7px; }
.iv-tab.activa { background:#22366F; border-color:#22366F; color:#fff; }

/* --- Barras --- */
.iv-barra { height:10px; background:#eef0f6; border-radius:20px; overflow:hidden; }
.iv-barra > div { height:100%; transition:width .35s; }
.iv-barra-alta { height:22px; border-radius:12px; }
.iv-barra-alta > div { color:#fff; font-size:12px; line-height:22px; text-align:right; padding-right:9px; font-weight:700; }

/* --- Indicadores --- */
.iv-kpis { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.iv-kpi { background:#f6f8fc; border-radius:11px; padding:10px 8px; text-align:center; }
.iv-kpi .n { font-size:20px; font-weight:800; line-height:1.1; }
.iv-kpi .t { font-size:11px; color:#5b6478; margin-top:3px; }

/* --- Filas de navegación --- */
.iv-fila { display:flex; align-items:center; gap:11px; padding:13px 15px; border-bottom:1px solid #eef0f5;
    cursor:pointer; background:#fff; }
.iv-fila:last-child { border-bottom:0; }
.iv-fila:active { background:#f4f7fd; }
.iv-fila-info { flex:1; min-width:0; }
.iv-fila-tit { font-size:14.5px; font-weight:600; color:#2a3140; }
.iv-fila-sub { font-size:11.5px; color:#767c94; margin-top:2px; }
.iv-pct { font-size:15px; font-weight:800; min-width:44px; text-align:right; }

/* --- Chips de piso --- */
.iv-pisos { display:flex; gap:8px; overflow-x:auto; padding:2px 2px 12px; }
.iv-piso-btn { flex-shrink:0; padding:9px 15px; border-radius:11px; border:1px solid #d8dce6;
    background:#fff; font-size:14px; font-weight:600; color:#2a3140; cursor:pointer; text-align:center; }
.iv-piso-btn.activo { background:#22366F; color:#fff; border-color:#22366F; }
.iv-piso-btn .mini { display:block; font-size:11px; font-weight:500; opacity:.8; margin-top:2px; }

/* --- Migas --- */
.iv-migas { font-size:12px; color:#5b6478; padding:10px 15px; background:#f6f8fc;
    border-bottom:1px solid #eef0f5; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.iv-migas b { color:#22366F; }

/* --- Partidas --- */
.iv-partida { padding:14px 15px; border-bottom:1px solid #eef0f5; }
.iv-partida:last-child { border-bottom:0; }
.iv-partida-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.iv-partida-nom { font-size:14.5px; font-weight:700; color:#2a3140; }
.iv-partida-sub { font-size:11.5px; color:#767c94; margin-top:2px; }
.iv-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.iv-badge.sin { background:#f1f2f6; color:#6b7285; }
.iv-badge.proc { background:#FDF3E7; color:#A66A00; }
.iv-badge.fin { background:#E7F4EC; color:#2E7D32; }

/* --- Tira de fases --- */
.iv-fases { display:grid; grid-template-columns:repeat(3,1fr); gap:7px; margin-top:11px; }
.iv-fase { border:1px solid #e5e8f0; border-radius:10px; overflow:hidden; background:#fafbfe; }
.iv-fase-cab { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    padding:5px 7px; text-align:center; }
.iv-fase.antes   .iv-fase-cab { background:#FBEAEA; color:#A61C1C; }
.iv-fase.durante .iv-fase-cab { background:#FDF3E7; color:#A66A00; }
.iv-fase.despues .iv-fase-cab { background:#E7F4EC; color:#2E7D32; }
.iv-fase-cuerpo { padding:6px; min-height:62px; display:flex; flex-wrap:wrap; gap:4px;
    align-items:center; justify-content:center; }
.iv-fase-cuerpo img { width:48px; height:48px; object-fit:cover; border-radius:6px;
    border:1px solid #d8dce6; cursor:zoom-in; }
.iv-fase-vacia { font-size:10.5px; color:#a3a9ba; text-align:center; }

/* --- Botones --- */
.iv-acciones { display:flex; gap:7px; margin-top:11px; }
.iv-btn { flex:1; padding:12px 8px; border-radius:11px; border:0; font-size:13.5px; font-weight:600;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; }
.iv-btn-dur { background:#EF9F27; color:#fff; }
.iv-btn-des { background:#1D9E75; color:#fff; }
.iv-btn:disabled { opacity:.45; cursor:not-allowed; }
.iv-btn-txt { background:transparent; border:0; color:#5b6478; font-size:12px; cursor:pointer;
    padding:7px 2px; text-decoration:underline; }

/* --- Bitácora --- */
.iv-bit { margin-top:10px; border-top:1px dashed #e0e4ee; padding-top:9px; }
.iv-bit-item { font-size:12px; color:#5b6478; padding:5px 0; display:flex; gap:8px; }
.iv-bit-fecha { font-weight:700; color:#2a3140; white-space:nowrap; }

/* --- Varios --- */
.iv-nota { font-size:11.5px; color:#767c94; margin-top:6px; line-height:1.45; }
.iv-vacio { text-align:center; padding:36px 20px; color:#5b6478; }
.iv-vacio i { font-size:42px; color:#1D9E75; }
.iv-toast { position:fixed; left:50%; bottom:22px; transform:translateX(-50%); background:#2a3140;
    color:#fff; padding:11px 18px; border-radius:10px; font-size:14px; z-index:4000; opacity:0;
    transition:opacity .25s; pointer-events:none; max-width:88%; text-align:center; }
.iv-toast.ver { opacity:1; }
.iv-lupa { position:fixed; inset:0; background:rgba(16,20,34,.9); z-index:5000; display:none;
    align-items:center; justify-content:center; padding:16px; }
.iv-lupa img { max-width:100%; max-height:88vh; border-radius:10px; }

@media (min-width:620px) { .iv-wrap { max-width:640px; } }
</style>

<div class="iv-wrap">

  <div class="iv-top">
    <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $inspeccionId ?>" class="iv-volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div style="flex:1;min-width:0;">
      <div class="iv-titulo"><?= e($insp['nombre_edificio']) ?></div>
      <div style="font-size:12px;color:#5b6478;">Intervención · antes, durante y después</div>
    </div>
    <div id="iv-avance-gen" style="font-size:22px;font-weight:800;color:#22366F;">—</div>
  </div>

  <div class="iv-tabs">
    <button type="button" class="iv-tab activa" id="iv-tab-res" onclick="ivVista('resultados')">
      <i class="bi bi-bar-chart-line"></i> Resultados
    </button>
    <?php if ($puedeReportar): ?>
    <button type="button" class="iv-tab" id="iv-tab-rep" onclick="ivVista('reportar')">
      <i class="bi bi-camera"></i> Reportar
    </button>
    <?php endif; ?>
  </div>

  <div id="iv-cargando" style="text-align:center;padding:40px;color:#5b6478;">
    <i class="bi bi-arrow-repeat" style="font-size:28px;"></i>
    <div style="margin-top:8px;">Cargando la intervención…</div>
  </div>

  <div id="iv-resultados" class="iv-oculto"></div>
  <div id="iv-reportar" class="iv-oculto"></div>
</div>

<input type="file" id="iv-file" accept="image/*" capture="environment" class="iv-oculto">
<div id="iv-toast" class="iv-toast"></div>
<div id="iv-lupa" class="iv-lupa" onclick="this.style.display='none'"><img id="iv-lupa-img" src="" alt="Foto ampliada"></div>

<script>
const IV_INSP  = <?= $inspeccionId ?>;
const IV_EDIF  = <?= $edificioId ?>;
const IV_URL   = '<?= APP_URL_BASE ?>seguimiento/';
const IV_PUEDE = <?= $puedeReportar ? 'true' : 'false' ?>;
</script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-fotos.js"></script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-offline.js"></script>
<script src="<?= APP_URL_BASE ?>seguimiento/campo.js?v=<?= @filemtime(__DIR__ . '/campo.js') ?: time() ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
