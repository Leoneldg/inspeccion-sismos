<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

// Estados y parroquias que realmente tienen inspecciones (para los selectores
// del PDF masivo de fichas).
$estadosConDatos = db()->query(
    "SELECT DISTINCT estado FROM inspecciones WHERE estado IS NOT NULL AND estado <> '' ORDER BY estado"
)->fetchAll(PDO::FETCH_COLUMN);
$parroquiasPorEstado = [];
foreach (db()->query(
    "SELECT DISTINCT estado, parroquia FROM inspecciones
     WHERE parroquia IS NOT NULL AND parroquia <> '' ORDER BY parroquia"
)->fetchAll() as $row) {
    $parroquiasPorEstado[$row['estado']][] = $row['parroquia'];
}

// Manejo de errores / excepciones: si APP_DEBUG está activo, mostrar traza
if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    set_error_handler(function($sev, $msg, $file, $line){
        throw new ErrorException($msg, 0, $sev, $file, $line);
    });
    set_exception_handler(function(Throwable $e){
        http_response_code(500);
        echo '<h2>Error interno</h2><pre>' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        exit;
    });
}

requierePermiso('import_export', 'ver');

$pageTitle = 'Importar / Exportar datos';
$pageSubtitle = 'Módulo independiente para importar Excel y exportar Excel/PDF';
$activeModule = 'import_export';

include __DIR__ . '/../includes/header.php';
?>

<div class="flex justify-between items-center" style="margin-bottom:16px;">
    <h1>Importar / Exportar</h1>
    <div class="text-sm text-muted">Módulo independiente: no modifica archivos existentes.</div>
</div>

<div class="card" style="max-width:920px;">
    <div class="card-body">
        <h3>Importar archivo Excel (.xlsx)</h3>
        <p class="help-text">El archivo debe tener una fila de cabecera con nombres de columnas que coincidan (o se mapeen) con las columnas de la tabla <strong>inspecciones</strong>. Las columnas obligatorias mínimas: <em>codigo, ing1_nombre, ing1_cedula, nombre_edificio, fecha_inspeccion, parroquia</em>.</p>
        <form action="import_handler.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="file" name="excel" accept=".xlsx,.xls" required>
            <div style="margin-top:8px;"><button class="btn btn-primary">Subir e importar</button></div>
        </form>

        <hr>

        <h3>Exportar a Excel</h3>
        <p class="help-text">Exporta toda la tabla <strong>inspecciones</strong> a un archivo Excel descargable.</p>
        <a class="btn btn-outline" href="export_handler.php?type=excel">Exportar Excel</a>

        <hr>

        <h3>Exportar ficha técnica (PDF)</h3>
        <p class="help-text">Abra la vista de una inspección y use el botón proporcionar abajo para generar un PDF de la ficha técnica.</p>
        <p class="text-muted">Generar PDF para inspecciones individuales usando <code>export_pdf.php?id=XX</code>.</p>

        <hr>

        <h3>PDF masivo de fichas resumidas</h3>
        <p class="help-text">
            Genera un PDF con una ficha por edificación (una hoja cada una), agrupadas
            primero en <strong>con fotos</strong> / <strong>sin fotos</strong> y luego por parroquia.
            Elija el ámbito a exportar.
        </p>
        <form action="export_fichas_masivo.php" method="get" target="_blank" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado</label>
                <select name="estado" id="fm-estado" class="form-control" style="width:210px;">
                    <option value="">— Seleccione un estado —</option>
                    <?php foreach ($estadosConDatos as $est): ?>
                        <option value="<?= e($est) ?>"><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Parroquia (opcional)</label>
                <select name="parroquia" id="fm-parroquia" class="form-control" style="width:210px;">
                    <option value="">Todas las del estado</option>
                </select>
            </div>
            <button class="btn btn-primary"><i class="bi bi-file-earmark-pdf"></i> Generar PDF</button>
        </form>
        <p class="text-muted" style="margin-top:6px;">Sugerencia: para muchos edificios el PDF puede tardar unos segundos en generarse.</p>

        <hr>

        <h3>Limpiar inspecciones (borrado por fecha)</h3>
        <p class="help-text">
            Filtre inspecciones por fecha, seleccione varias y elimínelas. Útil para
            borrar datos de prueba. <strong>Acción irreversible</strong>; solo el
            superadministrador puede ejecutarla.
        </p>
        <a href="<?= APP_URL_BASE ?>dashboard/limpiar_inspecciones.php" class="btn btn-outline">
            <i class="bi bi-trash3"></i> Abrir herramienta de limpieza
        </a>

        <script>
            // Parroquias disponibles por estado (solo las que tienen inspecciones).
            const PARROQUIAS_POR_ESTADO = <?= json_encode($parroquiasPorEstado, JSON_UNESCAPED_UNICODE) ?>;
            const selEstado = document.getElementById('fm-estado');
            const selParroquia = document.getElementById('fm-parroquia');
            selEstado.addEventListener('change', function () {
                const lista = PARROQUIAS_POR_ESTADO[this.value] || [];
                selParroquia.innerHTML = '<option value="">Todas las del estado</option>'
                    + lista.map(p => '<option value="' + p.replace(/"/g, '&quot;') + '">' + p + '</option>').join('');
            });
        </script>
    </div>
</div>

<div style="margin-top:18px;">
    <h4>Dependencias</h4>
    <ul>
        <li>Instale PhpSpreadsheet para Excel: <code>composer require phpoffice/phpspreadsheet</code></li>
        <li>Instale Dompdf para PDF (opcional): <code>composer require dompdf/dompdf</code></li>
    </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php';
