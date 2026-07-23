<?php
/**
 * PLANILLA IMPRIMIBLE DEL LEVANTAMIENTO.
 *
 * Un formulario EN BLANCO para llenar a mano en la calle y cargar
 * después en el sistema. Incluye todos los campos que pide el
 * levantamiento digital, con casillas y líneas para escribir.
 *
 * Parámetros GET (opcionales, para ajustar cuántas hojas imprimir):
 *   aptos   (int)  cuántas hojas de apartamento incluir (def. 6)
 *   ambientes (int) cuántos ambientes por apartamento (def. 5)
 *   pdf     (1)    si se quiere como PDF en vez de HTML imprimible
 *
 * Uso: planilla_levantamiento.php?aptos=8&ambientes=6
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Cuántas hojas de apartamento y ambientes por hoja.
$numAptos     = max(1, min(40, (int)($_GET['aptos'] ?? 6)));
$numAmbientes = max(1, min(12, (int)($_GET['ambientes'] ?? 5)));
$comoPdf      = !empty($_GET['pdf']);

// Catálogos reales del sistema, para que la planilla liste las mismas
// opciones que verá quien cargue después.
$tiposTrabajo = [];
try {
    foreach (recTiposTrabajo() as $t) {
        $tiposTrabajo[$t['clave']] = $t['nombre'];
    }
} catch (Throwable $e) {}

$areasComunes = [];
try { $areasComunes = recAreasComunesTipicas(); } catch (Throwable $e) {}

$tiposAmbiente = ['Habitación', 'Sala', 'Baño', 'Cocina', 'Balcón', 'Otro'];
$tiposSuperficie = ['Pared', 'Techo', 'Piso'];

// Una línea en blanco para escribir a mano.
function linea($ancho = '100%', $alto = '18px') {
    return '<span style="display:inline-block;width:' . $ancho
         . ';border-bottom:1px solid #9aa3b8;height:' . $alto . ';"></span>';
}
// Una casilla para marcar.
function casilla($texto = '') {
    return '<span style="display:inline-block;width:12px;height:12px;border:1.3px solid #55617f;'
         . 'border-radius:2px;vertical-align:middle;margin-right:4px;"></span>'
         . ($texto !== '' ? '<span style="vertical-align:middle;">' . esc($texto) . '</span>' : '');
}

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 10.5px; }
  .hoja { padding: 16px 22px; }
  .salto { page-break-before: always; }

  .cab { border-bottom: 2.5px solid #C9A227; padding-bottom: 8px; margin-bottom: 12px; }
  .cab h1 { margin: 0; font-size: 19px; color: #22366F; font-weight: 800; }
  .cab .sub { font-size: 10.5px; color: #55617f; margin-top: 2px; }
  .cab .der { float: right; text-align: right; font-size: 9.5px; color: #55617f; }

  h2 { font-size: 12.5px; color: #fff; background: #22366F; margin: 14px 0 8px;
       padding: 5px 9px; border-radius: 5px; }
  h3 { font-size: 11px; color: #22366F; margin: 10px 0 5px;
       border-bottom: 1px solid #dde2ec; padding-bottom: 3px; }

  .campo { margin-bottom: 7px; }
  .campo .lbl { font-size: 9.5px; color: #55617f; font-weight: 600;
                text-transform: uppercase; display: block; margin-bottom: 2px; }

  .grid2 { display: table; width: 100%; border-spacing: 10px 0; }
  .grid2 > div { display: table-cell; width: 50%; vertical-align: top; }
  .grid3 { display: table; width: 100%; border-spacing: 8px 0; }
  .grid3 > div { display: table-cell; width: 33%; vertical-align: top; }

  .cajas { font-size: 10px; line-height: 1.9; }
  .cajas span.op { display: inline-block; margin-right: 14px; white-space: nowrap; }

  table.amb { width: 100%; border-collapse: collapse; margin-top: 4px; }
  table.amb th { background: #eef2fb; color: #22366F; font-size: 8.5px; padding: 4px 5px;
                 border: 1px solid #cfd6e6; text-transform: uppercase; }
  table.amb td { border: 1px solid #cfd6e6; height: 30px; padding: 3px 5px; font-size: 9px; }

  .ref { background: #f7f9fd; border: 1px solid #e0e4ee; border-radius: 6px;
         padding: 7px 10px; font-size: 8.8px; color: #45506b; margin-top: 6px; }
  .ref b { color: #22366F; }

  .nota { margin-top: 10px; font-size: 8.5px; color: #7a8398; }
  .apto-hoja { border: 1.5px solid #22366F; border-radius: 8px; padding: 12px 14px; }
</style>
</head>
<body>

<!-- ============ HOJA 1: DATOS DEL EDIFICIO ============ -->
<div class="hoja">
  <div class="cab">
    <div class="der">
      Planilla de campo · para llenar a mano<br>
      Fecha: <?= linea('90px') ?> Levantador: <?= linea('120px') ?>
    </div>
    <h1>Levantamiento técnico</h1>
    <div class="sub">Edificación afectada — hoja del edificio</div>
  </div>

  <h2>Identificación de la edificación</h2>
  <div class="grid2">
    <div>
      <div class="campo"><span class="lbl">Nombre del edificio</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Dirección</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Parroquia</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Punto de referencia</span><?= linea() ?></div>
    </div>
    <div>
      <div class="campo"><span class="lbl">Código / N° de inspección</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Coordenadas (si las tiene)</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Uso</span>
        <div class="cajas">
          <span class="op"><?= casilla('Residencial') ?></span>
          <span class="op"><?= casilla('Comercial') ?></span>
          <span class="op"><?= casilla('Mixto') ?></span>
          <span class="op"><?= casilla('Casa') ?></span>
        </div>
      </div>
    </div>
  </div>

  <h2>Estructura</h2>
  <div class="grid3">
    <div class="campo"><span class="lbl">Cantidad de pisos</span><?= linea() ?></div>
    <div class="campo"><span class="lbl">Aptos por piso</span><?= linea() ?></div>
    <div class="campo"><span class="lbl">Total de aptos</span><?= linea() ?></div>
  </div>
  <div class="cajas" style="margin-top:4px;">
    <span class="op"><?= casilla('Tiene planta baja (aparte de los pisos)') ?></span>
    <span class="op"><?= casilla('Tiene locales comerciales') ?></span>
    N° de locales: <?= linea('50px') ?>
  </div>

  <h2>Áreas comunes del edificio</h2>
  <div class="cajas">
    <?php foreach ($areasComunes as $k => $nombre): ?>
      <span class="op"><?= casilla($nombre) ?></span>
    <?php endforeach; ?>
    <span class="op"><?= casilla('Otra: ') ?><?= linea('90px') ?></span>
  </div>
  <div class="nota">
    Para cada área común marcada que necesite reparación, anote en el reverso:
    tipo de trabajo, metros² y una referencia de foto.
  </div>

  <h2>Cierre (azotea y tanques) — se llena al final del recorrido</h2>
  <div class="grid2">
    <div>
      <div class="campo"><span class="lbl">Estado de la azotea / terraza</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Estado de los tanques de agua</span><?= linea() ?></div>
    </div>
    <div>
      <div class="campo"><span class="lbl">Fecha estimada de inicio</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Fecha estimada de fin</span><?= linea() ?></div>
    </div>
  </div>
  <div class="campo"><span class="lbl">Observaciones generales</span><?= linea() ?></div>
  <div class="campo"><?= linea() ?></div>
</div>

<!-- ============ HOJAS DE APARTAMENTO ============ -->
<?php for ($a = 1; $a <= $numAptos; $a++): ?>
<div class="hoja salto">
  <div class="cab">
    <div class="der">
      Edificio: <?= linea('130px') ?><br>
      Hoja <?= $a ?> de <?= $numAptos ?>
    </div>
    <h1>Apartamento / Local</h1>
    <div class="sub">Una hoja por cada apartamento o local</div>
  </div>

  <div class="apto-hoja">
    <div class="grid3">
      <div class="campo"><span class="lbl">Piso</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Identificador (ej. 3-A / PB-A / L-1)</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Tipo</span>
        <div class="cajas"><span class="op"><?= casilla('Apto') ?></span><span class="op"><?= casilla('Local') ?></span></div>
      </div>
    </div>

    <h3>Responsable (jefe de familia o dueño del local)</h3>
    <div class="grid3">
      <div class="campo"><span class="lbl">Nombre y apellido</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Cédula</span><?= linea() ?></div>
      <div class="campo"><span class="lbl">Teléfono</span><?= linea() ?></div>
    </div>
    <div class="cajas">
      <span class="op"><?= casilla('No se pudo visitar / cerrado') ?></span>
      <span class="op">Motivo: <?= linea('180px') ?></span>
    </div>

    <h3>Ambientes y reparaciones</h3>
    <table class="amb">
      <thead>
        <tr>
          <th style="width:16%;">Ambiente</th>
          <th style="width:14%;">¿Repara?</th>
          <th style="width:26%;">Tipo de trabajo</th>
          <th style="width:10%;">Superficie</th>
          <th style="width:12%;">Metros²</th>
          <th style="width:22%;">Ref. foto</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $numAmbientes; $i++): ?>
        <tr>
          <td></td>
          <td style="text-align:center;">Sí / No</td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
    <div class="nota">
      Tipos de ambiente: <?= esc(implode(' · ', $tiposAmbiente)) ?>.
      Para locales: Área de venta · Depósito · Baño de local.
      Superficies: <?= esc(implode(' · ', $tiposSuperficie)) ?>.
    </div>
  </div>
</div>
<?php endfor; ?>

<!-- ============ HOJA DE REFERENCIA: TIPOS DE TRABAJO ============ -->
<?php if ($tiposTrabajo): ?>
<div class="hoja salto">
  <div class="cab">
    <h1>Referencia · tipos de trabajo</h1>
    <div class="sub">Copie el nombre exacto en la columna "Tipo de trabajo"</div>
  </div>
  <div class="ref" style="font-size:10px;line-height:1.8;">
    <?php foreach ($tiposTrabajo as $clave => $nombre): ?>
      <b><?= esc($nombre) ?></b><br>
    <?php endforeach; ?>
  </div>
  <div class="nota" style="margin-top:12px;">
    Regla de materiales: si el trabajo incluye demolición, arrastra
    reconstrucción y revestimiento; si es reconstrucción, arrastra
    revestimiento; si es solo revestimiento (friso/pintura), va solo.
  </div>
</div>
<?php endif; ?>

</body></html>
<?php
$html = ob_get_clean();

// Salida como HTML imprimible (por defecto) o PDF.
if (!$comoPdf) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    // Auto-abrir el diálogo de impresión.
    echo '<script>window.onload=function(){window.print();};</script>';
    exit;
}

$tmpHtml = sys_get_temp_dir() . '/planilla_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/planilla_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 8mm --margin-bottom 8mm --margin-left 6mm --margin-right 6mm '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Planilla_levantamiento_' . date('Y-m-d') . '.pdf';
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
