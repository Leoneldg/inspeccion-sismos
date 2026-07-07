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
$opcionesMapa   = obtenerOpcionesMapa();
$camposKpi      = catalogoCamposKpi();
$kpisCustom     = obtenerConfigKpisCustom();

include __DIR__ . '/../includes/header.php';
?>

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
    <div class="card-header"><h2><i class="bi bi-geo-alt-fill"></i> Opciones del mapa</h2></div>
    <div class="card-body">
        <p class="text-sm text-muted" style="margin-top:0;">
            Controles rápidos del mapa del dashboard. Son interruptores de mostrar/ocultar: no cambian
            el resto del tablero ni el orden de los indicadores.
        </p>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="guardar_mapa">

            <div class="check-row" style="margin-bottom:12px;">
                <input type="checkbox" name="mapa[listado_emergente]" id="mapa_listado" value="1"
                    <?= !empty($opcionesMapa['listado_emergente']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                    style="width:17px;height:17px;accent-color:var(--azul-700);">
                <label for="mapa_listado">
                    Mostrar el <strong>listado de fichas</strong> al seleccionar una zona en el mapa
                    <span class="text-sm text-muted" style="display:block;">
                        (Al hacer clic en una parroquia/municipio del geojson se abre el panel con los edificios.
                        Desactívelo para ocultar ese panel temporalmente.)
                    </span>
                </label>
            </div>

            <div class="check-row" style="margin-bottom:4px;">
                <input type="checkbox" name="mapa[solo_seguimiento]" id="mapa_solo_seg" value="1"
                    <?= !empty($opcionesMapa['solo_seguimiento']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>
                    style="width:17px;height:17px;accent-color:var(--azul-700);">
                <label for="mapa_solo_seg">
                    Mostrar en el mapa <strong>solo las fichas de Seguimiento y Control</strong>
                    <span class="text-sm text-muted" style="display:block;">
                        (Filtra los puntos del mapa a únicamente los edificios que ya tienen ficha de seguimiento.
                        El resto del dashboard no se altera.)
                    </span>
                </label>
            </div>

            <?php if ($puedeEditar): ?>
            <button class="btn btn-primary" style="margin-top:12px;"><i class="bi bi-save-fill"></i> Guardar opciones del mapa</button>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
