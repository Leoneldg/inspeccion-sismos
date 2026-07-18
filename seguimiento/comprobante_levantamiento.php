<?php
/**
 * COMPROBANTE DEL LEVANTAMIENTO TÉCNICO (PDF).
 *
 * Deja constancia de todo lo registrado: datos del edificio, áreas comunes,
 * pisos, apartamentos con sus jefes de familia y ambientes, y el cierre.
 * Sirve como respaldo físico y para verificar que nada se perdió.
 *
 * ?inspeccion=ID
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) { http_response_code(404); exit('Inspección no encontrada.'); }
if (!puedeAccederParroquia($insp['parroquia'] ?? null)) {
    http_response_code(403); exit('No tiene asignada esta parroquia.');
}

$ed = recEdificio($inspeccionId);
$edificioId = (int)($ed['id'] ?? 0);
if ($edificioId <= 0) { http_response_code(400); exit('Esta edificación no tiene levantamiento.'); }

$pisos  = recPisos($edificioId);
$areas  = recAreasComunes($edificioId);
$resp   = recResponsableLevantamiento($edificioId);
$sim    = recSimboloDecision($insp['decision_final'] ?? null);

// Contar todo lo registrado, para el resumen de verificación.
$totApt = 0; $totAmb = 0; $totJefes = 0; $totFotos = 0;
$detallePisos = [];
foreach ($pisos as $p) {
    $aptos = recApartamentos((int)$p['id']);
    $filaAptos = [];
    foreach ($aptos as $a) {
        $ambs = recAmbientes((int)$a['id']);
        $nf = count(recFotos('apartamento', (int)$a['id']));
        foreach ($ambs as $am) { $nf += count(recFotos('ambiente', (int)$am['id'])); }
        $totApt++;
        $totAmb += count($ambs);
        $totFotos += $nf;
        if (!empty($a['jefe_nombre'])) $totJefes++;
        $filaAptos[] = ['apto' => $a, 'ambientes' => $ambs, 'fotos' => $nf];
    }
    $elems = recElementosPiso((int)$p['id']);
    $detallePisos[] = ['piso' => $p, 'aptos' => $filaAptos, 'elementos' => $elems];
}
$totFotos += count(recFotos('edificio', $edificioId));

$fecha = date('d/m/Y');
$hora  = date('H:i');
$folio = strtoupper(substr(md5($inspeccionId . '|' . ($ed['completado_en'] ?? '')), 0, 8));

ob_start();
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; }
  body { margin: 0; color: #1a1f2b; font-size: 12px; }
  .hoja { padding: 24px 28px; }
  .cab { border-bottom: 3px solid #C9A227; padding-bottom: 12px; margin-bottom: 14px; }
  .cab h1 { margin: 0; font-size: 22px; color: #22366F; font-weight: 800; }
  .cab .sub { color: #55617f; font-size: 12px; margin-top: 3px; }
  .cab .folio { float: right; text-align: right; font-size: 11px; color: #55617f; }
  .cab .folio b { display: block; font-size: 15px; color: #22366F; letter-spacing: 1px; }

  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #22366F;
       margin: 16px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #e8ebf3; font-weight: 700; }

  table { width: 100%; border-collapse: collapse; }
  table.datos td { padding: 4px 6px; font-size: 11.5px; border-bottom: 1px solid #f0f2f7; }
  table.datos td.lbl { color: #55617f; width: 130px; }
  table.datos td.val { font-weight: 600; }

  table.lista th { background: #22366F; color: #fff; font-size: 10px; padding: 6px 5px;
                   text-align: left; text-transform: uppercase; }
  table.lista td { font-size: 10.5px; padding: 5px; border-bottom: 1px solid #e8ebf3; }
  table.lista tr:nth-child(even) td { background: #fafbfe; }
  table.lista tr { page-break-inside: avoid; }

  .resumen { display: table; width: 100%; border-spacing: 6px; margin-bottom: 6px; }
  .rcell { display: table-cell; text-align: center; padding: 10px 6px; border: 1px solid #dde3ef;
           border-radius: 8px; width: 20%; }
  .rcell .n { font-size: 21px; font-weight: 800; color: #22366F; }
  .rcell .l { font-size: 9px; text-transform: uppercase; color: #55617f; }

  .letra { display:inline-block; width:19px; height:19px; line-height:19px; text-align:center;
           border:2px solid; border-radius:4px; font-weight:800; font-size:11px; }
  .piso-t { background:#eef2fb; padding:6px 10px; border-radius:6px; font-weight:700;
            color:#22366F; font-size:12px; margin:12px 0 6px; }
  .firma { margin-top: 26px; display: table; width: 100%; border-spacing: 20px; }
  .firma div { display: table-cell; width: 50%; border-top: 1px solid #2a3140;
               padding-top: 5px; font-size: 10.5px; color: #55617f; text-align: center; }
  .pie { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e8ebf3;
         font-size: 9px; color: #767c94; text-align: center; }
  .aviso { background:#f4f7fd; border-radius:8px; padding:9px 12px; font-size:11px; color:#3a4256; }
</style></head><body>
<div class="hoja">

  <div class="cab">
    <div class="folio">Comprobante N.º<b><?= $folio ?></b><?= $fecha ?> · <?= $hora ?></div>
    <h1>Levantamiento técnico</h1>
    <div class="sub">Gestión de Obras Avanzadas · Constancia de registro</div>
  </div>

  <div class="aviso">
    Este documento deja constancia de los datos registrados en el levantamiento técnico.
    Consérvelo como respaldo: si algún dato no llegara al sistema, este comprobante
    permite verificarlo y volver a cargarlo.
  </div>

  <h2>Edificación</h2>
  <table class="datos">
    <tr><td class="lbl">Nombre</td><td class="val"><?= esc($insp['nombre_edificio'] ?? '—') ?></td>
        <td class="lbl">Código</td><td class="val"><?= esc($insp['codigo'] ?? '—') ?></td></tr>
    <tr><td class="lbl">Parroquia</td><td class="val"><?= esc($insp['parroquia'] ?? '—') ?></td>
        <td class="lbl">Municipio</td><td class="val"><?= esc($insp['municipio'] ?? '—') ?></td></tr>
    <tr><td class="lbl">Dirección</td><td class="val" colspan="3"><?= esc(trim(implode(', ', array_filter([$insp['avenida_calle'] ?? '', $insp['sector'] ?? '', $insp['urbanizacion'] ?? '']))) ?: '—') ?></td></tr>
    <tr><td class="lbl">Clasificación</td>
        <td class="val" colspan="3">
          <span class="letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"><?= $sim['letra'] ?></span>
          <?= esc($sim['texto']) ?>
        </td></tr>
    <tr><td class="lbl">Pisos</td><td class="val"><?= (int)($ed['num_pisos'] ?? 0) ?></td>
        <td class="lbl">Aptos por piso</td><td class="val"><?= (int)($ed['aptos_por_piso'] ?? 0) ?></td></tr>
    <tr><td class="lbl">Etiqueta</td>
        <td class="val" colspan="3">
          <?php if (!empty($ed['sin_etiqueta'])): ?>
            <span style="color:#a8871f;font-weight:700;">NO TIENE ETIQUETA</span>
            <?php if (!empty($ed['etiqueta_motivo'])): ?>
              · <?= esc($ed['etiqueta_motivo']) ?>
            <?php endif; ?>
            <?php if (!empty($ed['etiqueta_obs'])): ?>
              <br><span style="font-size:10.5px;color:#55617f;"><?= esc($ed['etiqueta_obs']) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <?php $nFotoEtq = count(recFotos('edificio', $edificioId)); ?>
            <?= $nFotoEtq > 0 ? 'Registrada con foto' : 'Pendiente' ?>
          <?php endif; ?>
        </td></tr>
  </table>

  <h2>Resumen de lo registrado</h2>
  <div class="resumen">
    <div class="rcell"><div class="n"><?= count($pisos) ?></div><div class="l">Pisos</div></div>
    <div class="rcell"><div class="n"><?= $totApt ?></div><div class="l">Apartamentos</div></div>
    <div class="rcell"><div class="n"><?= $totJefes ?></div><div class="l">Jefes de familia</div></div>
    <div class="rcell"><div class="n"><?= $totAmb ?></div><div class="l">Ambientes</div></div>
    <div class="rcell"><div class="n"><?= $totFotos ?></div><div class="l">Fotos</div></div>
  </div>
  <?php if ($totJefes < $totApt): ?>
  <div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:7px;padding:8px 11px;font-size:11px;color:#8a6d1a;">
    <b>Atención:</b> <?= $totApt - $totJefes ?> apartamento(s) sin datos del jefe de familia.
  </div>
  <?php endif; ?>

  <?php if ($areas): ?>
  <h2>Áreas comunes</h2>
  <table class="lista">
    <thead><tr><th>Área</th><th style="width:96px;">Reparación</th>
      <th style="width:110px;">Trabajo</th><th style="width:66px;">m²</th></tr></thead>
    <tbody>
    <?php foreach ($areas as $a): ?>
      <tr>
        <td><?= esc(ucfirst(str_replace('_', ' ', $a['tipo'] ?? ''))) ?></td>
        <td><?= !empty($a['necesita_reparacion']) ? 'Sí' : 'No' ?></td>
        <td><?= esc($a['tipo_trabajo'] ?? '—') ?></td>
        <td><?= $a['metros_cuadrados'] !== null ? (float)$a['metros_cuadrados'] : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2>Detalle por piso</h2>
  <?php foreach ($detallePisos as $dp):
    $p = $dp['piso'];
    $etq = (int)$p['numero_piso'] === 0 ? 'Planta Baja' : 'Piso ' . (int)$p['numero_piso'];
  ?>
    <div class="piso-t">
      <?= esc($etq) ?>
      <span style="float:right;font-weight:600;font-size:11px;">
        <?= count($dp['aptos']) ?> apartamento(s)
      </span>
    </div>

    <?php if ($dp['elementos']): ?>
    <div style="font-size:10.5px;color:#55617f;margin-bottom:5px;">
      <b>Elementos:</b>
      <?php $ee = [];
      foreach ($dp['elementos'] as $k => $el) {
          if (!empty($el['estado'])) $ee[] = ucfirst($k) . ': ' . $el['estado'];
      }
      echo esc($ee ? implode(' · ', $ee) : 'sin registrar'); ?>
    </div>
    <?php endif; ?>

    <?php if (!$dp['aptos']): ?>
      <div style="font-size:11px;color:#767c94;">Sin apartamentos registrados.</div>
    <?php else: ?>
    <table class="lista">
      <thead><tr>
        <th style="width:52px;">Apto</th>
        <th>Jefe de familia</th>
        <th style="width:86px;">Cédula</th>
        <th style="width:92px;">Teléfono</th>
        <th style="width:120px;">Ambientes</th>
        <th style="width:44px;">Fotos</th>
      </tr></thead>
      <tbody>
      <?php foreach ($dp['aptos'] as $fa):
        $a = $fa['apto'];
        $porTipo = [];
        foreach ($fa['ambientes'] as $am) {
            $t = $am['tipo'];
            $porTipo[$t] = ($porTipo[$t] ?? 0) + 1;
        }
        $txtAmb = [];
        foreach ($porTipo as $t => $n) $txtAmb[] = $n . ' ' . $t . ($n > 1 ? 's' : '');
      ?>
      <tr>
        <td style="font-weight:700;color:#22366F;"><?= esc($a['identificador']) ?></td>
        <td><?= esc($a['jefe_nombre'] ?: '— sin registrar —') ?></td>
        <td><?= esc($a['jefe_cedula'] ?: '—') ?></td>
        <td><?= esc($a['jefe_telefono'] ?: '—') ?></td>
        <td style="font-size:10px;"><?= esc($txtAmb ? implode(', ', $txtAmb) : '—') ?></td>
        <td style="text-align:center;"><?= (int)$fa['fotos'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endforeach; ?>

  <h2>Cierre del levantamiento</h2>
  <table class="datos">
    <tr><td class="lbl">Azotea</td><td class="val"><?= esc($ed['azotea_estado'] ?? '—') ?></td>
        <td class="lbl">Observación</td><td class="val"><?= esc($ed['azotea_obs'] ?? '—') ?></td></tr>
    <tr><td class="lbl">Tanques de agua</td><td class="val"><?= esc($ed['tanques_estado'] ?? '—') ?></td>
        <td class="lbl">Observación</td><td class="val"><?= esc($ed['tanques_obs'] ?? '—') ?></td></tr>
    <tr><td class="lbl">Estado</td>
        <td class="val" colspan="3" style="color:<?= !empty($ed['completado']) ? '#2E7D32' : '#a8871f' ?>;">
          <?= !empty($ed['completado']) ? 'CERRADO' : 'EN PROCESO' ?>
        </td></tr>
  </table>

  <h2>Responsables</h2>
  <table class="datos">
    <tr><td class="lbl">Inició</td>
        <td class="val"><?= esc($resp['creado_por_nombre'] ?? '—') ?></td>
        <td class="lbl">Fecha</td>
        <td class="val"><?= !empty($resp['creado_en']) ? date('d/m/Y H:i', strtotime($resp['creado_en'])) : '—' ?></td></tr>
    <tr><td class="lbl">Cerró</td>
        <td class="val"><?= esc($resp['completado_por_nombre'] ?? 'Pendiente') ?></td>
        <td class="lbl">Fecha</td>
        <td class="val"><?= !empty($resp['completado_en']) ? date('d/m/Y H:i', strtotime($resp['completado_en'])) : '—' ?></td></tr>
  </table>

  <div class="firma">
    <div>Firma del técnico responsable</div>
    <div>Firma del supervisor</div>
  </div>

  <div class="pie">
    Gestión de Obras Avanzadas · Comprobante <?= $folio ?> ·
    Generado el <?= $fecha ?> a las <?= $hora ?>
    por <?= esc($_SESSION['user_nombre'] ?? $_SESSION['nombre'] ?? 'usuario del sistema') ?>
  </div>

</div>
</body></html>
<?php
$html = ob_get_clean();

$tmpHtml = sys_get_temp_dir() . '/comp_' . uniqid() . '.html';
$tmpPdf  = sys_get_temp_dir() . '/comp_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 10mm --margin-bottom 10mm --margin-left 0 --margin-right 0 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
exec($cmd, $out, $code);

$nombre = 'Comprobante_' . preg_replace('/[^A-Za-z0-9]+/', '_', $insp['codigo'] ?? $inspeccionId)
        . '_' . date('Y-m-d_Hi') . '.pdf';

if ($code === 0 && is_file($tmpPdf)) {
    header('Content-Type: application/pdf');
    // "attachment" fuerza la descarga: queda guardado en el teléfono.
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

@unlink($tmpHtml); @unlink($tmpPdf);
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</body>', '<script>window.onload=function(){setTimeout(function(){window.print();},400);};</script></body>', $html);
