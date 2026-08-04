<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('configuracion', 'ver');

$pageTitle    = 'Configuración del Sistema';
$pageSubtitle = 'Personalice qué secciones tiene el formulario y cómo se ve el dashboard';
$activeModule = 'configuracion';

$puedeEditar = puede('configuracion', 'editar');

if (!tablaPanelConfigExiste()) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>Esta pantalla requiere el esquema actualizado. Cargue <code>database/schema.sql</code>.</div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$secciones      = catalogoSeccionesFormulario();
$seccionesEstado = obtenerConfigFormulario();
$widgets        = obtenerConfigDashboard();
$camposKpi      = catalogoCamposKpi();
$kpisCustom     = obtenerConfigKpisCustom();
$mapaConfig     = obtenerConfigMapa();

// Para el modo personalizado: lista de inspecciones con nombre y código.
$listaInspecciones = [];
$listaSeguimiento  = [];
try {
    $listaInspecciones = db()->query(
        'SELECT id, codigo, nombre_edificio, parroquia, municipio, estado
         FROM inspecciones ORDER BY estado, municipio, nombre_edificio LIMIT 2000'
    )->fetchAll();
} catch (Throwable $e) {}
try {
    require_once __DIR__ . '/../includes/seguimiento.php';
    $listaSeguimiento = segListaEdificios([]);
} catch (Throwable $e) {}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($esAdmin ?? false): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2><i class="bi bi-sliders"></i> Administración del sistema</h2></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
            <a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-person-badge" style="font-size:19px;"></i> Usuarios
            </a>
            <a href="<?= APP_URL_BASE ?>admin/roles.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-shield-lock" style="font-size:19px;"></i> Roles y permisos
            </a>
            <a href="<?= APP_URL_BASE ?>admin/ingenieros.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-person-vcard" style="font-size:19px;"></i> Ingenieros
            </a>
            <a href="<?= APP_URL_BASE ?>admin/materiales.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-box-seam" style="font-size:19px;"></i> Materiales
            </a>
            <a href="<?= APP_URL_BASE ?>seguimiento/frentes.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-diagram-3-fill" style="font-size:19px;"></i> Frentes de trabajo
            </a>
            <?php if (usuarioEsMaster()): ?>
            <a href="<?= APP_URL_BASE ?>dashboard/import_export.php" class="nav-item" style="border:1px solid #e6e9f0;border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;color:#22366F;text-decoration:none;font-weight:600;">
                <i class="bi bi-arrow-down-up" style="font-size:19px;"></i> Importar / Exportar
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2><i class="bi bi-clipboard2-check-fill"></i> Secciones del formulario de inspección</h2></div>
    <div class="card-body">
        <p class="text-sm text-muted" style="margin-top:0;">
            Desactive las secciones que no quiera capturar en el formulario. Las secciones apagadas
            se ocultan del wizard y no se guardan datos nuevos en ellas (los datos ya guardados no se pierden).
        </p>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="guardar_formulario">
            <div class="form-grid cols-2">
                <?php foreach ($secciones as $key => $label): ?>
                <div class="check-row">
                    <input type="checkbox" name="secciones[<?= e($key) ?>]" id="sec_<?= e($key) ?>" value="1"
                        <?= !empty($seccionesEstado[$key]) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                    <label for="sec_<?= e($key) ?>"><?= e($label) ?></label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($puedeEditar): ?>
            <button class="btn btn-primary" style="margin-top:16px;"><i class="bi bi-save-fill"></i> Guardar secciones del formulario</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="bi bi-bar-chart-line-fill"></i> Widgets del dashboard</h2></div>
    <div class="card-body">
        <p class="text-sm text-muted" style="margin-top:0;">
            Controle qué tarjetas y gráficos se muestran en el dashboard, en qué orden aparecen dentro de su
            sección, y su color (o degradado). Deje el color en blanco para usar el estilo por defecto.
        </p>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="guardar_dashboard">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Widget</th>
                            <th style="text-align:center;">Visible</th>
                            <th style="text-align:center;">Orden</th>
                            <th style="text-align:center;">Color</th>
                            <th style="text-align:center;">Degradado</th>
                            <th style="text-align:center;">Color 2 (degradado)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($widgets as $w): $id = $w['id']; ?>
                        <tr>
                            <td><?= e($w['label']) ?></td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="widgets[<?= e($id) ?>][visible]" value="1"
                                    <?= !empty($w['visible']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                                    style="width:17px;height:17px;accent-color:var(--azul-700);">
                            </td>
                            <td style="text-align:center;">
                                <input type="number" min="1" name="widgets[<?= e($id) ?>][orden]" value="<?= (int)$w['orden'] ?>"
                                    class="form-control" style="width:70px;text-align:center;" <?= $puedeEditar ? '' : 'disabled' ?>>
                            </td>
                            <td style="text-align:center;">
                                <input type="color" name="widgets[<?= e($id) ?>][color]" value="<?= e($w['color'] ?: '#1e3a8a') ?>"
                                    style="width:44px;height:30px;padding:0;border:1px solid var(--gris-300);border-radius:6px;" <?= $puedeEditar ? '' : 'disabled' ?>>
                                <label style="display:inline-flex;align-items:center;gap:4px;font-weight:400;margin:0 0 0 6px;">
                                    <input type="checkbox" name="widgets[<?= e($id) ?>][sin_color]" value="1"
                                        <?= empty($w['color']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                                    <span class="text-sm text-muted">Por defecto</span>
                                </label>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="widgets[<?= e($id) ?>][gradiente]" value="1"
                                    <?= !empty($w['gradiente']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                                    style="width:17px;height:17px;accent-color:var(--azul-700);">
                            </td>
                            <td style="text-align:center;">
                                <input type="color" name="widgets[<?= e($id) ?>][color2]" value="<?= e($w['color2'] ?: '#1e40af') ?>"
                                    style="width:44px;height:30px;padding:0;border:1px solid var(--gris-300);border-radius:6px;" <?= $puedeEditar ? '' : 'disabled' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="help-text">El "Orden" reordena el widget dentro de la zona del dashboard donde ya vive (tarjetas grandes, cuadrícula, gráficos o mapa); no lo mueve a otra columna.</p>
            <?php if ($puedeEditar): ?>
            <button class="btn btn-primary" style="margin-top:8px;"><i class="bi bi-save-fill"></i> Guardar configuración del dashboard</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-header"><h2><i class="bi bi-graph-up-arrow"></i> KPIs personalizados (a partir de los resultados del formulario)</h2></div>
    <div class="card-body">
        <p class="text-sm text-muted" style="margin-top:0;">
            Cree tarjetas de KPI nuevas calculadas directamente de las inspecciones guardadas: sume o promedie un campo
            numérico (ej. personas afectadas, % de daño), o cuente cuántas inspecciones coinciden con un valor de un
            campo de categoría (ej. "Riesgo Externo = C. Alto"). Aparecen junto a las demás tarjetas del dashboard.
        </p>

        <?php if ($kpisCustom): ?>
        <div class="table-wrap" style="margin-bottom:20px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Etiqueta</th>
                        <th>Cálculo</th>
                        <th style="text-align:center;">Visible</th>
                        <th style="text-align:center;">Orden</th>
                        <th style="text-align:center;">Color</th>
                        <th style="text-align:center;">Degradado</th>
                        <th style="text-align:center;">Color 2</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($kpisCustom as $k): $meta = $camposKpi[$k['campo']] ?? null; ?>
                    <tr>
                        <td>
                            <i class="bi <?= e($k['icono'] ?: 'bi-graph-up-arrow') ?>"></i> <?= e($k['label']) ?>
                            <input type="hidden" form="form-kpis-existentes" name="kpis[<?= e($k['id']) ?>][existe]" value="1">
                        </td>
                        <td class="text-sm text-muted">
                            <?= $k['tipo'] === 'conteo' ? 'Conteo donde' : ($k['tipo'] === 'suma' ? 'Suma de' : 'Promedio de') ?>
                            <?= e($meta['label'] ?? $k['campo']) ?><?= $k['tipo'] === 'conteo' ? ' = ' . e($k['valor']) : '' ?>
                        </td>
                        <td style="text-align:center;">
                            <input form="form-kpis-existentes" type="checkbox" name="kpis[<?= e($k['id']) ?>][visible]" value="1"
                                <?= !empty($k['visible']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                                style="width:17px;height:17px;accent-color:var(--azul-700);">
                        </td>
                        <td style="text-align:center;">
                            <input form="form-kpis-existentes" type="number" min="1" name="kpis[<?= e($k['id']) ?>][orden]" value="<?= (int)$k['orden'] ?>"
                                class="form-control" style="width:70px;text-align:center;" <?= $puedeEditar ? '' : 'disabled' ?>>
                        </td>
                        <td style="text-align:center;">
                            <input form="form-kpis-existentes" type="color" name="kpis[<?= e($k['id']) ?>][color]" value="<?= e($k['color'] ?: '#1e3a8a') ?>"
                                style="width:44px;height:30px;padding:0;border:1px solid var(--gris-300);border-radius:6px;" <?= $puedeEditar ? '' : 'disabled' ?>>
                            <label style="display:inline-flex;align-items:center;gap:4px;font-weight:400;margin:0 0 0 6px;">
                                <input form="form-kpis-existentes" type="checkbox" name="kpis[<?= e($k['id']) ?>][sin_color]" value="1"
                                    <?= empty($k['color']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                                <span class="text-sm text-muted">Def.</span>
                            </label>
                        </td>
                        <td style="text-align:center;">
                            <input form="form-kpis-existentes" type="checkbox" name="kpis[<?= e($k['id']) ?>][gradiente]" value="1"
                                <?= !empty($k['gradiente']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                                style="width:17px;height:17px;accent-color:var(--azul-700);">
                        </td>
                        <td style="text-align:center;">
                            <input form="form-kpis-existentes" type="color" name="kpis[<?= e($k['id']) ?>][color2]" value="<?= e($k['color2'] ?: '#1e40af') ?>"
                                style="width:44px;height:30px;padding:0;border:1px solid var(--gris-300);border-radius:6px;" <?= $puedeEditar ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <?php if ($puedeEditar): ?>
                            <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php" onsubmit="return confirm('¿Eliminar este KPI del dashboard?');">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="accion" value="eliminar_kpi">
                                <input type="hidden" name="kpi_id" value="<?= e($k['id']) ?>">
                                <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($puedeEditar): ?>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php" id="form-kpis-existentes">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="guardar_kpis">
            <button class="btn btn-primary btn-sm"><i class="bi bi-save-fill"></i> Guardar cambios de los KPIs de arriba</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
        <div class="section-title" style="margin-top:<?= $kpisCustom ? '20px' : '0' ?>;"><i class="bi bi-plus-circle-fill"></i> Agregar nuevo KPI</div>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php" id="form-agregar-kpi">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="agregar_kpi">
            <div class="form-grid cols-2">
                <div class="field">
                    <label class="req">Etiqueta</label>
                    <input required name="label" class="form-control" placeholder="Ej: Riesgo alto detectado">
                </div>
                <div class="field">
                    <label class="req">Campo de origen</label>
                    <select required name="campo" id="kpi-campo" class="form-control">
                        <option value="">Seleccione…</option>
                        <optgroup label="Numéricos (Suma / Promedio)">
                            <?php foreach ($camposKpi as $campo => $meta): if ($meta['tipo'] !== 'numero') continue; ?>
                            <option value="<?= e($campo) ?>" data-tipo="numero"><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="De categoría (Conteo de coincidencias)">
                            <?php foreach ($camposKpi as $campo => $meta): if ($meta['tipo'] !== 'texto') continue; ?>
                            <option value="<?= e($campo) ?>" data-tipo="texto"><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="field">
                    <label class="req">Tipo de cálculo</label>
                    <select required name="tipo" id="kpi-tipo" class="form-control">
                        <option value="conteo">Conteo de coincidencias (campo = valor)</option>
                        <option value="suma">Suma</option>
                        <option value="promedio">Promedio</option>
                    </select>
                </div>
                <div class="field" id="kpi-valor-wrap">
                    <label>Valor a contar</label>
                    <select name="valor" id="kpi-valor" class="form-control">
                        <option value="">Seleccione un campo primero…</option>
                    </select>
                </div>
                <div class="field">
                    <label>Ícono (Bootstrap Icons)</label>
                    <input name="icono" class="form-control" placeholder="Ej: bi-exclamation-triangle-fill" value="bi-graph-up-arrow">
                    <p class="help-text">Vea la lista completa en <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">icons.getbootstrap.com</a>.</p>
                </div>
                <div class="field">
                    <label>Color</label>
                    <div class="flex items-center gap-8">
                        <input type="color" name="color" value="#1e3a8a" style="width:44px;height:36px;padding:0;border:1px solid var(--gris-300);border-radius:6px;">
                        <label style="display:inline-flex;align-items:center;gap:4px;font-weight:400;margin:0;">
                            <input type="checkbox" name="sin_color" value="1" checked> <span class="text-sm text-muted">Usar color por defecto</span>
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label>Degradado</label>
                    <div class="flex items-center gap-8">
                        <label style="display:inline-flex;align-items:center;gap:4px;font-weight:400;margin:0;">
                            <input type="checkbox" name="gradiente" value="1"> <span class="text-sm text-muted">Activar</span>
                        </label>
                        <input type="color" name="color2" value="#1e40af" style="width:44px;height:36px;padding:0;border:1px solid var(--gris-300);border-radius:6px;">
                        <span class="text-sm text-muted">Color 2</span>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary" style="margin-top:12px;"><i class="bi bi-plus-lg"></i> Agregar KPI al dashboard</button>
        </form>
        <script>
        (function () {
            // Metadatos de cada campo (tipo + opciones válidas) para armar el
            // select de "Valor a contar" dinámicamente según el campo elegido.
            const CAMPOS_KPI = <?= json_encode($camposKpi, JSON_UNESCAPED_UNICODE) ?>;
            const selCampo = document.getElementById('kpi-campo');
            const selTipo  = document.getElementById('kpi-tipo');
            const wrapValor = document.getElementById('kpi-valor-wrap');
            const selValor  = document.getElementById('kpi-valor');

            function actualizar() {
                const meta = CAMPOS_KPI[selCampo.value];
                if (!meta) {
                    selTipo.innerHTML = '<option value="conteo">Conteo de coincidencias (campo = valor)</option><option value="suma">Suma</option><option value="promedio">Promedio</option>';
                    wrapValor.style.display = '';
                    selValor.innerHTML = '<option value="">Seleccione un campo primero…</option>';
                    return;
                }
                if (meta.tipo === 'numero') {
                    selTipo.innerHTML = '<option value="suma">Suma</option><option value="promedio">Promedio</option>';
                    wrapValor.style.display = 'none';
                    selValor.innerHTML = '';
                } else {
                    selTipo.innerHTML = '<option value="conteo">Conteo de coincidencias (campo = valor)</option>';
                    wrapValor.style.display = '';
                    const opciones = meta.opciones || {};
                    const entries = Array.isArray(opciones) ? opciones.map(v => [v, v]) : Object.entries(opciones);
                    selValor.innerHTML = entries.map(([val, lbl]) => `<option value="${val}">${lbl}</option>`).join('');
                }
            }
            selCampo.addEventListener('change', actualizar);
            actualizar();
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<!-- ================================================================
     CONFIGURACIÓN DEL MAPA DEL DASHBOARD
     ================================================================ -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2><i class="bi bi-map-fill"></i> Modo del mapa en el dashboard</h2></div>
    <?php if (!$puedeEditar): ?>
        <div class="card-body text-muted">No tiene permisos para editar la configuración.</div>
    <?php else: ?>
    <div class="card-body">
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php" id="form-mapa-config">
            <input type="hidden" name="accion" value="guardar_mapa">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <!-- Modo -->
            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:600;">Modo del mapa</label>
                <div class="text-sm text-muted" style="margin-bottom:8px;">Define qué puntos geográficos se muestran en el mapa principal del dashboard.</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                    <?php
                    $modos = [
                        'normal'       => ['icono'=>'bi-layers-fill','titulo'=>'Normal','desc'=>'Muestra puntos de inspección y de seguimiento simultáneamente'],
                        'inspeccion'   => ['icono'=>'bi-clipboard-check-fill','titulo'=>'Solo inspecciones','desc'=>'Muestra únicamente las fichas de inspección'],
                        'seguimiento'  => ['icono'=>'bi-tools','titulo'=>'Solo seguimiento','desc'=>'Muestra únicamente las fichas de seguimiento y control'],
                        'personalizado'=> ['icono'=>'bi-pin-map-fill','titulo'=>'Personalizado','desc'=>'Usted elige qué puntos específicos aparecen en el mapa'],
                    ];
                    foreach ($modos as $val => $m): ?>
                    <label class="card" style="cursor:pointer;padding:12px;border:2px solid <?= $mapaConfig['modo']===$val?'#22366f':'var(--border)' ?>;border-radius:10px;user-select:none;" id="modo-card-<?= $val ?>">
                        <div class="flex items-center gap-8" style="margin-bottom:4px;">
                            <input type="radio" name="mapa_modo" value="<?= $val ?>" <?= $mapaConfig['modo']===$val?'checked':'' ?> style="accent-color:#22366f;">
                            <i class="bi <?= $m['icono'] ?>" style="color:#22366f;font-size:18px;"></i>
                            <strong><?= $m['titulo'] ?></strong>
                        </div>
                        <div class="text-sm text-muted"><?= $m['desc'] ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Panel del modo personalizado -->
            <div id="panel-personalizado" style="<?= $mapaConfig['modo']==='personalizado'?'':'display:none;' ?>border:1px solid var(--border);border-radius:10px;padding:16px;background:var(--fondo-panel,#f8fafc);margin-bottom:16px;">
                <div class="flex gap-16 align-start" style="flex-wrap:wrap;">
                    <!-- Inspecciones -->
                    <div style="flex:1;min-width:280px;">
                        <div style="font-weight:600;margin-bottom:6px;"><i class="bi bi-clipboard-check"></i> Fichas de inspección</div>
                        <input type="text" id="filtro-insp-mapa" class="form-control form-control-sm" placeholder="Filtrar por nombre, código o parroquia…" style="margin-bottom:8px;">
                        <div style="max-height:280px;overflow-y:auto;border:1px solid var(--gris-300);border-radius:6px;background:#fff;">
                        <?php foreach ($listaInspecciones as $insp): ?>
                            <label class="insp-mapa-item" style="display:flex;align-items:flex-start;gap:8px;padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--gris-100);"
                                   data-search="<?= e(strtolower(($insp['codigo']??'').' '.($insp['nombre_edificio']??'').' '.($insp['parroquia']??'').' '.($insp['municipio']??''))) ?>">
                                <input type="checkbox" name="mapa_insp_ids[]" value="<?= (int)$insp['id'] ?>"
                                       style="margin-top:2px;accent-color:#22366f;"
                                       <?= in_array((int)$insp['id'], $mapaConfig['insp_ids']) ? 'checked' : '' ?>>
                                <div>
                                    <div style="font-size:13px;font-weight:600;"><?= e($insp['nombre_edificio'] ?: $insp['codigo']) ?></div>
                                    <div class="text-sm text-muted"><?= e($insp['parroquia']??'') ?> · <?= e($insp['municipio']??'') ?> · <?= e($insp['estado']??'') ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$listaInspecciones): ?><div class="text-sm text-muted" style="padding:12px;">Sin inspecciones registradas.</div><?php endif; ?>
                        </div>
                        <div class="text-sm text-muted" style="margin-top:4px;" id="cnt-insp-sel">
                            <?= count($mapaConfig['insp_ids']) ?> seleccionada(s)
                        </div>
                    </div>
                    <!-- Seguimiento -->
                    <div style="flex:1;min-width:280px;">
                        <div style="font-weight:600;margin-bottom:6px;"><i class="bi bi-tools"></i> Fichas de seguimiento</div>
                        <input type="text" id="filtro-seg-mapa" class="form-control form-control-sm" placeholder="Filtrar por nombre, estado de obra…" style="margin-bottom:8px;">
                        <div style="max-height:280px;overflow-y:auto;border:1px solid var(--gris-300);border-radius:6px;background:#fff;">
                        <?php foreach ($listaSeguimiento as $seg): ?>
                            <label class="seg-mapa-item" style="display:flex;align-items:flex-start;gap:8px;padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--gris-100);"
                                   data-search="<?= e(strtolower(($seg['nombre_edificio']??'').' '.($seg['estado_obra']??'').' '.($seg['parroquia']??''))) ?>">
                                <input type="checkbox" name="mapa_seg_ids[]" value="<?= (int)$seg['id'] ?>"
                                       style="margin-top:2px;accent-color:#f0a63a;"
                                       <?= in_array((int)$seg['id'], $mapaConfig['seg_ids']) ? 'checked' : '' ?>>
                                <div>
                                    <div style="font-size:13px;font-weight:600;"><?= e($seg['nombre_edificio']) ?></div>
                                    <div class="text-sm" style="margin-top:2px;">
                                        <span class="badge badge-gris"><?= e($seg['estado_obra'] ?? 'Sin iniciar') ?></span>
                                        <span class="text-muted" style="margin-left:4px;"><?= e($seg['parroquia']??'') ?></span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$listaSeguimiento): ?><div class="text-sm text-muted" style="padding:12px;">Sin edificaciones en seguimiento.</div><?php endif; ?>
                        </div>
                        <div class="text-sm text-muted" style="margin-top:4px;" id="cnt-seg-sel">
                            <?= count($mapaConfig['seg_ids']) ?> seleccionada(s)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nota sobre los colores -->
            <div class="alert alert-info" style="margin-bottom:16px;">
                <i class="bi bi-info-circle"></i>
                <div>Los puntos de <strong>inspección</strong> mantienen automáticamente su color de semáforo (verde/amarillo/rojo según la decisión). Los puntos de <strong>seguimiento</strong> se muestran con un ícono de herramienta <i class="bi bi-tools"></i> para indicar que están en fase 2 de intervención.</div>
            </div>

            <button class="btn btn-primary"><i class="bi bi-save-fill"></i> Guardar configuración del mapa</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    // Mostrar/ocultar panel personalizado y resaltar la tarjeta seleccionada.
    const radios = document.querySelectorAll('input[name="mapa_modo"]');
    const panel  = document.getElementById('panel-personalizado');
    const cards  = document.querySelectorAll('[id^="modo-card-"]');

    function actualizarModo() {
        const sel = document.querySelector('input[name="mapa_modo"]:checked')?.value;
        panel.style.display = sel === 'personalizado' ? '' : 'none';
        cards.forEach(c => {
            const activo = c.id === 'modo-card-' + sel;
            c.style.borderColor = activo ? '#22366f' : 'var(--border)';
        });
    }
    radios.forEach(r => r.addEventListener('change', actualizarModo));
    actualizarModo();

    // Filtro de inspecciones.
    const filtroInsp = document.getElementById('filtro-insp-mapa');
    filtroInsp?.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.insp-mapa-item').forEach(el => {
            el.style.display = !q || el.dataset.search.includes(q) ? '' : 'none';
        });
    });
    // Filtro de seguimiento.
    const filtroSeg = document.getElementById('filtro-seg-mapa');
    filtroSeg?.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.seg-mapa-item').forEach(el => {
            el.style.display = !q || el.dataset.search.includes(q) ? '' : 'none';
        });
    });
    // Contadores.
    function actualizarContadores() {
        const cntI = document.querySelectorAll('input[name="mapa_insp_ids[]"]:checked').length;
        const cntS = document.querySelectorAll('input[name="mapa_seg_ids[]"]:checked').length;
        const elI = document.getElementById('cnt-insp-sel');
        const elS = document.getElementById('cnt-seg-sel');
        if (elI) elI.textContent = cntI + ' seleccionada(s)';
        if (elS) elS.textContent = cntS + ' seleccionada(s)';
    }
    document.querySelectorAll('input[name="mapa_insp_ids[]"], input[name="mapa_seg_ids[]"]')
        .forEach(cb => cb.addEventListener('change', actualizarContadores));
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
