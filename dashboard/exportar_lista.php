<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('dashboard', 'ver');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require phpoffice/phpspreadsheet');
    header('Location: ' . APP_URL_BASE . 'dashboard/index.php');
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ---------------------------------------------------------------------
// Se reconstruyen EXACTAMENTE los mismos filtros que usa el dashboard
// (dashboard/api_kpis.php), para que "descargar la lista" siempre
// corresponda a lo que la persona está viendo en pantalla en ese momento.
// ---------------------------------------------------------------------
$catalogo = catalogoDecisionFinal();

$condiciones = [];
$params = [];
aplicarScopeEstado($condiciones, $params); // fuerza el estado del usuario si no es master

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

$usoFiltro = trim((string)($_GET['uso'] ?? ''));
if ($usoFiltro !== '') {
    $condiciones[] = 'uso_edificacion = :uso';
    $params['uso'] = $usoFiltro;
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
    SELECT codigo, nombre_edificio, estado, municipio, parroquia, uso_edificacion, tipo_estructural,
           decision_final, fecha_inspeccion, ing1_nombre, ing1_cedula,
           familias, hombres, mujeres, ninos, adultos_tercera_edad, gestantes, movilidad_reducida, mascotas,
           latitud, longitud, observaciones
    FROM inspecciones
    $whereSql
    ORDER BY fecha_inspeccion DESC, codigo DESC
");
$stmt->execute($params);
$filas = $stmt->fetchAll();

// ---------------------------------------------------------------------
// Construcción del Excel
// ---------------------------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Inspecciones filtradas');

$encabezados = [
    'Código', 'Nombre del edificio', 'Estado', 'Municipio', 'Parroquia', 'Uso', 'Tipo estructural',
    'Decisión final', 'Fecha de inspección', 'Inspector', 'Cédula inspector',
    'Familias', 'Hombres', 'Mujeres', 'Niños', '3ra edad', 'Gestantes', 'Movilidad reducida', 'Mascotas',
    'Latitud', 'Longitud', 'Observaciones',
];
foreach ($encabezados as $i => $h) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $celda = $sheet->setCellValue($col . '1', $h);
    $sheet->getStyle($col . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($col . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('22366F');
}

$fila = 2;
foreach ($filas as $r) {
    $meta = $catalogo[$r['decision_final']] ?? ['corto' => $r['decision_final']];
    $valores = [
        $r['codigo'], $r['nombre_edificio'], $r['estado'], $r['municipio'], $r['parroquia'],
        $r['uso_edificacion'], $r['tipo_estructural'], $meta['corto'], $r['fecha_inspeccion'],
        $r['ing1_nombre'], $r['ing1_cedula'],
        (int)$r['familias'], (int)$r['hombres'], (int)$r['mujeres'], (int)$r['ninos'],
        (int)$r['adultos_tercera_edad'], (int)$r['gestantes'], (int)$r['movilidad_reducida'], (int)$r['mascotas'],
        $r['latitud'], $r['longitud'], $r['observaciones'],
    ];
    foreach ($valores as $i => $v) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue($col . $fila, $v);
    }
    $fila++;
}

$ultimaFila = $fila - 1;
$ultimaCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
if ($ultimaFila >= 2) {
    $sheet->setAutoFilter("A1:{$ultimaCol}{$ultimaFila}");
}
foreach (range('A', $ultimaCol) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A2');

// Nombre de archivo descriptivo según los filtros activos, para que quede
// claro qué es cada descarga sin tener que abrirla.
$partesNombre = ['inspecciones'];
if ($estadoFiltro) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $estadoFiltro);
if ($municipioFiltro) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $municipioFiltro);
if ($parroquiaFiltro) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $parroquiaFiltro);
if ($usoFiltro) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $usoFiltro);
if ($decisionFiltroClave) $partesNombre[] = preg_replace('/[^A-Za-z0-9]+/', '_', $decisionFiltroCorto);
$partesNombre[] = date('Y-m-d');
$nombreArchivo = implode('_', $partesNombre) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
