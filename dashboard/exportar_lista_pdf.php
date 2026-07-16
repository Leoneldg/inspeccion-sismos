<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

// Listas grandes generan tablas con miles de celdas: Dompdf consume mucha
// memoria. Se amplían los límites solo para este proceso.
@ini_set('memory_limit', '512M');
@set_time_limit(300);

requierePermiso('dashboard', 'ver');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require dompdf/dompdf');
    header('Location: ' . APP_URL_BASE . 'dashboard/index.php');
    exit;
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// ---------------------------------------------------------------------
// Mismos filtros exactos que dashboard/exportar_lista.php (Excel) y que
// el propio dashboard, para que "descargar en PDF" corresponda siempre
// a lo que la persona está viendo en pantalla en ese momento.
// ---------------------------------------------------------------------
$catalogo = catalogoDecisionFinal();

$condiciones = [];
$params = [];
aplicarScopeEstado($condiciones, $params);

$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
if (usuarioEsMaster() && $estadoFiltro !== '' && $estadoFiltro !== '__NINGUNO__') {
    $condiciones[] = 'estado = :estado';
    $params['estado'] = $estadoFiltro;
}

$municipioFiltro = trim((string)($_GET['municipio'] ?? ''));
if ($municipioFiltro !== '') {
    $condiciones[] = 'municipio = :municipio';
    $params['municipio'] = $municipioFiltro;
}

$parroquiaFiltro = trim((string)($_GET['parroquia'] ?? ''));
if ($parroquiaFiltro !== '') {
    $condiciones[] = 'parroquia = :parroquia';
    $params['parroquia'] = $parroquiaFiltro;
}

$usoResultado = filtroUsoSql($_GET['uso'] ?? '', 'uso_edificacion', 'uso');
$usoFiltro = $usoResultado[2] ?? '';   // etiqueta legible
if ($usoResultado !== null) {
    $condiciones[] = $usoResultado[0];
    $params = array_merge($params, $usoResultado[1]);
}

// Filtro por presencia de fotos / archivos adjuntos. Solo aplica si la tabla
// de fotos existe; si no, se ignora para no romper la consulta.
$fotosFiltro = trim((string)($_GET['fotos'] ?? ''));
if (($fotosFiltro === 'con' || $fotosFiltro === 'sin') && tablaFotosExiste()) {
    if ($fotosFiltro === 'con') {
        $condiciones[] = 'EXISTS (SELECT 1 FROM inspeccion_fotos f WHERE f.inspeccion_id = inspecciones.id)';
    } else {
        $condiciones[] = 'NOT EXISTS (SELECT 1 FROM inspeccion_fotos f WHERE f.inspeccion_id = inspecciones.id)';
    }
}

$decisionFiltroCorto = trim((string)($_GET['decision'] ?? ''));
$decisionFiltroClave = null;
foreach ($catalogo as $clave => $meta) {
    if ($meta['corto'] === $decisionFiltroCorto) { $decisionFiltroClave = $clave; break; }
}
if ($decisionFiltroClave !== null) {
    $condiciones[] = 'decision_final = :decision';
    $params['decision'] = $decisionFiltroClave;
}

$whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

$stmt = db()->prepare("
    SELECT id, codigo, nombre_edificio, estado, municipio, parroquia, uso_edificacion,
           decision_final, fecha_inspeccion, ing1_nombre,
           (COALESCE(hombres,0)+COALESCE(mujeres,0)+COALESCE(ninos,0)+COALESCE(adultos_tercera_edad,0)+COALESCE(gestantes,0)) AS personas
    FROM inspecciones
    $whereSql
    ORDER BY fecha_inspeccion DESC, codigo DESC
");
$stmt->execute($params);
$filas = $stmt->fetchAll();

// ---------------------------------------------------------------------
// Agrupación del listado.
//   - Si NO se filtró por un estado concreto (exportación "a nivel nacional"),
//     se agrupa por ESTADO.
//   - Si ya se está viendo un estado concreto, se agrupa por PARROQUIA
//     (ej.: residencial + verdes de Distrito Capital → dividido por parroquia).
// ---------------------------------------------------------------------
$hayEstadoConcreto = ($estadoFiltro !== '' && $estadoFiltro !== '__NINGUNO__');
$campoAgrupacion   = $hayEstadoConcreto ? 'parroquia' : 'estado';
$etiquetaAgrupacion = $hayEstadoConcreto ? 'Parroquia' : 'Estado';

$grupos = [];
foreach ($filas as $f) {
    $clave = trim((string)($f[$campoAgrupacion] ?? ''));
    if ($clave === '') {
        $clave = 'Sin ' . strtolower($etiquetaAgrupacion) . ' registrada';
    }
    $grupos[$clave][] = $f;
}
// Orden alfabético de los grupos, dejando el "Sin ..." al final.
uksort($grupos, function ($a, $b) {
    $aSin = stripos($a, 'Sin ') === 0;
    $bSin = stripos($b, 'Sin ') === 0;
    if ($aSin !== $bSin) return $aSin ? 1 : -1;
    return strcasecmp($a, $b);
});

// ---------------------------------------------------------------------
// Descripción legible de los filtros activos, para el encabezado del PDF.
// ---------------------------------------------------------------------
$descripcionFiltros = [];
if ($estadoFiltro && $estadoFiltro !== '__NINGUNO__') $descripcionFiltros[] = "Estado: $estadoFiltro";
if ($municipioFiltro) $descripcionFiltros[] = "Municipio: $municipioFiltro";
if ($parroquiaFiltro) $descripcionFiltros[] = "Parroquia: $parroquiaFiltro";
if ($usoFiltro) $descripcionFiltros[] = "Uso: $usoFiltro";
if ($fotosFiltro === 'con') $descripcionFiltros[] = "Con fotos / adjuntos";
elseif ($fotosFiltro === 'sin') $descripcionFiltros[] = "Sin fotos / adjuntos";
if ($decisionFiltroClave) $descripcionFiltros[] = "Decisión: $decisionFiltroCorto";
if (!usuarioEsMaster() && estadoDelUsuario()) {
    // Ya viene reflejado en $estadoFiltro por aplicarScopeEstado(), pero se dejó
    // explícito arriba; aquí no se duplica.
}
$tituloFiltros = $descripcionFiltros ? implode('  ·  ', $descripcionFiltros) : 'Todas las inspecciones (sin filtro)';

// Conteo por decisión, para el resumen superior.
$conteoDecision = ['VERDE' => 0, 'AMARILLO' => 0, 'ROJO' => 0];
$mapaCorto = ['Acceso Permitido' => 'VERDE', 'Precaución al Entrar' => 'AMARILLO', 'Acceso No Permitido' => 'ROJO'];
foreach ($filas as $f) {
    $corto = $catalogo[$f['decision_final']]['corto'] ?? '';
    $clave = $mapaCorto[$corto] ?? null;
    if ($clave) $conteoDecision[$clave]++;
}

ob_start();
?>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 90px 28px 60px 28px; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #222; }
    header { position: fixed; top: -70px; left: 0; right: 0; height: 70px; }
    footer { position: fixed; bottom: -40px; left: 0; right: 0; height: 30px; font-size: 9px; color: #888; border-top: 1px solid #ddd; padding-top: 6px; }
    .marca { background: #22366F; color: #fff; padding: 12px 18px; border-radius: 6px; }
    .marca h1 { margin: 0; font-size: 16px; }
    .marca .sub { font-size: 10px; color: #cbd4ee; margin-top: 2px; }
    .resumen { margin-top: 8px; display: table; width: 100%; }
    .resumen .caja { display: table-cell; padding: 8px 12px; border: 1px solid #e2e2e2; border-radius: 4px; text-align: center; }
    .filtros-activos { font-size: 10px; color: #444; background: #f4f6fb; border: 1px solid #dfe4f0; border-radius: 4px; padding: 6px 10px; margin-top: 8px; }
    table.lista { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.lista th { background: #22366F; color: #fff; padding: 6px 5px; text-align: left; font-size: 9.5px; }
    table.lista td { padding: 5px 5px; border-bottom: 1px solid #e6e6e6; font-size: 9.5px; vertical-align: top; }
    table.lista tr:nth-child(even) td { background: #f7f8fb; }
    .badge { display: inline-block; padding: 2px 7px; border-radius: 8px; font-size: 8.5px; font-weight: bold; color: #fff; }
    .badge-verde { background: #2E7D32; }
    .badge-amarillo { background: #C9A227; }
    .badge-rojo { background: #A61C1C; }
    tr.grupo-cab td {
        background: #e8ecf6;
        color: #22366F;
        font-weight: bold;
        font-size: 11px;
        padding: 7px 8px;
        border-top: 2px solid #22366F;
        border-bottom: 1px solid #c3cbe0;
    }
    tr.grupo-cab td .conteo { font-weight: normal; color: #55617f; font-size: 9.5px; }
    a.link-ficha {
        display: inline-block;
        background: #22366F;
        color: #fff !important;
        text-decoration: none;
        font-size: 8.5px;
        font-weight: bold;
        padding: 3px 7px;
        border-radius: 4px;
        white-space: nowrap;
    }
</style>
</head>
<body>

<header>
    <div class="marca">
        <h1><?= e(APP_NAME) ?> — Listado de inspecciones</h1>
        <div class="sub">Generado el <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['nombre'] ?? 'Usuario') ?></div>
    </div>
</header>
<footer>
    <?= e(APP_NAME) ?> — Documento generado automáticamente, uso interno.
</footer>

<div class="filtros-activos"><strong>Filtros aplicados:</strong> <?= e($tituloFiltros) ?><br><strong>Listado dividido por:</strong> <?= e($etiquetaAgrupacion) ?><br><em style="color:#22366F;">Sugerencia: haga clic en el código o en «Ver ficha» de cualquier fila para abrir su ficha completa.</em></div>

<div class="resumen">
    <div class="caja"><strong style="font-size:15px;"><?= count($filas) ?></strong><br>Total de inspecciones</div>
    <div class="caja" style="color:#2E7D32;"><strong style="font-size:15px;"><?= $conteoDecision['VERDE'] ?></strong><br>Acceso Permitido</div>
    <div class="caja" style="color:#C9A227;"><strong style="font-size:15px;"><?= $conteoDecision['AMARILLO'] ?></strong><br>Precaución al Entrar</div>
    <div class="caja" style="color:#A61C1C;"><strong style="font-size:15px;"><?= $conteoDecision['ROJO'] ?></strong><br>Acceso No Permitido</div>
</div>

<table class="lista">
    <thead>
        <tr>
            <th>Código</th>
            <th>Edificación</th>
            <th>Estado</th>
            <th>Municipio</th>
            <th>Parroquia</th>
            <th>Uso</th>
            <th>Decisión</th>
            <th>Fecha</th>
            <th>Inspector</th>
            <th>Personas</th>
            <th>Ficha</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$filas): ?>
        <tr><td colspan="11" style="text-align:center;padding:16px;color:#888;">No hay inspecciones que coincidan con estos filtros.</td></tr>
    <?php endif; ?>
    <?php foreach ($grupos as $nombreGrupo => $filasGrupo): ?>
        <tr class="grupo-cab">
            <td colspan="11">
                <?= e($etiquetaAgrupacion) ?>: <?= e($nombreGrupo) ?>
                <span class="conteo">(<?= count($filasGrupo) ?> <?= count($filasGrupo) === 1 ? 'inspección' : 'inspecciones' ?>)</span>
            </td>
        </tr>
        <?php foreach ($filasGrupo as $f):
            $meta = $catalogo[$f['decision_final']] ?? ['corto' => $f['decision_final']];
            $claveColor = $mapaCorto[$meta['corto']] ?? '';
            $claseBadge = ['VERDE' => 'badge-verde', 'AMARILLO' => 'badge-amarillo', 'ROJO' => 'badge-rojo'][$claveColor] ?? '';
            // Enlace a la ficha individual. Se usa el token público para que
            // el enlace funcione aunque el PDF se abra sin sesión iniciada.
            $idFicha = (int)$f['id'];
            $urlFicha = urlAbsoluta('dashboard/export_pdf.php?id=' . $idFicha . '&token=' . tokenPdfPublico($idFicha));
        ?>
            <tr>
                <td><a href="<?= e($urlFicha) ?>" style="color:#22366F;font-weight:bold;text-decoration:none;"><?= e($f['codigo']) ?></a></td>
                <td><?= e($f['nombre_edificio']) ?></td>
                <td><?= e($f['estado'] ?: '—') ?></td>
                <td><?= e($f['municipio'] ?: '—') ?></td>
                <td><?= e($f['parroquia'] ?: '—') ?></td>
                <td><?= e($f['uso_edificacion'] ?: '—') ?></td>
                <td><span class="badge <?= $claseBadge ?>"><?= e($meta['corto']) ?></span></td>
                <td><?= e($f['fecha_inspeccion'] ?: '—') ?></td>
                <td><?= e($f['ing1_nombre'] ?: '—') ?></td>
                <td style="text-align:center;"><?= (int)$f['personas'] ?></td>
                <td style="text-align:center;"><a href="<?= e($urlFicha) ?>" class="link-ficha">Ver ficha &raquo;</a></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Numeración de página en el pie, sobre el documento ya renderizado.
$canvas = $dompdf->getCanvas();
$canvas->page_text(760, $canvas->get_height() - 32, 'Página {PAGE_NO} de {PAGE_COUNT}', null, 8, [0.5, 0.5, 0.5]);

$partesNombre = ['inspecciones'];
if ($estadoFiltro && $estadoFiltro !== '__NINGUNO__') $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $estadoFiltro);
if ($usoFiltro) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $usoFiltro);
$partesNombre[] = date('Y-m-d');
$nombreArchivo = implode('_', $partesNombre) . '.pdf';

$dompdf->stream($nombreArchivo, ['Attachment' => false]);
exit;
