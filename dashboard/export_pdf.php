<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

// Acceso público vía el QR impreso en la ficha (sin sesión iniciada), solo
// si el token coincide exactamente con el de ESTE id. Cualquier otro caso
// (sin token, o token inválido) exige la sesión/permiso normal de la app.
$accesoPorQr = $id > 0 && $token !== '' && hash_equals(tokenPdfPublico($id), $token);
if (!$accesoPorQr) {
    requierePermiso('import_export', 'ver');
}

if (!$id) {
    flash('error', 'ID de inspección requerido.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require dompdf/dompdf');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}
require_once $autoload;

use Dompdf\Dompdf;

$stmt = db()->prepare('SELECT i.*, u.nombre_completo as creado_por_nombre FROM inspecciones i LEFT JOIN usuarios u ON u.id = i.creado_por WHERE i.id = :id');
$stmt->execute(['id' => $id]);
$r = $stmt->fetch();
if (!$r) {
    flash('error', 'Registro no encontrado.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

// Obtener fotos y convertir a data URIs para incrustar en el PDF.
// Dompdf no soporta display:flex, así que las fotos se colocan en una tabla
// (3 por fila) para que salgan una al lado de la otra de forma confiable.
$fotosPorFila = 3;
$fotosAgrupadas = tablaFotosExiste() ? obtenerFotosInspeccion($id) : [];
$fotosHtml = '';
foreach ($fotosAgrupadas as $categoria => $lista) {
    $fotosHtml .= '<h4 style="margin:12px 0 6px;">' . e($categoria) . '</h4>';
    $fotosHtml .= '<table style="width:100%;border-collapse:collapse;margin-bottom:8px;"><tr>';
    $col = 0;
    foreach ($lista as $f) {
        if ($col > 0 && $col % $fotosPorFila === 0) {
            $fotosHtml .= '</tr><tr>';
        }
        $anchoCelda = round(100 / $fotosPorFila, 4);
        $fotosHtml .= '<td style="width:' . $anchoCelda . '%;padding:4px;vertical-align:top;text-align:center;">';
        $rutaAbs = __DIR__ . '/../' . $f['ruta'];
        if (is_file($rutaAbs) && filesize($rutaAbs) > 0) {
            $mime = mime_content_type($rutaAbs) ?: 'image/jpeg';
            $data = base64_encode(file_get_contents($rutaAbs));
            $src = 'data:' . $mime . ';base64,' . $data;
            $fotosHtml .= '<div style="border:1px solid #ddd;padding:4px;">'
                . '<img src="' . $src . '" style="width:100%;height:auto;">'
                . '<div style="font-size:11px;margin-top:4px;word-wrap:break-word;">' . e($f['nombre_original']) . '</div>'
                . '</div>';
        } else {
            $fotosHtml .= '<div style="height:90px;border:1px solid #ddd;background:#f7f7f7;color:#888;font-size:12px;padding-top:36px;">Archivo no encontrado</div>';
        }
        $fotosHtml .= '</td>';
        $col++;
    }
    // Rellenar las celdas restantes de la última fila para que la tabla no se deforme.
    while ($col % $fotosPorFila !== 0) {
        $fotosHtml .= '<td style="width:' . round(100 / $fotosPorFila, 4) . '%;"></td>';
        $col++;
    }
    $fotosHtml .= '</tr></table>';
}

$danosEst = json_decode($r['danos_estructurales'] ?? '{}', true) ?: [];
$danosNo  = json_decode($r['danos_no_estructurales'] ?? '{}', true) ?: [];
$extra    = json_decode($r['datos_adicionales'] ?? '{}', true) ?: [];
$nivelesDano = catalogoNivelDano();
$elementosEstruct = catalogoElementosEstructurales();
$elementosNoEstruct = catalogoElementosNoEstructurales();

ob_start();
?>
<html><head><meta charset="utf-8"><style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#222;margin:0;padding:0;}
.page{padding:24px;}
h1{font-size:18px;margin-bottom:4px;}
h2{font-size:14px;margin:18px 0 8px;}
.table{width:100%;border-collapse:collapse;margin-bottom:12px;}
.table th,.table td{padding:6px;border:1px solid #bbb;vertical-align:top;text-align:left;}
.table th{background:#f3f3f3;}
.section{margin-top:18px;}
.photo-grid{display:flex;flex-wrap:wrap;gap:8px;}
.photo-item{width:140px;border:1px solid #ddd;padding:4px;text-align:center;}
.photo-item img{max-width:100%;height:auto;}
.photo-caption{font-size:11px;margin-top:4px;}
</style></head><body>
<div class="page">
<h1>Ficha técnica de inspección</h1>
<p><strong>Código:</strong> <?= e($r['codigo']) ?> | <strong>Fecha:</strong> <?= e($r['fecha_inspeccion']) ?> | <strong>Decisión:</strong> <?= e($r['decision_final']) ?></p>

<div class="section">
    <h2>Identificación y ubicación</h2>
    <table class="table">
        <tr><th>Nombre del edificio</th><td><?= e($r['nombre_edificio'] ?: '—') ?></td></tr>
        <tr><th>Fecha de inspección</th><td><?= e($r['fecha_inspeccion'] ?: '—') ?></td></tr>
        <tr><th>Horario</th><td><?= e(($r['hora_inicio'] ?: '—') . ' a ' . ($r['hora_culminacion'] ?: '—')) ?></td></tr>
        <tr><th>N° de pisos / sótanos / semisótanos</th><td><?= e($r['num_pisos'] . ' / ' . $r['num_sotanos'] . ' / ' . $r['num_semisotanos']) ?></td></tr>
        <tr><th>Cantidad de apartamentos</th><td><?= e($r['cantidad_apartamentos']) ?></td></tr>
        <tr><th>Parroquia</th><td><?= e($r['parroquia'] ?: '—') ?></td></tr>
        <tr><th>Municipio / Ciudad / Estado</th><td><?= e(trim(($r['municipio'] ?: '—') . ' / ' . ($r['ciudad'] ?: '—') . ' / ' . ($r['estado'] ?: '—'))) ?></td></tr>
        <tr><th>Urbanización / Sector</th><td><?= e(trim(($r['urbanizacion'] ?: '—') . ' / ' . ($r['sector'] ?: '—'))) ?></td></tr>
        <tr><th>Avenida o calle</th><td><?= e($r['avenida_calle'] ?: '—') ?></td></tr>
        <tr><th>Coordenadas</th><td><?= e($r['latitud'] && $r['longitud'] ? $r['latitud'] . ', ' . $r['longitud'] : '—') ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Profesional responsable</h2>
    <table class="table">
        <tr><th>Nombre</th><td><?= e($r['ing1_nombre'] ?: '—') ?></td></tr>
        <tr><th>Cédula</th><td><?= e($r['ing1_cedula'] ?: '—') ?></td></tr>
        <tr><th>Teléfono</th><td><?= e($r['ing1_telefono'] ?: '—') ?></td></tr>
        <tr><th>Profesión</th><td><?= e($r['ing1_profesion'] ?: '—') ?></td></tr>
        <tr><th>N° de inscripción</th><td><?= e($r['ing1_inscripcion'] ?: '—') ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Características constructivas y riesgo</h2>
    <table class="table">
        <tr><th>Uso de la edificación</th><td><?= e($r['uso_edificacion'] ?: '—') ?></td></tr>
        <tr><th>Tipo estructural</th><td><?= e($r['tipo_estructural'] ?: '—') ?></td></tr>
        <tr><th>Materiales</th><td><?= e(($r['material_acero'] ? 'Acero, ' : '') . ($r['material_conexiones'] ? 'Conexiones, ' : '') . ($r['material_mamposteria'] ? 'Mampostería, ' : '') . ($r['material_otros'] ? 'Otros' : '')) ?><?= $r['material_otros'] ? ' / ' . e($r['material_otros_especifique'] ?: '—') : '' ?></td></tr>
        <tr><th>Colapso de la estructura</th><td><?= e($r['colapso_estructura'] ?: '—') ?></td></tr>
        <tr><th>Riesgo edificios aledaños</th><td><?= e($r['riesgo_edificios_aledanos'] ?: '—') ?></td></tr>
        <tr><th>Amenaza geológica</th><td><?= e($r['amenaza_geologica'] ?: '—') ?></td></tr>
        <tr><th>Asentamiento / Inclinación</th><td><?= e(trim(($r['asentamiento_edificio'] ?: '—') . ' / ' . ($r['inclinacion_edificio'] ?: '—'))) ?></td></tr>
        <tr><th>Requiere inspección interna</th><td><?= e($r['requiere_inspeccion_interna'] ?: '—') ?></td></tr>
        <tr><th>Requiere intervención</th><td><?= e($r['requiere_intervencion'] ?: '—') ?></td></tr>
        <tr><th>% Daño III / IV / V</th><td><?= e($r['pct_dano_iii'] ?: '0') ?>% / <?= e($r['pct_dano_iv'] ?: '0') ?>% / <?= e($r['pct_dano_v'] ?: '0') ?>%</td></tr>
        <tr><th>m² de losas afectadas</th><td><?= e($r['m2_losas'] ?: '—') ?></td></tr>
        <tr><th>Muros a reconstruir</th><td><?= e($r['muros_reconstruir'] ?: '—') ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Daños estructurales</h2>
    <table class="table">
        <tr><th>Elemento</th><th>Evaluación</th></tr>
        <?php foreach ($elementosEstruct as $k => $label): ?>
        <tr><td><?= e($label) ?></td><td><?= e(isset($danosEst[$k]) ? ($nivelesDano[$danosEst[$k]] ?? $danosEst[$k]) : 'Sin evaluar') ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="section">
    <h2>Daños no estructurales</h2>
    <table class="table">
        <tr><th>Elemento</th><th>Evaluación</th></tr>
        <?php foreach ($elementosNoEstruct as $k => $label): ?>
        <tr><td><?= e($label) ?></td><td><?= e(isset($danosNo[$k]) ? ($nivelesDano[$danosNo[$k]] ?? $danosNo[$k]) : 'Sin evaluar') ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="section">
    <h2>Personas y animales afectados</h2>
    <table class="table">
        <tr><th>Familias</th><td><?= e($r['familias']) ?></td></tr>
        <tr><th>Hombres</th><td><?= e($r['hombres']) ?></td></tr>
        <tr><th>Mujeres</th><td><?= e($r['mujeres']) ?></td></tr>
        <tr><th>Niños</th><td><?= e($r['ninos']) ?></td></tr>
        <tr><th>Adultos 3ra edad</th><td><?= e($r['adultos_tercera_edad']) ?></td></tr>
        <tr><th>Gestantes</th><td><?= e($r['gestantes'] ?: '—') ?></td></tr>
        <tr><th>Movilidad reducida</th><td><?= e($r['movilidad_reducida'] ?: '—') ?></td></tr>
        <tr><th>Mascotas</th><td><?= e($r['mascotas'] ?: '—') ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Datos adicionales</h2>
    <table class="table">
        <tr><th>Ascensores</th><td><?= e(!empty($extra['ascensores']) ? 'Sí' : 'No') ?></td></tr>
        <tr><th>Cantidad ascensores</th><td><?= e($extra['cant_ascensores'] ?? '—') ?></td></tr>
        <tr><th>Fuga de gas</th><td><?= e(!empty($extra['fuga_gas']) ? 'Sí' : 'No') ?></td></tr>
        <tr><th>Fallas eléctricas</th><td><?= e(!empty($extra['fallas_electricas']) ? 'Sí' : 'No') ?></td></tr>
        <tr><th>Daños en aguas</th><td><?= e(!empty($extra['danos_aguas']) ? 'Sí' : 'No') ?></td></tr>
        <tr><th>Estado del tanque</th><td><?= e($extra['estado_tanque'] ?? '—') ?></td></tr>
        <tr><th>Tiempo de acción</th><td><?= e($extra['tiempo_accion'] ?? '—') ?></td></tr>
        <tr><th>Mano de obra</th><td><?= e($extra['mano_obra'] ?? '—') ?></td></tr>
        <tr><th>Herramientas</th><td><?= e($extra['herramientas'] ?? '—') ?></td></tr>
        <tr><th>Maquinaria</th><td><?= e($extra['maquinarias'] ?? '—') ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Observaciones y recomendaciones</h2>
    <table class="table">
        <tr><th>Medidas de seguridad</th><td><?= nl2br(e($r['medidas_seguridad'] ?: '—')) ?></td></tr>
        <tr><th>Observaciones</th><td><?= nl2br(e($r['observaciones'] ?: '—')) ?></td></tr>
        <tr><th>Recomendaciones</th><td><?= nl2br(e($r['recomendaciones'] ?: '—')) ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Auditoría</h2>
    <table class="table">
        <tr><th>Código</th><td><?= e($r['codigo']) ?></td></tr>
        <tr><th>Registrado por</th><td><?= e($r['creado_por_nombre'] ?: '—') ?></td></tr>
        <tr><th>Creado</th><td><?= e($r['creado_en'] ?: '—') ?></td></tr>
        <tr><th>Última actualización</th><td><?= e($r['actualizado_en'] ?: '—') ?></td></tr>
    </table>
</div>

<?php if ($fotosHtml): ?>
    <div class="section">
        <h2>Registro fotográfico</h2>
        <?= $fotosHtml ?>
    </div>
<?php endif; ?>
</div>
</body></html>
<?php
$html = ob_get_clean();
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('ficha_inspeccion_' . $r['codigo'] . '.pdf', ['Attachment' => false]);
exit;
