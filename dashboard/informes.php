<?php
/**
 * MÓDULO INFORMES
 * ------------------------------------------------------------------
 * Centraliza las exportaciones y reportes del sistema:
 *   - Lista de inspecciones (Excel / PDF) con filtros
 *   - PDF masivo de fichas resumidas (una hoja por edificio)
 *   - Accesos a importar/exportar y a la limpieza de datos
 *
 * Reutiliza el permiso del módulo 'import_export' (ya configurado por rol),
 * de modo que no hace falta crear permisos nuevos.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('import_export', 'ver');

// Estados y parroquias con datos (para los selectores de los informes).
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

$usos = catalogoUsoEdificacion();

$pageTitle = 'Informes';
$pageSubtitle = 'Reportes y exportaciones del sistema';
$activeModule = 'informes';
include __DIR__ . '/../includes/header.php';
?>
<style>
    .inf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; }
    .inf-card { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:18px 20px; }
    .inf-card h3 { margin:0 0 4px; font-size:15px; color:#22366F; display:flex; align-items:center; gap:8px; }
    .inf-card .desc { font-size:12.5px; color:#767c94; margin:0 0 14px; line-height:1.4; }
    .inf-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .inf-row .field { margin:0; }
    .inf-row label { font-size:11px; color:#55617f; display:block; margin-bottom:3px; }
    .inf-ico { width:32px; height:32px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
</style>

<div class="inf-grid">

    <!-- 1. Lista de inspecciones (Excel / PDF) -->
    <div class="inf-card">
        <h3><span class="inf-ico" style="background:#e5f7ee;color:#2E7D32;"><i class="bi bi-table"></i></span> Lista de inspecciones</h3>
        <p class="desc">Exporta la lista de inspecciones filtrada. El PDF se divide por parroquia (o por estado a nivel nacional).</p>
        <form id="form-lista" class="inf-row" onsubmit="return false;">
            <div class="field">
                <label>Estado</label>
                <select id="li-estado" class="form-control" style="width:180px;">
                    <option value="">Todos</option>
                    <?php foreach ($estadosConDatos as $est): ?>
                        <option value="<?= e($est) ?>"><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Uso</label>
                <select id="li-uso" class="form-control" style="width:170px;">
                    <option value="">Todos</option>
                    <option value="__SIN_USO__">Sin uso</option>
                    <?php foreach ($usos as $u): ?>
                        <option value="<?= e($u) ?>"><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Fotos</label>
                <select id="li-fotos" class="form-control" style="width:150px;">
                    <option value="">Con o sin fotos</option>
                    <option value="con">Con fotos</option>
                    <option value="sin">Sin fotos</option>
                </select>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="descargarLista('excel')">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="descargarLista('pdf')">
                <i class="bi bi-file-earmark-pdf"></i> PDF
            </button>
        </form>
    </div>

    <!-- 2. PDF masivo de fichas -->
    <div class="inf-card">
        <h3><span class="inf-ico" style="background:#eaf0ff;color:#22366F;"><i class="bi bi-file-earmark-text"></i></span> Fichas resumidas (PDF)</h3>
        <p class="desc">Una ficha por edificación (una hoja cada una), agrupadas en con/sin fotos y por parroquia.</p>
        <form action="<?= APP_URL_BASE ?>dashboard/export_fichas_masivo.php" method="get" target="_blank" class="inf-row">
            <div class="field">
                <label>Estado</label>
                <select name="estado" id="fm-estado" class="form-control" style="width:180px;">
                    <option value="">— Seleccione —</option>
                    <?php foreach ($estadosConDatos as $est): ?>
                        <option value="<?= e($est) ?>"><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Parroquia (opcional)</label>
                <select name="parroquia" id="fm-parroquia" class="form-control" style="width:180px;">
                    <option value="">Todas las del estado</option>
                </select>
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Generar</button>
        </form>
    </div>

    <!-- 3. Importar / Exportar -->
    <div class="inf-card">
        <h3><span class="inf-ico" style="background:#fff4e0;color:#C9A227;"><i class="bi bi-upload"></i></span> Importar / Exportar datos</h3>
        <p class="desc">Carga masiva de inspecciones y exportación completa de la base de datos.</p>
        <a href="<?= APP_URL_BASE ?>dashboard/import_export.php" class="btn btn-outline btn-sm">
            <i class="bi bi-box-arrow-in-down"></i> Abrir
        </a>
    </div>

    <!-- 4. Limpiar inspecciones (solo master) -->
    <?php if (usuarioEsMaster() && puede('import_export', 'eliminar')): ?>
    <div class="inf-card">
        <h3><span class="inf-ico" style="background:#fdeaea;color:#A61C1C;"><i class="bi bi-trash3"></i></span> Limpiar inspecciones</h3>
        <p class="desc">Filtra por fecha y elimina inspecciones. Útil para borrar datos de prueba. Acción irreversible.</p>
        <a href="<?= APP_URL_BASE ?>dashboard/limpiar_inspecciones.php" class="btn btn-outline btn-sm">
            <i class="bi bi-trash3"></i> Abrir herramienta
        </a>
    </div>
    <?php endif; ?>

</div>

<script>
    const PARROQUIAS_POR_ESTADO = <?= json_encode($parroquiasPorEstado, JSON_UNESCAPED_UNICODE) ?>;

    // Selector dependiente estado -> parroquia (fichas masivas)
    const fmEstado = document.getElementById('fm-estado');
    const fmParroquia = document.getElementById('fm-parroquia');
    fmEstado.addEventListener('change', function () {
        const lista = PARROQUIAS_POR_ESTADO[this.value] || [];
        fmParroquia.innerHTML = '<option value="">Todas las del estado</option>'
            + lista.map(p => '<option value="' + p.replace(/"/g, '&quot;') + '">' + p + '</option>').join('');
    });

    // Descarga de la lista con los filtros elegidos
    function descargarLista(tipo) {
        const params = new URLSearchParams();
        const est = document.getElementById('li-estado').value;
        const uso = document.getElementById('li-uso').value;
        const fotos = document.getElementById('li-fotos').value;
        if (est) params.set('estado', est);
        if (uso) params.set('uso', uso);
        if (fotos) params.set('fotos', fotos);
        const base = tipo === 'pdf' ? 'exportar_lista_pdf.php' : 'exportar_lista.php';
        window.open('<?= APP_URL_BASE ?>dashboard/' + base + '?' + params.toString(), '_blank');
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
