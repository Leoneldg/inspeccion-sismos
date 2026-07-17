<?php
/**
 * Genera un PDF breve con el resumen de una parroquia:
 * encargado, conteo por color y estado de las edificaciones.
 * ?estado=Distrito Capital&parroquia=Altagracia
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('dashboard', 'ver');

$estado    = trim($_GET['estado'] ?? '');
$parroquia = trim($_GET['parroquia'] ?? '');
if ($estado === '' || $parroquia === '') { http_response_code(400); exit('Faltan parámetros.'); }
if (!usuarioEsMaster() && $estado !== estadoDelUsuario()) { http_response_code(403); exit('No autorizado.'); }

$d = recPanelParroquia($estado, $parroquia);
$pc = $d['por_color'];

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Encargados
$encHtml = '';
if ($d['encargados']) {
    foreach ($d['encargados'] as $r) {
        $encHtml .= '<div style="margin-bottom:4px;"><b>' . esc($r['nombre']) . '</b>'
                 . ($r['cargo'] ? ' · ' . esc($r['cargo']) : '')
                 . ($r['telefono'] ? ' · ' . esc($r['telefono']) : '') . '</div>';
    }
} else {
    $encHtml = '<span style="color:#888;">Sin encargado asignado.</span>';
}

// Edificaciones
$filas = '';
foreach ($d['edificaciones'] as $e) {
    $estadoTxt = $e['completado'] ? 'Levantamiento completo' : 'En levantamiento';
    $avance = $e['completado'] ? $e['avance'] . '%' : '—';
    $filas .= '<tr>
        <td style="text-align:center;"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . $e['color'] . ';"></span></td>
        <td>' . esc($e['nombre']) . '</td>
        <td>' . esc($estadoTxt) . '</td>
        <td style="text-align:center;">' . $avance . '</td>
    </tr>';
}
if (!$filas) $filas = '<tr><td colspan="4" style="text-align:center;color:#888;padding:12px;">Ninguna edificación ha comenzado el levantamiento.</td></tr>';

$card = function($lbl,$val,$color){
    return '<div style="flex:1;text-align:center;padding:12px 8px;background:' . $color . '14;border-radius:10px;border:1px solid ' . $color . '44;">
        <div style="font-size:26px;font-weight:bold;color:' . $color . ';">' . $val . '</div>
        <div style="font-size:11px;color:#555;text-transform:uppercase;">' . $lbl . '</div></div>';
};

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
* { font-family: Arial, sans-serif; }
body { margin:0; padding:28px 32px; color:#2a3140; }
h1 { color:#22366F; font-size:23px; margin:0; }
.sub { color:#767c94; font-size:13px; margin:2px 0 0; }
.linea { height:3px; background:#C9A227; width:80px; margin:10px 0; }
.box { background:#f7f9fd; border-radius:10px; padding:12px 16px; margin:14px 0; }
.tit { font-size:12px; font-weight:bold; color:#22366F; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th { background:#22366F; color:#fff; font-size:11px; padding:7px 8px; text-align:left; }
td { font-size:11px; padding:6px 8px; border-bottom:1px solid #e8ebf3; }
</style></head><body>
<h1>Parroquia ' . esc($parroquia) . '</h1>
<p class="sub">' . esc($estado) . ' · Resumen de reconstrucción</p>
<div class="linea"></div>
<div class="box"><div class="tit">Encargado</div>' . $encHtml . '</div>
<div class="tit">Edificaciones (' . $d['total'] . ' en total)</div>
<div style="display:flex;gap:10px;margin:8px 0 4px;">
    ' . $card('Rojo', $pc['rojo'], '#A61C1C')
      . $card('Amarillo', $pc['amarillo'], '#C9A227')
      . $card('Verde', $pc['verde'], '#2E7D32') . '
</div>
<p style="font-size:13px;color:#55617f;">' . $d['comenzadas'] . ' de ' . $d['total'] . ' edificaciones comenzaron el levantamiento técnico.</p>
<div class="tit" style="margin-top:14px;">Seguimiento de edificaciones</div>
<table>
<thead><tr><th style="text-align:center;">Color</th><th>Edificación</th><th>Estado</th><th style="text-align:center;">Avance</th></tr></thead>
<tbody>' . $filas . '</tbody>
</table>
<p style="font-size:10px;color:#9aa1b4;margin-top:20px;">Generado el ' . date('d/m/Y H:i') . '</p>
</body></html>';

// Generar PDF con wkhtmltopdf
$tmpHtml = tempnam(sys_get_temp_dir(), 'parr') . '.html';
$tmpPdf  = tempnam(sys_get_temp_dir(), 'parr') . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = 'wkhtmltopdf --quiet --enable-local-file-access --page-size A4 '
     . '--margin-top 12 --margin-bottom 12 --margin-left 10 --margin-right 10 '
     . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>/dev/null';
exec($cmd);

if (!is_file($tmpPdf) || filesize($tmpPdf) === 0) {
    @unlink($tmpHtml);
    http_response_code(500);
    exit('No se pudo generar el PDF.');
}

$nombre = 'Parroquia_' . preg_replace('/[^A-Za-z0-9]+/', '_', $parroquia) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($tmpPdf));
readfile($tmpPdf);
@unlink($tmpHtml);
@unlink($tmpPdf);
