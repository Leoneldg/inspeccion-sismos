<?php
/**
 * MI TRABAJO · vista de inicio del sistematizador.
 *
 * Resumen simple y enfocado en campo: sus edificios, cuántos
 * levantamientos lleva, qué le falta por subir. Accesos directos a
 * sus tres herramientas (Levantamientos, Subir el durante, Requisiciones).
 *
 * Se apoya en lo que ya existe (obrasDeFrente, frenteDeUsuario) y no
 * toca ninguna lógica ni estructura de datos.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$activeModule = 'mi_trabajo';
$pageTitle = 'Mi trabajo';
$BASE = APP_URL_BASE;
$uid = (int)($_SESSION['user_id'] ?? 0);
$nombre = $_SESSION['nombre'] ?? 'Sistematizador';

// Contar el trabajo del usuario a partir de lo que él ha levantado.
$totalLevant = 0; $completos = 0; $enProceso = 0; $sinDurante = 0;
try {
    $st = db()->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN re.completado = 1 THEN 1 ELSE 0 END) AS completos
         FROM rec_edificio re
         WHERE re.creado_por = :u"
    );
    $st->execute(['u' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalLevant = (int)($row['total'] ?? 0);
    $completos   = (int)($row['completos'] ?? 0);
    $enProceso   = max(0, $totalLevant - $completos);
} catch (Throwable $e) { /* si la tabla no tiene la columna, se queda en 0 */ }

include __DIR__ . '/../includes/header.php';
?>
<style>
  .mt-wrap { max-width: 860px; margin: 0 auto; padding: 6px 8px 40px; }
  .mt-hola { display:flex; align-items:center; gap:13px; margin: 6px 2px 18px; }
  .mt-hola .av { width:46px;height:46px;border-radius:12px;background:#22366F;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700; }
  .mt-hola h1 { font-size:20px;font-weight:700;color:#22366F;margin:0;line-height:1.15; }
  .mt-hola .sub { font-size:12.5px;color:#5b6478; }

  .mt-kpis { display:grid;grid-template-columns:repeat(3,1fr);gap:11px;margin-bottom:18px; }
  .mt-kpi { border-radius:13px;padding:16px;color:#fff; }
  .mt-kpi .n { font-size:28px;font-weight:800;line-height:1; }
  .mt-kpi .l { font-size:12px;opacity:.92;margin-top:5px; }

  .mt-tit { font-size:11px;color:#9aa1b4;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin:0 2px 10px; }
  .mt-acc { display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px; }
  .mt-card { display:flex;align-items:center;gap:14px;border:1px solid #e6e9f0;border-radius:14px;padding:17px;background:#fff;text-decoration:none;transition:border-color .15s,box-shadow .15s; }
  .mt-card:hover { border-color:#22366F;box-shadow:0 3px 12px rgba(34,54,111,.08); }
  .mt-card .ic { width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0; }
  .mt-card .tx b { font-size:15px;color:#22366F;display:block; }
  .mt-card .tx span { font-size:12px;color:#5b6478; }
  .mt-card .go { margin-left:auto;color:#c4c9d6;font-size:18px; }
</style>

<div class="mt-wrap">

  <div class="mt-hola">
    <div class="av"><?= strtoupper(mb_substr($nombre, 0, 1)) ?></div>
    <div>
      <h1>Hola, <?= e($nombre) ?></h1>
      <div class="sub">Este es tu panel de trabajo de campo</div>
    </div>
  </div>

  <div class="mt-kpis">
    <div class="mt-kpi" style="background:#22366F;">
      <div class="n"><?= number_format($totalLevant,0,',','.') ?></div>
      <div class="l">Edificios que trabajas</div>
    </div>
    <div class="mt-kpi" style="background:#1D6E56;">
      <div class="n"><?= number_format($completos,0,',','.') ?></div>
      <div class="l">Levantamientos completos</div>
    </div>
    <div class="mt-kpi" style="background:#C9A227;">
      <div class="n"><?= number_format($enProceso,0,',','.') ?></div>
      <div class="l">En proceso</div>
    </div>
  </div>

  <div class="mt-tit">¿Qué quieres hacer?</div>
  <div class="mt-acc">
    <a href="<?= $BASE ?>seguimiento/index.php" class="mt-card">
      <div class="ic" style="background:#E9EEF9;color:#22366F;"><i class="bi bi-search"></i></div>
      <div class="tx"><b>Levantamiento</b><span>Buscar un edificio nuevo y levantarlo</span></div>
      <i class="bi bi-chevron-right go"></i>
    </a>
    <a href="<?= $BASE ?>seguimiento/mi_seguimiento.php" class="mt-card">
      <div class="ic" style="background:#E7F4EC;color:#1D6E56;"><i class="bi bi-clipboard-check"></i></div>
      <div class="tx"><b>Seguimiento</b><span>Reportar avance de tus edificios</span></div>
      <i class="bi bi-chevron-right go"></i>
    </a>
    <a href="<?= $BASE ?>seguimiento/requisiciones.php" class="mt-card">
      <div class="ic" style="background:#FDF7E7;color:#A66A00;"><i class="bi bi-file-earmark-text"></i></div>
      <div class="tx"><b>Requisiciones</b><span>Solicitar material para la obra</span></div>
      <i class="bi bi-chevron-right go"></i>
    </a>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
