<?php
/**
 * MI SEGUIMIENTO · los edificios que el sistematizador ya levantó.
 *
 * A diferencia de "Levantamiento" (donde busca edificios nuevos entre los
 * 20 mil para hacerles el levantamiento técnico), aquí ve SUS edificios
 * ya levantados y le da seguimiento al avance de obra. Cada uno tiene un
 * botón que lo manda directo al modo campo (el formulario de intervención).
 *
 * Lista simple, pensada para campo. Con buscador por si necesita seguir
 * un edificio de otro.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$activeModule = 'mi_seguimiento';
$pageTitle = 'Seguimiento';
$BASE = APP_URL_BASE;
$uid  = (int)($_SESSION['user_id'] ?? 0);

$rolAct = mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8');
$esSistemCampo = !usuarioEsMaster()
              && !str_contains($rolAct, 'administrador')
              && function_exists('esSistematizador') && esSistematizador();

// Filtros: por defecto, sus edificios. Si busca, puede ver otros.
$parrF  = trim($_GET['parroquia'] ?? '');
$textoF = trim($_GET['q'] ?? '');
$estaBuscando = ($parrF !== '' || $textoF !== '');

$filtros = [];
if ($parrF !== '')  $filtros['parroquia'] = $parrF;
if ($textoF !== '') $filtros['texto']     = $textoF;
if ($esSistemCampo && !$estaBuscando) {
    $filtros['creado_por'] = $uid;
}
$lista = segEnReconstruccion($filtros);

// Parroquias con levantamientos, para el selector.
$parroquiasDisp = [];
try {
    $cond = []; $par = [];
    aplicarScopeEstado($cond, $par, 'i');
    aplicarScopeParroquia($cond, $par, 'i');
    $cond[] = "i.parroquia IS NOT NULL AND i.parroquia <> ''";
    $stPD = db()->prepare('SELECT DISTINCT i.parroquia FROM inspecciones i
                             JOIN rec_edificio re ON re.inspeccion_id = i.id
                            WHERE ' . implode(' AND ', $cond) . ' ORDER BY i.parroquia');
    $stPD->execute($par);
    $parroquiasDisp = $stPD->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/../includes/header.php';
?>
<style>
  .sg-wrap { max-width: 880px; margin: 0 auto; padding: 6px 8px 40px; }
  .sg-head { display:flex; align-items:center; gap:12px; margin: 4px 2px 16px; }
  .sg-head .ic { width:42px;height:42px;border-radius:11px;background:#1D6E56;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px; }
  .sg-head h1 { font-size:19px;font-weight:700;color:#22366F;margin:0;line-height:1.15; }
  .sg-head .sub { font-size:12.5px;color:#5b6478; }

  .sg-tools { display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px; }
  .sg-aviso { border-radius:9px;padding:9px 13px;font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:12px; }

  .sg-item { display:flex;align-items:center;gap:13px;border:1px solid #e6e9f0;border-radius:12px;padding:13px 15px;background:#fff;margin-bottom:9px; }
  .sg-item .avatar { width:40px;height:40px;border-radius:10px;background:#E9EEF9;color:#22366F;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0; }
  .sg-item .datos { flex:1;min-width:0; }
  .sg-item .datos b { font-size:14.5px;color:#22366F;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
  .sg-item .datos span { font-size:12px;color:#5b6478; }
  .sg-prog { width:120px;flex-shrink:0; }
  .sg-prog .barra { height:7px;background:#eef1f7;border-radius:4px;overflow:hidden; }
  .sg-prog .barra > div { height:100%;background:#1D6E56;border-radius:4px; }
  .sg-prog .txt { font-size:11px;color:#5b6478;margin-top:3px;text-align:right; }
  .sg-btn { flex-shrink:0;background:#22366F;color:#fff;border-radius:9px;padding:10px 15px;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap; }
  .sg-btn:hover { background:#1a2a57; }
  .sg-vacio { text-align:center;color:#9aa1b4;padding:50px 20px;font-size:13.5px; }

  @media (max-width:640px){
    .sg-prog { display:none; }
    .sg-item .datos b { white-space:normal; }
  }
</style>

<div class="sg-wrap">

  <div class="sg-head">
    <div class="ic"><i class="bi bi-clipboard-check-fill"></i></div>
    <div>
      <h1>Seguimiento de obra</h1>
      <div class="sub">Tus edificios levantados · dale seguimiento al avance</div>
    </div>
  </div>

  <div class="sg-tools">
    <?php if (count($parroquiasDisp) > 1): ?>
    <select class="form-control" style="width:180px;"
            onchange="location.href='?parroquia=' + encodeURIComponent(this.value)">
      <option value="">Todas las parroquias</option>
      <?php foreach ($parroquiasDisp as $pd): ?>
      <option value="<?= e($pd) ?>" <?= $parrF === $pd ? 'selected' : '' ?>><?= e($pd) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="text" class="form-control" style="width:210px;" value="<?= e($textoF) ?>"
           placeholder="Buscar edificación…"
           onkeydown="if(event.key==='Enter'){location.href='?q='+encodeURIComponent(this.value);}">
  </div>

  <?php if ($esSistemCampo): ?>
  <div class="sg-aviso" style="background:<?= $estaBuscando ? '#FDF7E7' : '#E7F4EC' ?>;
       border:1px solid <?= $estaBuscando ? '#C9A22755' : '#1D6E5633' ?>;
       color:<?= $estaBuscando ? '#A66A00' : '#1D6E56' ?>;">
    <i class="bi bi-<?= $estaBuscando ? 'search' : 'person-check-fill' ?>"></i>
    <?php if ($estaBuscando): ?>
      Resultados de la búsqueda. <a href="?" style="color:#22366F;font-weight:600;margin-left:auto;">Ver solo los míos</a>
    <?php else: ?>
      Estás viendo <strong>tus edificios</strong>. Para seguir el de otro, usa el buscador.
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($lista)): ?>
    <div class="sg-vacio">
      <i class="bi bi-inbox" style="font-size:30px;display:block;margin-bottom:10px;"></i>
      <?= $estaBuscando ? 'No se encontraron edificios con esa búsqueda.' : 'Todavía no tienes edificios levantados. Ve a Levantamiento para empezar uno.' ?>
    </div>
  <?php else: ?>
    <?php foreach ($lista as $e):
      $nombre = $e['nombre_edificio'] ?: 'Sin nombre';
      $pct = (int)($e['pct'] ?? $e['avance'] ?? 0);
      $inspId = (int)$e['id'];
    ?>
    <div class="sg-item">
      <div class="avatar"><i class="bi bi-building"></i></div>
      <div class="datos">
        <b><?= e($nombre) ?></b>
        <span><?= e($e['codigo'] ?? '') ?> · <?= e($e['parroquia'] ?? '') ?> · <?= (int)($e['n_aptos'] ?? 0) ?> apto(s)</span>
      </div>
      <div class="sg-prog">
        <div class="barra"><div style="width:<?= $pct ?>%;"></div></div>
        <div class="txt"><?= $pct ?>% avance</div>
      </div>
      <a href="<?= $BASE ?>seguimiento/campo.php?inspeccion=<?= $inspId ?>" class="sg-btn">
        <i class="bi bi-arrow-right-circle"></i> Hacerle seguimiento
      </a>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
