<?php
/**
 * PDF · FRENTES DE TRABAJO POR PARROQUIA.
 *
 * Un reporte listo para imprimir: agrupado por parroquia y, dentro de
 * cada una, cada Frente de Trabajo con su equipo de supervisión
 * (responsable, ingeniero y sistematizador) y sus cifras de brigadas
 * y edificaciones asignadas.
 *
 * Uso: pdf_frentes.php                    -> todas las parroquias del usuario
 *      pdf_frentes.php?parroquia=Sucre    -> solo esa parroquia
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ent($v) { return number_format((int)$v, 0, ',', '.'); }

frenteRespAsegurar();
repAsegurarTablas();
segAsegurarEquipoFrente();

$misParroquias = parroquiasDelUsuario();
$esResponsable = usuarioLimitadoAParroquia();

$parrF = trim($_GET['parroquia'] ?? '');
if ($parrF !== '' && $misParroquias && !in_array($parrF, $misParroquias, true)) {
    http_response_code(403);
    exit('No tiene asignada esta parroquia.');
}

// --- Frentes activos, con el mismo alcance que la pantalla de gestión ---
$frentes = [];
try {
    if ($esResponsable && $misParroquias) {
        $marcas = []; $params = [];
        foreach ($misParroquias as $i => $pp) { $marcas[] = ':p' . $i; $params['p' . $i] = $pp; }
        $stF = db()->prepare('SELECT * FROM frente WHERE activo = 1 AND parroquia IN ('
            . implode(',', $marcas) . ') ORDER BY parroquia, numero');
        $stF->execute($params);
        $frentes = frenteAdjuntarBrigadas($stF->fetchAll());
    } else {
        $frentes = frenteAdjuntarBrigadas(
            db()->query('SELECT * FROM frente WHERE activo = 1 ORDER BY parroquia, numero')->fetchAll()
        );
    }
} catch (Throwable $e) {}

if ($parrF !== '') {
    $frentes = array_values(array_filter($frentes, fn($f) => ($f['parroquia'] ?? '') === $parrF));
}

// --- Equipo asignado a cada frente: responsable, teléfono, ingeniero, sistematizador ---
$equipoFrente = [];
try {
    foreach (db()->query("
        SELECT f.id,
               f.responsable AS resp_manual, f.responsable_tlf,
               f.ingeniero_id, f.sistematizador_id,
               ing.nombre_completo AS ing_nombre, ing.cedula AS ing_cedula,
               us.nombre_completo  AS sis_nombre
          FROM frente f
          LEFT JOIN ingenieros ing ON ing.id = f.ingeniero_id
          LEFT JOIN usuarios   us  ON us.id  = f.sistematizador_id
    ")->fetchAll() as $eqRow) {
        $equipoFrente[(int)$eqRow['id']] = $eqRow;
    }
} catch (Throwable $e) {}

// --- Responsable general de cada parroquia (encargado), para el encabezado de sección ---
$respPorParroquia = [];
try {
    $stRP = db()->query("SELECT rp.parroquia, r.nombre, r.telefono
                           FROM representante_parroquia rp
                           JOIN representantes r ON r.id = rp.representante_id
                          WHERE r.activo = 1");
    foreach ($stRP->fetchAll() as $r) {
        $respPorParroquia[$r['parroquia']] = ['nombre' => $r['nombre'], 'telefono' => $r['telefono'] ?? ''];
    }
} catch (Throwable $e) {}

// --- Agrupar por parroquia ---
$porParroquia = [];
foreach ($frentes as $f) {
    $porParroquia[$f['parroquia'] ?: 'Sin parroquia asignada'][] = $f;
}
ksort($porParroquia, SORT_NATURAL | SORT_FLAG_CASE);

// --- Totales generales ---
$totFrentes    = count($frentes);
$totBrigadas   = 0;
$totObras      = 0;
$totIngAsig    = 0;
$totSisAsig    = 0;
$totRespAsig   = 0;
foreach ($frentes as $f) {
    $totBrigadas += count($f['brigadas'] ?? []);
    $totObras    += (int)($f['obras'] ?? 0);
    $eq = $equipoFrente[(int)$f['id']] ?? [];
    if (!empty($eq['ing_nombre'])) $totIngAsig++;
    if (!empty($eq['sis_nombre'])) $totSisAsig++;
    if (!empty($eq['resp_manual'])) $totRespAsig++;
}
$totParroquias = count($porParroquia);

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
  .hoja { padding: 24px 28px; }

  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 11px; margin-bottom: 15px; }
  .cab h1 { margin: 0; font-size: 25px; color: #22366F; font-weight: 800; letter-spacing: -.3px; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; font-weight: 600; }
  .cab .sub { font-size: 11.5px; color: #55617f; margin-top: 3px; }

  .kpis { display: table; width: 100%; border-spacing: 6px 0; margin-bottom: 16px; }
  .kpis .k { display: table-cell; text-align: center; padding: 12px 6px;
             border-radius: 9px; border: 1px solid; }
  .kpis .n { font-size: 22px; font-weight: 800; line-height: 1; }
  .kpis .l { font-size: 8.5px; text-transform: uppercase; color: #55617f;
             margin-top: 5px; letter-spacing: .3px; }

  .parr { background: #22366F; color: #fff; padding: 8px 13px; border-radius: 7px 7px 0 0;
          font-size: 13px; font-weight: 800; letter-spacing: .3px; }
  .parr .n { float: right; font-weight: 600; font-size: 10px; opacity: .9; text-transform: none; }
  .parr-caja { border: 1px solid #dde2ec; border-top: 0; border-radius: 0 0 7px 7px;
               margin-bottom: 14px; overflow: hidden; }
  .parr-enc { background: #f4f7fd; padding: 6px 13px; font-size: 9.5px; color: #55617f;
              border-bottom: 1px solid #e3e7f1; }
  .parr-enc strong { color: #22366F; }

  table.ft { width: 100%; border-collapse: collapse; }
  table.ft thead { display: table-header-group; }
  table.ft tr { page-break-inside: avoid; }
  table.ft th { background: #eef2fb; color: #22366F; font-size: 8.5px; padding: 6px 8px;
                text-align: left; text-transform: uppercase; letter-spacing: .3px;
                border-bottom: 1px solid #dde2ec; }
  table.ft td { font-size: 10px; padding: 7px 8px; border-bottom: 1px solid #eef0f5;
                vertical-align: top; }
  table.ft tr:last-child td { border-bottom: 0; }
  table.ft tr:nth-child(even) td { background: #fafbfe; }

  .fnum { display: inline-block; background: #22366F; color: #fff; width: 22px; height: 22px;
          line-height: 22px; text-align: center; border-radius: 6px; font-weight: 800;
          font-size: 11px; }
  .fnombre { font-size: 8.5px; color: #2d4488; font-weight: 700; margin-top: 2px; }
  .persona { font-weight: 700; color: #2a3140; }
  .cargo-vacio { color: #a8871f; font-style: italic; font-size: 9.5px; }
  .tel { color: #767c94; font-size: 9px; margin-top: 1px; }
  .cifra { text-align: center; }
  .cifra .n { font-size: 13px; font-weight: 800; color: #2d4488; line-height: 1; }

  .leyenda { font-size: 8.5px; color: #767c94; margin: -6px 0 14px; }
  .leyenda span.pt { display:inline-block; width:8px; height:8px; border-radius:2px;
                      background:#C9A227; margin-right:4px; }

  .pie { margin-top: 16px; padding-top: 9px; border-top: 1px solid #e8ebf3;
         font-size: 8px; color: #767c94; text-align: center; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="der"><?= $fecha ?><br><?= $hora ?></div>
    <h1>Frentes de trabajo</h1>
    <div class="sub">
      Equipo de supervisión por parroquia · Responsable, ingeniero y sistematizador
      <?php if ($parrF !== ''): ?> · Parroquia: <strong><?= esc($parrF) ?></strong><?php endif; ?>
    </div>
  </div>

  <?php if (!$frentes): ?>
    <p style="color:#767c94;padding:50px;text-align:center;">
      No hay frentes de trabajo activos<?= $parrF !== '' ? ' en esta parroquia' : '' ?>.
    </p>
  <?php else: ?>

  <!-- Totales generales -->
  <div class="kpis">
    <div class="k" style="border-color:#22366F33;background:#22366F0a;">
      <div class="n" style="color:#22366F;"><?= ent($totFrentes) ?></div>
      <div class="l">Frentes de trabajo</div>
    </div>
    <div class="k" style="border-color:#2d448833;background:#2d44880a;">
      <div class="n" style="color:#2d4488;"><?= ent($totBrigadas) ?></div>
      <div class="l">Brigadas en total</div>
    </div>
    <div class="k" style="border-color:#C9A22733;background:#C9A2270a;">
      <div class="n" style="color:#a8871f;"><?= ent($totObras) ?></div>
      <div class="l">Edificaciones asignadas</div>
    </div>
    <div class="k" style="border-color:#2E7D3233;background:#2E7D320a;">
      <div class="n" style="color:#2E7D32;"><?= ent($totParroquias) ?></div>
      <div class="l">Parroquias cubiertas</div>
    </div>
  </div>

  <div class="leyenda">
    <?= ent($totRespAsig) ?> de <?= ent($totFrentes) ?> frentes tienen responsable asignado ·
    <?= ent($totIngAsig) ?> tienen ingeniero ·
    <?= ent($totSisAsig) ?> tienen sistematizador.
    Los campos sin asignar se muestran en <span style="color:#a8871f;font-weight:700;">ámbar</span>.
  </div>

  <?php foreach ($porParroquia as $parrNombre => $lista): ?>
  <div class="parr-bloque">
    <div class="parr">
      <?= esc(mb_strtoupper($parrNombre, 'UTF-8')) ?>
      <span class="n"><?= count($lista) ?> frente<?= count($lista) === 1 ? '' : 's' ?> ·
        <?= array_sum(array_map(fn($f) => count($f['brigadas'] ?? []), $lista)) ?> brigadas ·
        <?= array_sum(array_map(fn($f) => (int)($f['obras'] ?? 0), $lista)) ?> edificaciones</span>
    </div>
    <div class="parr-caja">
      <?php $enc = $respPorParroquia[$parrNombre] ?? null; ?>
      <?php if ($enc): ?>
      <div class="parr-enc">
        <i>Responsable de la parroquia:</i> <strong><?= esc($enc['nombre']) ?></strong>
        <?php if (!empty($enc['telefono'])): ?> · <?= esc($enc['telefono']) ?><?php endif; ?>
      </div>
      <?php endif; ?>

      <table class="ft">
        <thead><tr>
          <th style="width:70px;">Frente</th>
          <th>Responsable</th>
          <th>Ingeniero</th>
          <th>Sistematizador</th>
          <th style="width:52px;">Brigadas</th>
          <th style="width:58px;">Edificac.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista as $f):
          $fid = (int)$f['id'];
          $eq  = $equipoFrente[$fid] ?? [];
        ?>
        <tr>
          <td>
            <span class="fnum"><?= (int)$f['numero'] ?></span>
            <?php if (!empty($f['nombre'])): ?>
              <div class="fnombre"><?= esc($f['nombre']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($eq['resp_manual'])): ?>
              <div class="persona"><?= esc($eq['resp_manual']) ?></div>
              <?php if (!empty($eq['responsable_tlf'])): ?>
                <div class="tel"><i class="tel-ico">Tel.</i> <?= esc($eq['responsable_tlf']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="cargo-vacio">Sin asignar</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($eq['ing_nombre'])): ?>
              <div class="persona"><?= esc($eq['ing_nombre']) ?></div>
              <?php if (!empty($eq['ing_cedula'])): ?>
                <div class="tel">C.I. <?= esc($eq['ing_cedula']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="cargo-vacio">Sin asignar</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($eq['sis_nombre'])): ?>
              <div class="persona"><?= esc($eq['sis_nombre']) ?></div>
            <?php else: ?>
              <span class="cargo-vacio">Sin asignar</span>
            <?php endif; ?>
          </td>
          <td class="cifra">
            <div class="n"><?= count($f['brigadas'] ?? []) ?></div>
          </td>
          <td class="cifra">
            <div class="n"><?= (int)($f['obras'] ?? 0) ?></div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>

  <div class="pie">
    Gestión de Obras Avanzadas · Frentes de trabajo ·
    Generado el <?= $fecha ?> a las <?= $hora ?> por <?= esc($quien) ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

// Generar el PDF con wkhtmltopdf.
$tmpHtml = sys_get_temp_dir() . '/frt_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/frt_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 10mm --margin-bottom 10mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$sufijo = $parrF !== '' ? preg_replace('/[^A-Za-z0-9]+/', '_', $parrF) : 'General';
$nombre = 'Frentes_de_trabajo_' . $sufijo . '_' . date('Y-m-d') . '.pdf';

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
