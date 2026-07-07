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

// Lista de edificios (inspecciones) disponibles para el modo "puntos
// seleccionados" del mapa. Solo tiene sentido elegir los que tienen
// coordenadas, pues son los únicos que se dibujan como punto. Se respeta el
// alcance territorial: un usuario estadal solo ve/selecciona los suyos.
// Se marca cuáles ya tienen ficha de Seguimiento y Control, para separarlos
// en dos pestañas (seguimiento vs. solo inspección).
require_once __DIR__ . '/../includes/territorial.php';
$haySeguimiento = tablaSeguimientoExiste();
$selSeg = $haySeguimiento
    ? ', (SELECT COUNT(*) FROM seguimiento_obras so WHERE so.inspeccion_id = inspecciones.id) AS tiene_seg'
    : ', 0 AS tiene_seg';
$sqlEdif = 'SELECT id, codigo, nombre_edificio, parroquia, municipio, estado' . $selSeg . '
            FROM inspecciones
            WHERE latitud IS NOT NULL AND longitud IS NOT NULL';
$paramsEdif = [];
if (!usuarioEsMaster()) {
    $estadoU = estadoDelUsuario();
    if ($estadoU !== null) {
        $sqlEdif .= ' AND estado = :est';
        $paramsEdif['est'] = $estadoU;
    }
}
$sqlEdif .= ' ORDER BY estado, municipio, nombre_edificio';
$stmtEdif = db()->prepare($sqlEdif);
$stmtEdif->execute($paramsEdif);
$edificiosTodos = $stmtEdif->fetchAll();

// Separar en dos grupos: con seguimiento y solo inspección.
$edificiosSeguimiento = [];
$edificiosInspeccion  = [];
foreach ($edificiosTodos as $ed) {
    if (!empty($ed['tiene_seg'])) {
        $edificiosSeguimiento[] = $ed;
    } else {
        $edificiosInspeccion[] = $ed;
    }
}
$edificiosSeleccionados = $opcionesMapa['edificios'] ?? [];

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
            Configure cómo se comporta el mapa del dashboard. El modo de visualización es una sola opción
            a la vez; no cambia el resto del tablero ni el orden de los indicadores.
        </p>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_configuracion.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="guardar_mapa">

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:600;margin-bottom:8px;display:block;">Modo de visualización del mapa</label>

                <label class="radio-row">
                    <input type="radio" name="mapa[modo]" value="normal"
                        <?= ($opcionesMapa['modo'] ?? 'normal') === 'normal' ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                    <span>
                        <strong>Normal</strong>
                        <span class="text-sm text-muted" style="display:block;">Muestra todos los puntos de edificios en el mapa.</span>
                    </span>
                </label>

                <label class="radio-row">
                    <input type="radio" name="mapa[modo]" value="seguimiento"
                        <?= ($opcionesMapa['modo'] ?? '') === 'seguimiento' ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                    <span>
                        <strong>Solo fichas de Seguimiento y Control</strong>
                        <span class="text-sm text-muted" style="display:block;">Muestra únicamente los edificios que ya tienen ficha de seguimiento.</span>
                    </span>
                </label>

                <label class="radio-row">
                    <input type="radio" name="mapa[modo]" value="listado"
                        <?= ($opcionesMapa['modo'] ?? '') === 'listado' ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                    <span>
                        <strong>Con listado emergente</strong>
                        <span class="text-sm text-muted" style="display:block;">Al hacer clic en una zona del mapa se abre el panel con la lista de fichas de esa zona.</span>
                    </span>
                </label>

                <label class="radio-row">
                    <input type="radio" name="mapa[modo]" value="seleccionados" id="mapa-modo-seleccionados"
                        <?= ($opcionesMapa['modo'] ?? '') === 'seleccionados' ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                    <span>
                        <strong>Puntos seleccionados</strong>
                        <span class="text-sm text-muted" style="display:block;">Muestra en el mapa únicamente los edificios que usted elija en la lista de abajo.</span>
                    </span>
                </label>
            </div>

            <!-- Selector de edificios (solo aplica al modo "Puntos seleccionados") -->
            <div class="field mapa-selector-edificios" id="mapa-selector-edificios"
                 style="<?= ($opcionesMapa['modo'] ?? '') === 'seleccionados' ? '' : 'display:none;' ?>border-top:1px solid var(--gris-100);padding-top:14px;margin-bottom:16px;">
                <label style="font-weight:600;margin-bottom:6px;display:block;">
                    <i class="bi bi-check2-square"></i> Edificios a mostrar
                    <span class="text-sm text-muted" id="mapa-sel-contador"></span>
                </label>
                <?php if (!$edificiosTodos): ?>
                    <p class="text-sm text-muted">No hay edificios con coordenadas registradas para seleccionar.</p>
                <?php else: ?>
                    <?php
                    // Helper para pintar un item (una casilla de edificio).
                    $pintarItem = function (array $ed) use ($edificiosSeleccionados, $puedeEditar) {
                        $checked = in_array((int)$ed['id'], $edificiosSeleccionados, true);
                        $texto = trim(($ed['nombre_edificio'] ?: 'Sin nombre') . ' ' . ($ed['codigo'] ?: '') . ' ' . ($ed['parroquia'] ?: '') . ' ' . ($ed['municipio'] ?: '') . ' ' . ($ed['estado'] ?: ''));
                        ?>
                        <label class="mapa-sel-item" data-busqueda="<?= e(mb_strtolower($texto)) ?>">
                            <input type="checkbox" name="mapa[edificios][]" value="<?= (int)$ed['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                            <span class="mapa-sel-item-info">
                                <span class="mapa-sel-item-nombre"><?= e($ed['nombre_edificio'] ?: 'Sin nombre') ?> <span class="mapa-sel-item-cod"><?= e($ed['codigo']) ?></span></span>
                                <span class="mapa-sel-item-loc"><?= e(trim(($ed['parroquia'] ?: '—') . ', ' . ($ed['municipio'] ?: '—') . ', ' . ($ed['estado'] ?: '—'))) ?></span>
                            </span>
                        </label>
                    <?php }; ?>

                    <!-- Pestañas: Seguimiento y Control | Fichas de inspección -->
                    <div class="mapa-sel-tabs" role="tablist">
                        <button type="button" class="mapa-sel-tab activo" data-tab="seguimiento">
                            <i class="bi bi-tools"></i> Seguimiento y Control
                            <span class="mapa-sel-tab-badge"><?= count($edificiosSeguimiento) ?></span>
                        </button>
                        <button type="button" class="mapa-sel-tab" data-tab="inspeccion">
                            <i class="bi bi-clipboard-check"></i> Fichas de inspección
                            <span class="mapa-sel-tab-badge"><?= count($edificiosInspeccion) ?></span>
                        </button>
                    </div>

                    <input type="text" class="form-control" id="mapa-sel-buscar" placeholder="Buscar por nombre, código, municipio…" style="margin:8px 0;" <?= $puedeEditar ? '' : 'disabled' ?>>
                    <div class="mapa-sel-acciones" style="margin-bottom:8px;">
                        <button type="button" class="btn btn-outline btn-sm" id="mapa-sel-todos" <?= $puedeEditar ? '' : 'disabled' ?>><i class="bi bi-check-all"></i> Marcar visibles</button>
                        <button type="button" class="btn btn-outline btn-sm" id="mapa-sel-ninguno" <?= $puedeEditar ? '' : 'disabled' ?>><i class="bi bi-x-lg"></i> Quitar visibles</button>
                        <span class="text-sm text-muted mapa-sel-ayuda">Aplica a la pestaña activa según la búsqueda.</span>
                    </div>

                    <!-- Panel: Seguimiento y Control -->
                    <div class="mapa-sel-panel" id="mapa-sel-panel-seguimiento">
                        <?php if (!$edificiosSeguimiento): ?>
                            <p class="text-sm text-muted" style="padding:8px;">Aún no hay edificios con ficha de Seguimiento y Control.</p>
                        <?php else: ?>
                            <div class="mapa-sel-lista">
                                <?php foreach ($edificiosSeguimiento as $ed) $pintarItem($ed); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Panel: Fichas de inspección -->
                    <div class="mapa-sel-panel" id="mapa-sel-panel-inspeccion" style="display:none;">
                        <?php if (!$edificiosInspeccion): ?>
                            <p class="text-sm text-muted" style="padding:8px;">No hay fichas de inspección sin seguimiento.</p>
                        <?php else: ?>
                            <div class="mapa-sel-lista">
                                <?php foreach ($edificiosInspeccion as $ed) $pintarItem($ed); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <p class="text-sm text-muted" id="mapa-sel-sinresultados" style="display:none;">Ningún edificio coincide con la búsqueda.</p>
                <?php endif; ?>
            </div>

            <!-- Toggle independiente: filtrar por parroquias -->
            <div class="field" style="border-top:1px solid var(--gris-100);padding-top:14px;">
                <label class="toggle-row">
                    <span>
                        <strong>Filtrar por parroquias</strong>
                        <span class="text-sm text-muted" style="display:block;">
                            Permite que al hacer clic en una parroquia del mapa se filtre la información por esa parroquia.
                            Desactívelo para deshabilitar ese filtrado.
                        </span>
                    </span>
                    <span class="toggle-switch">
                        <input type="checkbox" name="mapa[filtro_parroquias]" value="1"
                            <?= !empty($opcionesMapa['filtro_parroquias']) ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>

            <?php if ($puedeEditar): ?>
            <button class="btn btn-primary" style="margin-top:14px;"><i class="bi bi-save-fill"></i> Guardar opciones del mapa</button>
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

<script>
// Selector de edificios para el modo "Puntos seleccionados" del mapa,
// con dos pestañas: Seguimiento y Control | Fichas de inspección.
(function () {
    const selector = document.getElementById('mapa-selector-edificios');
    if (!selector) return;
    const radios = document.querySelectorAll('input[name="mapa[modo]"]');
    const buscar = document.getElementById('mapa-sel-buscar');
    const sinRes = document.getElementById('mapa-sel-sinresultados');
    const contador = document.getElementById('mapa-sel-contador');
    const btnTodos = document.getElementById('mapa-sel-todos');
    const btnNinguno = document.getElementById('mapa-sel-ninguno');
    const tabs = Array.from(selector.querySelectorAll('.mapa-sel-tab'));
    const paneles = {
        seguimiento: document.getElementById('mapa-sel-panel-seguimiento'),
        inspeccion:  document.getElementById('mapa-sel-panel-inspeccion'),
    };
    // Todos los items (de ambos paneles) para contar y filtrar globalmente.
    const todosItems = Array.from(selector.querySelectorAll('.mapa-sel-item'));
    let tabActiva = 'seguimiento';

    function itemsDeTab(tab) {
        const panel = paneles[tab];
        return panel ? Array.from(panel.querySelectorAll('.mapa-sel-item')) : [];
    }
    function mostrarSelector() {
        const sel = document.getElementById('mapa-modo-seleccionados');
        selector.style.display = (sel && sel.checked) ? '' : 'none';
    }
    function actualizarContador() {
        if (!contador) return;
        // Cuenta el total seleccionado en AMBAS pestañas (lo que se guardará).
        const n = todosItems.filter(it => it.querySelector('input').checked).length;
        contador.textContent = n ? `— ${n} seleccionado${n === 1 ? '' : 's'} en total` : '— ninguno seleccionado';
    }
    function filtrar() {
        const q = (buscar ? buscar.value : '').trim().toLowerCase();
        let visibles = 0;
        itemsDeTab(tabActiva).forEach(it => {
            const ok = !q || it.dataset.busqueda.includes(q);
            it.style.display = ok ? '' : 'none';
            if (ok) visibles++;
        });
        if (sinRes) sinRes.style.display = visibles ? 'none' : '';
    }
    function cambiarTab(tab) {
        tabActiva = tab;
        tabs.forEach(t => t.classList.toggle('activo', t.dataset.tab === tab));
        Object.keys(paneles).forEach(k => {
            if (paneles[k]) paneles[k].style.display = (k === tab) ? '' : 'none';
        });
        filtrar();
    }

    radios.forEach(r => r.addEventListener('change', mostrarSelector));
    tabs.forEach(t => t.addEventListener('click', () => cambiarTab(t.dataset.tab)));
    if (buscar) buscar.addEventListener('input', filtrar);
    todosItems.forEach(it => it.querySelector('input').addEventListener('change', actualizarContador));
    if (btnTodos) btnTodos.addEventListener('click', () => {
        // Marca los visibles de la pestaña activa (según el filtro actual).
        itemsDeTab(tabActiva).forEach(it => { if (it.style.display !== 'none') it.querySelector('input').checked = true; });
        actualizarContador();
    });
    if (btnNinguno) btnNinguno.addEventListener('click', () => {
        // Desmarca los visibles de la pestaña activa.
        itemsDeTab(tabActiva).forEach(it => { if (it.style.display !== 'none') it.querySelector('input').checked = false; });
        actualizarContador();
    });

    mostrarSelector();
    cambiarTab('seguimiento');
    actualizarContador();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
