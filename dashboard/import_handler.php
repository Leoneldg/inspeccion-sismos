<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('import_export', 'editar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Método no permitido.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
    flash('error', 'No se recibió el archivo Excel.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

// Composer autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error', 'No se encontró Composer autoload. Instale dependencias: composer require phpoffice/phpspreadsheet');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpDate;

$tmp = $_FILES['excel']['tmp_name'];
try {
    $reader = IOFactory::createReaderForFile($tmp);
    $spreadsheet = $reader->load($tmp);
} catch (Exception $e) {
    flash('error', 'Error al leer el archivo Excel: ' . $e->getMessage());
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);
if (count($rows) < 2) {
    flash('error', 'El archivo Excel no contiene filas de datos.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

// Obtener cabecera
$header = array_shift($rows);
// normalizar nombres de cabecera
$normalized = [];
foreach ($header as $col => $val) {
    $h = trim((string)$val);
    $h = strtolower($h);
    $h = preg_replace('/[^a-z0-9_]/i', '_', $h);
    $h = trim($h, '_');
    $normalized[$col] = $h;
}

// Obtener columnas válidas desde la base
$colsStmt = db()->query("SHOW COLUMNS FROM inspecciones");
$cols = $colsStmt->fetchAll();
$validCols = [];
$jsonCols = [];
foreach ($cols as $c) {
    $validCols[] = $c['Field'];
    if (stripos($c['Type'], 'json') !== false) $jsonCols[] = $c['Field'];
}

// Mapear índices del Excel a columnas de la tabla
$map = [];
foreach ($normalized as $colLetter => $name) {
    if (in_array($name, $validCols, true)) {
        $map[$colLetter] = $name;
        continue;
    }
    // intento: buscar coincidencia ignorando guiones/guiones bajos
    foreach ($validCols as $vc) {
        if (strtolower(str_replace(['-', ' '], ['_', '_'], $vc)) === $name) {
            $map[$colLetter] = $vc;
            break;
        }
    }
}

if (empty($map)) {
    flash('error', 'No se encontraron columnas mapeables entre el Excel y la tabla inspecciones.');
    header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
    exit;
}

$inserted = 0;
$errors = [];

foreach ($rows as $rIndex => $row) {
    $data = [];
    foreach ($map as $colLetter => $dbcol) {
        $val = $row[$colLetter];
        // manejar fechas de Excel
        if (in_array($dbcol, ['fecha_inspeccion'], true)) {
            if (is_numeric($val)) {
                try { $dt = PhpDate::excelToDateTimeObject($val); $val = $dt->format('Y-m-d'); } catch (Exception $e) {}
            } else {
                $val = trim((string)$val);
                if ($val === '') $val = null;
                else {
                    try { $d = new DateTime($val); $val = $d->format('Y-m-d'); } catch (Exception $e) {}
                }
            }
        }
        // JSON columns: intentar decodificar o convertir 'k:v;k2:v2' a JSON
        if (in_array($dbcol, $jsonCols, true) && $val !== null && $val !== '') {
            $txt = trim((string)$val);
            $json = json_decode($txt, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $val = json_encode($json, JSON_UNESCAPED_UNICODE);
            } else {
                // intentar convertir formato simple "k:v;k2:v2"
                $pairs = preg_split('/[;|,]+/', $txt);
                $obj = [];
                foreach ($pairs as $p) {
                    if (strpos($p, ':') !== false) {
                        [$k,$v] = array_map('trim', explode(':', $p, 2));
                        if ($k !== '') $obj[$k] = $v;
                    }
                }
                if ($obj) $val = json_encode($obj, JSON_UNESCAPED_UNICODE);
            }
        }
        $data[$dbcol] = $val;
    }

    // campos excluidos a insertar: id, creado_en, actualizado_en
    unset($data['id'], $data['creado_en'], $data['actualizado_en']);

    if (empty($data)) continue;

    // construir sentencia preparada
    $colsList = implode(', ', array_map(function($c){return "`$c`";}, array_keys($data)));
    $placeholders = implode(', ', array_map(function($c){return ":$c";}, array_keys($data)));
    $sql = "INSERT INTO inspecciones ($colsList) VALUES ($placeholders)";
    $stmt = db()->prepare($sql);
    try {
        $exec = [];
        foreach ($data as $k => $v) $exec[$k] = ($v === '') ? null : $v;
        $stmt->execute($exec);
        $inserted++;
    } catch (Exception $e) {
        $errors[] = "Fila " . ($rIndex+2) . ": " . $e->getMessage();
    }
}

$msg = "Importación finalizada. Filas insertadas: $inserted.";
if ($errors) $msg .= ' Errores: ' . implode(' | ', array_slice($errors,0,5));
flash('success', $msg);
header('Location: ' . APP_URL_BASE . 'dashboard/import_export.php');
exit;
