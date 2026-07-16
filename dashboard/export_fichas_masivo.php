<?php
/**
 * PDF MASIVO DE FICHAS RESUMIDAS
 * ------------------------------------------------------------------
 * Genera un PDF con una ficha por edificación, UNA HOJA por ficha.
 * Estructura de agrupación:
 *      1) CON FOTOS  /  SIN FOTOS
 *      2) dentro de cada bloque, por PARROQUIA
 *
 * Se filtra por estado y/o parroquia (parámetros GET: estado, parroquia).
 * Cada ficha resume lo esencial: identificación, decisión, ubicación,
 * datos sociales, datos técnicos y hasta 4 fotos.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Generar muchas fichas con fotos consume memoria y tiempo: se amplían los
// límites solo para este proceso (las fotos ya se incrustan reducidas).
@ini_set('memory_limit', '512M');
@set_time_limit(300);

requierePermiso('import_export', 'ver');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require dompdf/dompdf');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}
require_once $autoload;
use Dompdf\Dompdf;
use Dompdf\Options;

// ------------------------------------------------------------------
// Filtros (los mismos que usa el dashboard)
// ------------------------------------------------------------------
$estadoFiltro    = trim((string)($_GET['estado'] ?? ''));
$municipioFiltro = trim((string)($_GET['municipio'] ?? ''));
$parroquiaFiltro = trim((string)($_GET['parroquia'] ?? ''));
$usoFiltro       = trim((string)($_GET['uso'] ?? ''));
$fotosFiltro     = trim((string)($_GET['fotos'] ?? ''));
$decisionFiltro  = trim((string)($_GET['decision'] ?? ''));

$condiciones = [];
$params = [];
if ($estadoFiltro !== '') {
    $condiciones[] = 'i.estado = :estado';
    $params['estado'] = $estadoFiltro;
}
if ($municipioFiltro !== '') {
    $condiciones[] = 'i.municipio = :municipio';
    $params['municipio'] = $municipioFiltro;
}
if ($parroquiaFiltro !== '') {
    $condiciones[] = 'i.parroquia = :parroquia';
    $params['parroquia'] = $parroquiaFiltro;
}
if ($usoFiltro === '__SIN_USO__') {
    $condiciones[] = "(i.uso_edificacion IS NULL OR TRIM(i.uso_edificacion) = '')";
} elseif ($usoFiltro !== '') {
    $condiciones[] = 'i.uso_edificacion = :uso';
    $params['uso'] = $usoFiltro;
}
// El filtro por decisión llega como el texto corto (ej. "Acceso Permitido");
// se traduce a la clave larga del catálogo.
if ($decisionFiltro !== '') {
    foreach (catalogoDecisionFinal() as $clave => $meta) {
        if ($meta['corto'] === $decisionFiltro) { $condiciones[] = 'i.decision_final = :dec'; $params['dec'] = $clave; break; }
    }
}
// El filtro por fotos se resuelve con EXISTS sobre inspeccion_fotos.
if (($fotosFiltro === 'con' || $fotosFiltro === 'sin') && tablaFotosExiste()) {
    $op = ($fotosFiltro === 'con') ? 'EXISTS' : 'NOT EXISTS';
    $condiciones[] = "$op (SELECT 1 FROM inspeccion_fotos ff WHERE ff.inspeccion_id = i.id)";
}
$whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

// ------------------------------------------------------------------
// Datos de las inspecciones
// ------------------------------------------------------------------
$stmt = db()->prepare("
    SELECT i.*, u.nombre_completo AS creado_por_nombre
    FROM inspecciones i
    LEFT JOIN usuarios u ON u.id = i.creado_por
    $whereSql
    ORDER BY i.parroquia, i.nombre_edificio
");
$stmt->execute($params);
$inspecciones = $stmt->fetchAll();

if (!$inspecciones) {
    flash('error', 'No hay inspecciones que coincidan con el filtro seleccionado.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

$catalogo = catalogoDecisionFinal();
$hayTablaFotos = tablaFotosExiste();

// ------------------------------------------------------------------
// Traer TODAS las fotos de las inspecciones filtradas en UNA sola consulta
// (en vez de una consulta por inspección). Con cientos de fichas, esto evita
// cientos de idas a la base de datos y acelera mucho la generación.
// ------------------------------------------------------------------
$fotosPorInspeccion = [];
if ($hayTablaFotos && $inspecciones) {
    $ids = array_map(fn($x) => (int)$x['id'], $inspecciones);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stF = db()->prepare(
        "SELECT inspeccion_id, categoria, ruta, nombre_original
         FROM inspeccion_fotos WHERE inspeccion_id IN ($in) ORDER BY creado_en ASC"
    );
    $stF->execute($ids);
    foreach ($stF->fetchAll() as $foto) {
        $fotosPorInspeccion[(int)$foto['inspeccion_id']][] = $foto;
    }
}

// ------------------------------------------------------------------
// Clasificar: CON FOTOS / SIN FOTOS  ->  por PARROQUIA
// ------------------------------------------------------------------
$grupos = ['con' => [], 'sin' => []];
foreach ($inspecciones as $insp) {
    $listaFotos = $fotosPorInspeccion[(int)$insp['id']] ?? [];
    $bloque = $listaFotos ? 'con' : 'sin';
    $pq = trim((string)($insp['parroquia'] ?? '')) ?: 'Sin parroquia';
    $grupos[$bloque][$pq][] = ['insp' => $insp, 'fotos' => $listaFotos];
}
// Ordenar las parroquias alfabéticamente dentro de cada bloque.
foreach ($grupos as $b => $_) {
    ksort($grupos[$b], SORT_NATURAL | SORT_FLAG_CASE);
}

// ------------------------------------------------------------------
// Helpers de presentación
// ------------------------------------------------------------------
/**
 * Convierte una foto en data URI para incrustarla en el PDF, REDIMENSIONÁNDOLA
 * a miniatura. Para acelerar, la versión reducida se GUARDA EN CACHÉ en disco:
 * la primera vez se genera, y las siguientes se lee directamente (instantáneo),
 * en vez de re-procesar la foto original cada vez que se genera un PDF.
 */
function fotoDataUri(array $f): ?string
{
    $rutaAbs = __DIR__ . '/../' . ($f['ruta'] ?? '');
    if (!is_file($rutaAbs) || filesize($rutaAbs) === 0) return null;

    // Si no está disponible GD, método simple (sin redimensionar).
    if (!function_exists('imagecreatetruecolor')) {
        $mime = mime_content_type($rutaAbs) ?: 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($rutaAbs));
    }

    // --- Caché en disco ---
    // La miniatura se identifica por la ruta + fecha de modificación del archivo,
    // así que si la foto cambia, se regenera; si no, se reutiliza.
    $cacheDir = __DIR__ . '/../storage/cache_fichas';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $clave = md5($rutaAbs . '|' . @filemtime($rutaAbs)) . '.jpg';
    $cacheFile = $cacheDir . '/' . $clave;

    if (is_file($cacheFile)) {
        return 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($cacheFile));
    }

    $info = @getimagesize($rutaAbs);
    if (!$info) return null;
    [$ancho, $alto] = $info;
    $tipo = $info[2];

    $src = null;
    switch ($tipo) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($rutaAbs); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($rutaAbs);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($rutaAbs);  break;
        case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($rutaAbs); break;
    }
    if (!$src) return null;

    // Escalar para que el lado mayor sea como máximo 380 px (suficiente para
    // la miniatura de la ficha; más pequeño = más rápido y más liviano).
    $max = 380;
    $escala = min(1, $max / max($ancho, $alto));
    $nw = max(1, (int)round($ancho * $escala));
    $nh = max(1, (int)round($alto * $escala));

    $dst = imagecreatetruecolor($nw, $nh);
    $blanco = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $blanco);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ancho, $alto);
    imagedestroy($src);

    // Exportar como JPEG de calidad media.
    ob_start();
    imagejpeg($dst, null, 68);
    $datos = ob_get_clean();
    imagedestroy($dst);

    // Guardar en caché para la próxima vez (así no se re-procesa la foto).
    @file_put_contents($cacheFile, $datos);

    return 'data:image/jpeg;base64,' . base64_encode($datos);
}

/** Fila de dato "etiqueta: valor" para las tablas de la ficha. */
function filaDato(string $k, ?string $v): string
{
    $v = ($v === null || $v === '') ? '—' : $v;
    return '<tr><td class="k">' . e($k) . '</td><td class="v">' . e($v) . '</td></tr>';
}

// ------------------------------------------------------------------
// Construcción del HTML
// ------------------------------------------------------------------
$css = '
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, sans-serif; margin: 0; color: #1f2430; }

    /* Cada ficha ocupa exactamente una hoja. Se usa un alto fijo un poco menor
       que el A4 útil para que el contenido nunca empuje una segunda página. */
    .ficha { page-break-after: always; page-break-inside: avoid; padding: 24px 30px; height: 1020px; position: relative; overflow: hidden; }
    .ficha:last-child { page-break-after: auto; }

    /* Portada de sección (con fotos / sin fotos, y parroquia) */
    .portada { page-break-after: always; padding: 120px 40px 0; text-align: center; }
    .portada .big { font-size: 40px; font-weight: bold; color: #22366F; margin-bottom: 8px; }
    .portada .sub { font-size: 18px; color: #55617f; }
    .portada .linea { width: 120px; height: 4px; background: #C9A227; margin: 18px auto; }

    .cab { border-bottom: 3px solid #22366F; padding-bottom: 8px; margin-bottom: 12px; }
    .cab .codigo { font-family: DejaVu Sans Mono, monospace; font-size: 11px; color: #767c94; letter-spacing: .5px; }
    .cab h1 { font-size: 19px; margin: 3px 0 6px; color: #1f2430; line-height: 1.15; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; color: #fff; font-size: 11px; font-weight: bold; }
    .cab .ctx { float: right; text-align: right; font-size: 10px; color: #767c94; margin-top: 2px; }

    .cols { width: 100%; }
    .cols td { vertical-align: top; }
    .col-left  { width: 54%; padding-right: 14px; }
    .col-right { width: 46%; }

    .seccion-tit { font-size: 11px; font-weight: bold; color: #22366F; text-transform: uppercase;
                   letter-spacing: .6px; margin: 12px 0 5px; border-left: 3px solid #C9A227; padding-left: 7px; }

    table.datos { width: 100%; border-collapse: collapse; }
    table.datos td { padding: 3px 5px; font-size: 11px; border-bottom: 1px solid #eef0f5; }
    table.datos td.k { color: #767c94; width: 42%; }
    table.datos td.v { font-weight: bold; color: #2a3140; }

    .social-grid { width: 100%; border-collapse: collapse; margin-top: 3px; }
    .social-grid td { width: 25%; text-align: center; padding: 7px 3px; border: 1px solid #e8ebf3; }
    .social-grid .n { font-size: 17px; font-weight: bold; color: #22366F; }
    .social-grid .l { font-size: 8.5px; color: #767c94; text-transform: uppercase; }

    .fotos-wrap { margin-top: 6px; }
    .fotos-tabla { width: 100%; border-collapse: collapse; }
    .fotos-tabla td { width: 50%; padding: 3px; vertical-align: top; }
    .fotos-tabla img { width: 100%; height: 118px; object-fit: cover; border: 1px solid #d8dce6; border-radius: 3px; }
    .foto-cap { font-size: 8px; color: #888; text-align: center; margin-top: 2px; }
    .sin-fotos { border: 1px dashed #cfd4e0; border-radius: 6px; padding: 26px; text-align: center;
                 color: #9aa1b4; font-size: 12px; margin-top: 6px; }

    .pie { position: absolute; bottom: 14px; left: 30px; right: 30px; border-top: 1px solid #e2e6ef;
           padding-top: 5px; font-size: 8.5px; color: #9aa1b4; }
    .pie .der { float: right; }
</style>';

// Descripción del filtro para las portadas
$ambito = [];
if ($estadoFiltro !== '')    $ambito[] = $estadoFiltro;
if ($parroquiaFiltro !== '') $ambito[] = 'Parroquia ' . $parroquiaFiltro;
$ambitoTxt = $ambito ? implode(' · ', $ambito) : 'Todas las inspecciones';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8">' . $css . '</head><body>';

$titulosBloque = [
    'con' => ['Edificaciones CON registro fotográfico', '#22366F'],
    'sin' => ['Edificaciones SIN registro fotográfico', '#8a6d1f'],
];

foreach (['con', 'sin'] as $bloque) {
    if (empty($grupos[$bloque])) continue;

    // Contar total del bloque
    $totalBloque = 0;
    foreach ($grupos[$bloque] as $lista) $totalBloque += count($lista);

    // Portada del bloque
    [$tit, $color] = $titulosBloque[$bloque];
    $html .= '<div class="portada">'
           . '<div class="big" style="color:' . $color . ';">' . e($tit) . '</div>'
           . '<div class="linea"></div>'
           . '<div class="sub">' . e($ambitoTxt) . '<br>' . $totalBloque . ' edificaciones</div>'
           . '</div>';

    foreach ($grupos[$bloque] as $parroquia => $items) {
        foreach ($items as $item) {
            $insp  = $item['insp'];
            $fotos = $item['fotos'];
            $html .= construirFicha($insp, $fotos, $parroquia, $catalogo, $bloque);
        }
    }
}

$html .= '</body></html>';

/**
 * Construye el HTML de UNA ficha (una hoja).
 */
function construirFicha(array $r, array $fotos, string $parroquia, array $catalogo, string $bloque): string
{
    $meta = $catalogo[$r['decision_final']] ?? ['color' => '#767c94', 'corto' => $r['decision_final']];

    // Datos sociales
    $ninos    = (int)($r['ninos'] ?? 0);
    $mujeres  = (int)($r['mujeres'] ?? 0);
    $hombres  = (int)($r['hombres'] ?? 0);
    $tercera  = (int)($r['adultos_tercera_edad'] ?? 0);
    $gestantes = (int)($r['gestantes'] ?? 0);
    $movilidad = (int)($r['movilidad_reducida'] ?? 0);
    $familias  = (int)($r['familias'] ?? 0);
    $mascotas  = (int)($r['mascotas'] ?? 0);
    $totalPersonas = $ninos + $mujeres + $hombres + $tercera + $gestantes;

    $ubic = array_filter([
        $r['avenida_calle'] ?? '', $r['sector'] ?? '', $r['urbanizacion'] ?? '',
    ]);
    $ubicTxt = $ubic ? implode(', ', $ubic) : '';

    $h  = '<div class="ficha">';

    // Cabecera
    $h .= '<div class="cab">';
    $h .= '<div class="ctx">' . e($r['estado'] ?: '—') . '<br>' . e($r['municipio'] ?: '') . '</div>';
    $h .= '<div class="codigo">' . e($r['codigo']) . '</div>';
    $h .= '<h1>' . e($r['nombre_edificio']) . '</h1>';
    $h .= '<span class="badge" style="background:' . $meta['color'] . ';">' . e($meta['corto']) . '</span>';
    $h .= '</div>';

    // Dos columnas: izquierda (datos), derecha (fotos)
    $h .= '<table class="cols"><tr>';

    // ---- Columna izquierda ----
    $h .= '<td class="col-left">';

    $h .= '<div class="seccion-tit">Ubicación</div>';
    $h .= '<table class="datos">';
    $h .= filaDato('Parroquia', $parroquia);
    $h .= filaDato('Municipio', $r['municipio'] ?? '');
    $h .= filaDato('Estado', $r['estado'] ?? '');
    if ($ubicTxt) $h .= filaDato('Dirección', $ubicTxt);
    $h .= filaDato('Comunidad', $r['nombre_comunidad'] ?? '');
    $h .= '</table>';

    $h .= '<div class="seccion-tit">Datos técnicos</div>';
    $h .= '<table class="datos">';
    $h .= filaDato('Uso', $r['uso_edificacion'] ?? '');
    $h .= filaDato('Tipo estructural', $r['tipo_estructural'] ?? '');
    $h .= filaDato('N.º de pisos', ($r['num_pisos'] ?? '') !== '' ? (string)(int)$r['num_pisos'] : '');
    $h .= filaDato('Apartamentos', ($r['cantidad_apartamentos'] ?? '') !== '' ? (string)(int)$r['cantidad_apartamentos'] : '');
    $h .= filaDato('Año construcción', ($r['anio_construccion'] ?? '') ? (string)(int)$r['anio_construccion'] : '');
    $h .= filaDato('Fecha inspección', $r['fecha_inspeccion'] ?? '');
    $h .= '</table>';

    $h .= '<div class="seccion-tit">Población</div>';
    $h .= '<table class="social-grid"><tr>'
        . '<td><div class="n">' . $totalPersonas . '</div><div class="l">Personas</div></td>'
        . '<td><div class="n">' . $familias . '</div><div class="l">Familias</div></td>'
        . '<td><div class="n">' . $ninos . '</div><div class="l">Niños</div></td>'
        . '<td><div class="n">' . $tercera . '</div><div class="l">3.ª edad</div></td>'
        . '</tr><tr>'
        . '<td><div class="n">' . $gestantes . '</div><div class="l">Gestantes</div></td>'
        . '<td><div class="n">' . $movilidad . '</div><div class="l">Mov. red.</div></td>'
        . '<td><div class="n">' . $hombres . '</div><div class="l">Hombres</div></td>'
        . '<td><div class="n">' . $mujeres . '</div><div class="l">Mujeres</div></td>'
        . '</tr></table>';

    // Observaciones breves si hay espacio (recortadas)
    $obs = trim((string)($r['observaciones'] ?? ''));
    if ($obs !== '') {
        if (mb_strlen($obs) > 240) $obs = mb_substr($obs, 0, 237) . '…';
        $h .= '<div class="seccion-tit">Observaciones</div>';
        $h .= '<div style="font-size:10.5px;color:#4a5160;line-height:1.35;">' . e($obs) . '</div>';
    }

    $h .= '</td>';

    // ---- Columna derecha: fotos ----
    $h .= '<td class="col-right">';
    $h .= '<div class="seccion-tit">Registro fotográfico</div>';
    if ($fotos) {
        // Máximo 4 fotos, 2 por fila.
        $sel = array_slice($fotos, 0, 4);
        $h .= '<div class="fotos-wrap"><table class="fotos-tabla">';
        $i = 0;
        foreach ($sel as $f) {
            if ($i % 2 === 0) $h .= '<tr>';
            $src = fotoDataUri($f);
            $h .= '<td>';
            if ($src) {
                $h .= '<img src="' . $src . '">';
                $cap = $f['categoria'] ?? '';
                if ($cap) $h .= '<div class="foto-cap">' . e($cap) . '</div>';
            } else {
                $h .= '<div class="sin-fotos" style="padding:40px 10px;">Imagen no disponible</div>';
            }
            $h .= '</td>';
            $i++;
            if ($i % 2 === 0) $h .= '</tr>';
        }
        if ($i % 2 !== 0) $h .= '<td></td></tr>';
        $h .= '</table>';
        if (count($fotos) > 4) {
            $h .= '<div class="foto-cap" style="text-align:left;margin-top:4px;">+ ' . (count($fotos) - 4) . ' fotos más en el sistema</div>';
        }
        $h .= '</div>';
    } else {
        $h .= '<div class="sin-fotos">Sin registro fotográfico</div>';
    }
    $h .= '</td>';

    $h .= '</tr></table>';

    // Pie
    $h .= '<div class="pie">'
        . 'Inspección de Edificaciones Post-Sismo'
        . '<span class="der">' . e($bloque === 'con' ? 'Con fotos' : 'Sin fotos') . ' · ' . e($parroquia) . '</span>'
        . '</div>';

    $h .= '</div>'; // .ficha
    return $h;
}

// ------------------------------------------------------------------
// Render
// ------------------------------------------------------------------
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nombre = 'fichas_' . ($estadoFiltro ?: 'todas');
if ($parroquiaFiltro !== '') $nombre .= '_' . $parroquiaFiltro;
$nombre = preg_replace('/[^A-Za-z0-9_]+/', '_', $nombre);

$dompdf->stream($nombre . '.pdf', ['Attachment' => true]);
exit;
