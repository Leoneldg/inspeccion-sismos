<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('import_export', 'ver');

$type = $_GET['type'] ?? 'excel';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require phpoffice/phpspreadsheet');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if ($type === 'excel') {
    $stmt = db()->query('SELECT * FROM inspecciones');
    $all = $stmt->fetchAll();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    if (count($all) === 0) {
        flash('error', 'No hay registros en la tabla inspecciones.');
        header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
        exit;
    }

    // cabeceras
    $cols = array_keys($all[0]);
    foreach ($cols as $index => $c) {
        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($columnLetter . '1', $c);
    }

    $rowNum = 2;
    foreach ($all as $r) {
        foreach ($cols as $index => $c) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($columnLetter . $rowNum, $r[$c]);
        }
        $rowNum++;
    }

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="inspecciones_export.xlsx"');
    $writer->save('php://output');
    exit;
}

flash('error', 'Tipo de exportación no soportado.');
header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
exit;
