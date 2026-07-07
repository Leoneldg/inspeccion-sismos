<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$row = null;
$fotosExistentes = [];

if ($editId) {
    requierePermiso('formulario', 'editar');
    $stmt = db()->prepare('SELECT * FROM inspecciones WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('error', 'La inspección solicitada no existe.');
        header('Location: ' . APP_URL_BASE . 'formulario/index.php');
        exit;
    }
    $danosEst = json_decode($row['danos_estructurales'] ?? '{}', true) ?: [];
    $danosNo  = json_decode($row['danos_no_estructurales'] ?? '{}', true) ?: [];
    $extra    = json_decode($row['datos_adicionales'] ?? '{}', true) ?: [];
    $pisoCriticoData = json_decode($row['elementos_piso_critico'] ?? '{}', true) ?: [];
    $accionesData    = json_decode($row['acciones_recomendadas'] ?? '{}', true) ?: [];
    $fotosExistentes = obtenerFotosInspeccion($editId);
} else {
    requierePermiso('formulario', 'crear');
    $danosEst = [];
    $danosNo  = [];
    $extra    = [];
    $pisoCriticoData = [];
    $accionesData    = [];
}

function val($row, $key, $default = '') {
    return $row[$key] ?? $default;
}

/** Imprime el bloque de subida de fotos + miniaturas existentes para una categoría dada. */
function bloqueFotos(string $categoria, string $etiqueta, array $fotosExistentes, bool $multiple = true, string $capture = 'environment', string $ayuda = ''): void {
    $existentes = $fotosExistentes[$categoria] ?? [];
    // Ninguna foto es obligatoria: el inspector puede subirlas si las tiene,
    // pero puede guardar la inspección sin ellas.
    $obligatoria = false;
    ?>
    <div class="foto-input-box">
        <label class="<?= $obligatoria ? 'req' : '' ?>"><i class="bi bi-camera-fill"></i> <?= e($etiqueta) ?></label>
        <?php if ($ayuda): ?><p class="help-text" style="margin-top:-2px;"><?= e($ayuda) ?></p><?php endif; ?>
        <input type="file" name="fotos[<?= e($categoria) ?>][]" accept="image/*"<?= $multiple ? ' multiple' : '' ?><?= $obligatoria ? ' required' : '' ?>>
        <?php if ($existentes): ?>
        <div class="foto-existente-grid">
            <?php foreach ($existentes as $f): ?>
            <div class="foto-existente">
                <img src="<?= APP_URL_BASE . e($f['ruta']) ?>" loading="lazy">
                <label class="del-check" title="Eliminar esta foto">
                    <input type="checkbox" name="eliminar_foto[]" value="<?= (int)$f['id'] ?>">
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="help-text">Ya hay <?= count($existentes) ?> foto(s) guardada(s) en esta categoría. Solo sube una nueva si quieres agregar o reemplazar alguna.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Selector de un profesional responsable (ing1/ing2) a partir del
 * directorio de ingenieros registrados (admin/ingenieros.php), en vez de
 * campos de texto libre. Al elegir uno, JS copia sus datos a campos
 * ocultos con los mismos nombres que antes (ing1_nombre, ing1_cedula,
 * etc.) para que el resto del sistema (guardado, ficha, PDF, dashboard)
 * siga funcionando exactamente igual sin cambios.
 */
function selectorIngeniero(string $prefix, ?array $row, bool $requerido, array $ingenieros): void {
    $valorSeleccionado = val($row, $prefix . '_id');
    ?>
    <div class="field" style="grid-column:1/-1;">
        <label <?= $requerido ? 'class="req"' : '' ?>>Profesional<?= $requerido ? '' : ' (segundo profesional)' ?></label>
        <div class="flex gap-8" style="flex-wrap:wrap;align-items:center;">
            <div style="flex:1;min-width:220px;">
                <input type="text" class="form-control ingeniero-buscar" data-prefix="<?= $prefix ?>"
                    placeholder="Buscar por nombre o cédula…" autocomplete="off" style="margin-bottom:6px;">
                <select <?= $requerido ? 'required' : '' ?> id="<?= $prefix ?>_id" name="<?= $prefix ?>_id" class="form-control ingeniero-select" data-prefix="<?= $prefix ?>">
                    <option value="">Seleccione un ingeniero registrado…</option>
                    <?php foreach ($ingenieros as $ing): ?>
                    <option value="<?= (int)$ing['id'] ?>"
                        data-nombre="<?= e($ing['nombre_completo']) ?>"
                        data-cedula="<?= e($ing['cedula']) ?>"
                        data-telefono="<?= e($ing['telefono'] ?? '') ?>"
                        data-profesion="<?= e($ing['profesion'] ?? '') ?>"
                        data-colegio="<?= e($ing['colegio_inscripcion'] ?? '') ?>"
                        <?= $valorSeleccionado == $ing['id'] ? 'selected' : '' ?>>
                        <?= e($ing['nombre_completo']) ?> — <?= e($ing['cedula']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-outline btn-sm btn-refrescar-ingenieros" title="Actualizar lista de ingenieros"><i class="bi bi-arrow-clockwise"></i></button>
            <a href="<?= APP_URL_BASE ?>admin/ingenieros.php" target="_blank" class="btn btn-outline btn-sm">
                <i class="bi bi-person-plus-fill"></i> Agregar nuevo ingeniero
            </a>
        </div>
        <p class="help-text">Solo aparecen ingenieros activos del directorio. Escribe en el buscador para filtrar por nombre o cédula. Si no está en la lista, agrégalo con el botón (se abre en una pestaña nueva, sin perder el progreso de este formulario) y luego toca <i class="bi bi-arrow-clockwise"></i> para actualizar.</p>
    </div>
    <div class="field"><label>Teléfono</label><input type="text" class="form-control" readonly id="<?= $prefix ?>_telefono_display" value="<?= e(val($row, $prefix . '_telefono')) ?>"></div>
    <div class="field"><label>Profesión</label><input type="text" class="form-control" readonly id="<?= $prefix ?>_profesion_display" value="<?= e(val($row, $prefix . '_profesion')) ?>"></div>
    <div class="field"><label>N° de inscripción</label><input type="text" class="form-control" readonly id="<?= $prefix ?>_inscripcion_display" value="<?= e(val($row, $prefix . '_inscripcion')) ?>"></div>

    <input type="hidden" name="<?= $prefix ?>_nombre" id="<?= $prefix ?>_nombre_hidden" value="<?= e(val($row, $prefix . '_nombre')) ?>">
    <input type="hidden" name="<?= $prefix ?>_cedula" id="<?= $prefix ?>_cedula_hidden" value="<?= e(val($row, $prefix . '_cedula')) ?>">
    <input type="hidden" name="<?= $prefix ?>_telefono" id="<?= $prefix ?>_telefono_hidden" value="<?= e(val($row, $prefix . '_telefono')) ?>">
    <input type="hidden" name="<?= $prefix ?>_profesion" id="<?= $prefix ?>_profesion_hidden" value="<?= e(val($row, $prefix . '_profesion')) ?>">
    <input type="hidden" name="<?= $prefix ?>_inscripcion" id="<?= $prefix ?>_inscripcion_hidden" value="<?= e(val($row, $prefix . '_inscripcion')) ?>">
    <?php
}

$pageTitle    = $editId ? 'Editar inspección' : 'Nueva inspección';
$pageSubtitle = 'Instrumento para Inspección de Edificaciones Afectadas por Sismos';
$activeModule = 'formulario';

$seccionesActivas = obtenerConfigFormulario();

$parroquias      = catalogoParroquias();
sort($parroquias, SORT_LOCALE_STRING);
$usos            = catalogoUsoEdificacion();
$tiposEstruct    = catalogoTipoEstructural();
$ingenierosActivos = obtenerIngenierosActivos();
$nivelesDano     = catalogoNivelDano();
$decisiones      = catalogoDecisionFinal();
$elementosEstruct = catalogoElementosEstructurales();
$elementosNoEstruct = catalogoElementosNoEstructurales();

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<form method="post" action="<?= APP_URL_BASE ?>formulario/save.php" id="form-inspeccion" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
<?php if ($editId): ?><input type="hidden" name="id" value="<?= (int)$editId ?>"><?php endif; ?>
<!-- Identificador único de este envío (generado en el navegador). Sirve para
     que, si la señal se corta o el guardado tarda y el modo offline reintenta
     más tarde, el servidor sepa que es el MISMO envío y no cree una
     inspección duplicada ni vuelva a subir las mismas fotos. -->
<input type="hidden" name="client_submission_id" id="client_submission_id">

<div class="wizard-steps">
    <div class="step active" data-step="1"><span class="step-num">1</span><span class="step-label">Profesionales</span></div>
    <div class="step" data-step="2"><span class="step-num">2</span><span class="step-label">Identificación y ubicación</span></div>
    <div class="step" data-step="3"><span class="step-num">3</span><span class="step-label">Características</span></div>
    <div class="step" data-step="4"><span class="step-num">4</span><span class="step-label">Riesgo y colapso</span></div>
    <div class="step" data-step="5"><span class="step-num">5</span><span class="step-label">Daños estructurales</span></div>
    <div class="step" data-step="6"><span class="step-num">6</span><span class="step-label">Daños no estructurales</span></div>
    <div class="step" data-step="7"><span class="step-num">7</span><span class="step-label">Personas afectadas</span></div>
    <div class="step" data-step="8"><span class="step-num">8</span><span class="step-label">Decisión y recomendaciones</span></div>
</div>

<div class="card">
<div class="card-body">

<!-- PASO 1 -->
<div class="wizard-pane active" data-pane="1">
    <div class="section-title"><i class="bi bi-person-badge-fill"></i> Profesional responsable de la inspección</div>
    <div class="form-grid">
        <?php selectorIngeniero('ing1', $row, true, $ingenierosActivos); ?>
    </div>
    <div class="form-grid cols-2">
        <?php bloqueFotos('foto_inspector', 'Foto del inspector (tipo carnet)', $fotosExistentes, false, 'user', 'Tómate una foto de frente, tipo carnet, para identificar quién realizó esta inspección.'); ?>
    </div>

    <button type="button" class="btn btn-outline btn-sm" id="btn-agregar-profesional" style="margin-top:6px;<?= val($row,'ing2_nombre') || val($row,'ing2_cedula') ? 'display:none;' : '' ?>">
        <i class="bi bi-person-plus-fill"></i> Agregar segundo profesional
    </button>
    <div id="bloque-segundo-profesional" style="<?= val($row,'ing2_nombre') || val($row,'ing2_cedula') ? '' : 'display:none;' ?>">
        <div class="section-title"><i class="bi bi-person-badge"></i> Segundo profesional</div>
        <div class="form-grid">
            <?php selectorIngeniero('ing2', $row, false, $ingenierosActivos); ?>
        </div>
    </div>
</div>

<!-- PASO 2 -->
<div class="wizard-pane" data-pane="2">
    <div class="section-title"><i class="bi bi-building"></i> Identificación de la edificación</div>
    <div class="form-grid">
        <div class="field" style="grid-column:span 2;"><label class="req">Nombre de edificio o estructura</label><input required name="nombre_edificio" class="form-control" value="<?= e(val($row,'nombre_edificio')) ?>"></div>
        <div class="field"><label class="req">Fecha de inspección</label><input required type="date" name="fecha_inspeccion" class="form-control" value="<?= e(val($row,'fecha_inspeccion', date('Y-m-d'))) ?>"></div>
        <div class="field"><label>Hora de inicio</label><input type="time" name="hora_inicio" class="form-control" value="<?= e(val($row,'hora_inicio')) ?>"></div>
        <div class="field"><label>Hora de culminación</label><input type="time" name="hora_culminacion" class="form-control" value="<?= e(val($row,'hora_culminacion')) ?>"></div>
        <div class="field"><label class="req">Cantidad de apartamentos</label><input type="number" min="0" name="cantidad_apartamentos" required placeholder="Ej: 12 (escriba 0 si es una vivienda unifamiliar)" class="form-control" value="<?= e(val($row,'cantidad_apartamentos', 0)) ?>"></div>
        <div class="field"><label class="req">N° de pisos</label><input type="number" min="0" name="num_pisos" required placeholder="Ej: 5" class="form-control" value="<?= e(val($row,'num_pisos', 0)) ?>"></div>
        <div class="field"><label class="req">N° de semisótanos</label><input type="number" min="0" name="num_semisotanos" required placeholder="Escriba 0 si no tiene" class="form-control" value="<?= e(val($row,'num_semisotanos', 0)) ?>"></div>
        <div class="field"><label class="req">N° de sótanos</label><input type="number" min="0" name="num_sotanos" required placeholder="Escriba 0 si no tiene" class="form-control" value="<?= e(val($row,'num_sotanos', 0)) ?>"></div>
        <?php if ($seccionesActivas['anio_personas']): ?>
        <div class="field"><label class="req">Año de construcción</label><input type="number" min="0" max="2100" name="anio_construccion" required placeholder="Ej: 1985" class="form-control" value="<?= e(val($row,'anio_construccion')) ?>"></div>
        <div class="field"><label class="req">N° de personas (general)</label><input type="number" min="0" name="numero_personas" required placeholder="Ej: 20" class="form-control" value="<?= e(val($row,'numero_personas')) ?>"></div>
        <?php endif; ?>
    </div>

    <div class="section-title"><i class="bi bi-geo-alt-fill"></i> Ubicación</div>
    <div class="form-grid">
        <?php
            // Alcance nacional: si el usuario es estadal, su estado viene fijado
            // y no puede cambiarlo. El master elige cualquier estado.
            $estadoActualForm = val($row, 'estado', usuarioEsMaster() ? 'Distrito Capital' : (estadoDelUsuario() ?? 'Distrito Capital'));
            $estadoBloqueado  = !usuarioEsMaster();
        ?>
        <div class="field">
            <label class="req">Estado</label>
            <select required name="estado" id="ubic-estado" class="form-control" <?= $estadoBloqueado ? 'data-bloqueado="1"' : '' ?>>
                <option value="">Seleccione…</option>
                <?php foreach (catalogoEstados() as $estOpt): ?>
                    <?php if ($estadoBloqueado && $estOpt !== $estadoActualForm) continue; ?>
                    <option value="<?= e($estOpt) ?>" <?= $estadoActualForm === $estOpt ? 'selected' : '' ?>><?= e($estOpt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label class="req">Ciudad</label><input name="ciudad" required placeholder="Ej: Caracas" class="form-control" value="<?= e(val($row,'ciudad')) ?>"></div>
        <div class="field">
            <label class="req">Municipio</label>
            <select required name="municipio" id="ubic-municipio" class="form-control" data-actual="<?= e(val($row,'municipio')) ?>">
                <option value="">Seleccione…</option>
            </select>
        </div>
        <div class="field">
            <label class="req">Parroquia</label>
            <select required name="parroquia" id="ubic-parroquia" class="form-control" data-actual="<?= e(val($row,'parroquia')) ?>">
                <option value="">Seleccione…</option>
            </select>
        </div>
        <div class="field"><label class="req">Comuna o circuito</label><input name="comuna_circuito" required placeholder="Ej: Comuna Simón Rodríguez" class="form-control" value="<?= e(val($row,'comuna_circuito')) ?>"></div>
        <div class="field"><label class="req">Urbanización</label><input name="urbanizacion" required placeholder="Ej: Urb. La Paz" class="form-control" value="<?= e(val($row,'urbanizacion')) ?>"></div>
        <div class="field"><label class="req">Sector</label><input name="sector" required placeholder="Ej: Sector Los Jardines" class="form-control" value="<?= e(val($row,'sector')) ?>"></div>
        <div class="field"><label class="req">Avenida o calle</label><input name="avenida_calle" required placeholder="Ej: Av. Bolívar con Calle 5" class="form-control" value="<?= e(val($row,'avenida_calle')) ?>"></div>
        <div class="field"><label class="req">Nombre de la comunidad</label><input name="nombre_comunidad" required placeholder="Ej: Consejo Comunal Nueva Esperanza" class="form-control" value="<?= e(val($row,'nombre_comunidad')) ?>"></div>
    </div>

    <label class="req" style="margin-top:6px;"><i class="bi bi-geo-fill"></i> Ubicación en el mapa</label>
    <p class="help-text" style="margin-top:-2px;margin-bottom:8px;">Toque o haga clic sobre el mapa para colocar el marcador en la edificación (obligatorio). Puede arrastrarlo para ajustar la posición.</p>
    <div class="mapa-selector" id="mapa-ubicacion">
        <div class="mapa-selector-hint"><i class="bi bi-cursor-fill"></i> Clic / toque para marcar la ubicación exacta</div>
    </div>
    <div class="flex items-center gap-8" style="margin-top:8px;flex-wrap:wrap;">
        <button type="button" id="btn-geo" class="btn btn-outline btn-sm"><i class="bi bi-crosshair"></i> Usar mi ubicación actual</button>
        <span class="coords-readout" id="coords-readout">
            <?= (val($row,'latitud') && val($row,'longitud')) ? e(val($row,'latitud')).', '.e(val($row,'longitud')) : 'Sin coordenadas seleccionadas' ?>
        </span>
    </div>
    <input type="hidden" name="latitud" id="input-latitud" value="<?= e(val($row,'latitud')) ?>">
    <input type="hidden" name="longitud" id="input-longitud" value="<?= e(val($row,'longitud')) ?>">

    <div class="section-title"><i class="bi bi-camera-fill"></i> Registro fotográfico</div>
    <div class="form-grid cols-2">
        <?php bloqueFotos('general', 'Vista general de la edificación / fachada', $fotosExistentes, true, 'environment', 'Foto de cuerpo completo de la fachada principal, desde una distancia que muestre todo el edificio.'); ?>
    </div>
</div>

<!-- PASO 3 -->
<div class="wizard-pane" data-pane="3">
    <div class="section-title"><i class="bi bi-diagram-3-fill"></i> Características constructivas</div>
    <div class="form-grid cols-2">
        <div class="field">
            <label>Uso de la edificación</label>
            <select required name="uso_edificacion" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach ($usos as $u): ?><option <?= val($row,'uso_edificacion')===$u?'selected':'' ?>><?= e($u) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Tipo estructural</label>
            <select required name="tipo_estructural" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach ($tiposEstruct as $t): ?><option <?= val($row,'tipo_estructural')===$t?'selected':'' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <label style="margin-top:10px;">Materiales presentes</label>
    <div class="form-grid cols-4">
        <?php if ($seccionesActivas['materiales_extendidos']): ?>
        <div class="check-row"><input type="checkbox" name="material_concreto" id="m0" value="1" <?= val($row,'material_concreto')?'checked':'' ?>><label for="m0">Concreto</label></div>
        <?php endif; ?>
        <div class="check-row"><input type="checkbox" name="material_acero" id="m1" value="1" <?= val($row,'material_acero')?'checked':'' ?>><label for="m1">Acero</label></div>
        <div class="check-row"><input type="checkbox" name="material_conexiones" id="m2" value="1" <?= val($row,'material_conexiones')?'checked':'' ?>><label for="m2">Conexiones</label></div>
        <div class="check-row"><input type="checkbox" name="material_mamposteria" id="m3" value="1" <?= val($row,'material_mamposteria')?'checked':'' ?>><label for="m3">Mampostería (general)</label></div>
        <?php if ($seccionesActivas['materiales_extendidos']): ?>
        <div class="check-row"><input type="checkbox" name="mamposteria_formal" id="m5" value="1" <?= val($row,'mamposteria_formal')?'checked':'' ?>><label for="m5">Mampostería formal</label></div>
        <div class="check-row"><input type="checkbox" name="mamposteria_informal" id="m6" value="1" <?= val($row,'mamposteria_informal')?'checked':'' ?>><label for="m6">Mampostería informal</label></div>
        <?php endif; ?>
        <div class="check-row"><input type="checkbox" name="material_otros" id="m4" value="1" <?= val($row,'material_otros')?'checked':'' ?>><label for="m4">Otros</label></div>
    </div>
    <div class="field" id="campo-otros-materiales" style="<?= val($row,'material_otros') ? '' : 'display:none;' ?>">
        <label>Especifique otros materiales</label>
        <input name="material_otros_especifique" required placeholder="Describa el material (Ej: Bahareque, adobe)" class="form-control" value="<?= e(val($row,'material_otros_especifique')) ?>">
    </div>
</div>

<!-- PASO 4 -->
<div class="wizard-pane" data-pane="4">
    <div class="section-title"><i class="bi bi-exclamation-diamond-fill"></i> Evaluación de riesgo general</div>
    <div class="form-grid cols-2">
        <div class="field">
            <label>Colapso de la estructura</label>
            <select required name="colapso_estructura" class="form-control">
                <?php foreach (['No','Parcial','Total'] as $o): ?><option <?= val($row,'colapso_estructura','No')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Peligro por edificios aledaños</label>
            <select required name="riesgo_edificios_aledanos" class="form-control">
                <?php foreach (['No','Moderado','Elevado','Si'] as $o): ?>
                <option value="<?= e($o) ?>" <?= val($row,'riesgo_edificios_aledanos')===$o?'selected':'' ?>><?= $o==='Si'?'Sí (legado)':$o ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Peligro geológico o geotécnico</label>
            <select required name="amenaza_geologica" class="form-control">
                <?php foreach (['No','Moderado','Elevado','Si'] as $o): ?>
                <option value="<?= e($o) ?>" <?= val($row,'amenaza_geologica')===$o?'selected':'' ?>><?= $o==='Si'?'Sí (legado)':$o ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Asentamiento del edificio</label>
            <select required name="asentamiento_edificio" class="form-control">
                <?php foreach (['No','Hasta 20 cm','Mayor a 20 cm','Si'] as $o): ?>
                <option value="<?= e($o) ?>" <?= val($row,'asentamiento_edificio')===$o?'selected':'' ?>><?= $o==='Si'?'Sí (legado)':$o ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Inclinación del edificio</label>
            <select required name="inclinacion_edificio" class="form-control">
                <?php foreach (['No','Hasta 2cm/60cm','Mayor que 2cm/60cm','Si'] as $o): ?>
                <option value="<?= e($o) ?>" <?= val($row,'inclinacion_edificio')===$o?'selected':'' ?>><?= $o==='Si'?'Sí (legado)':$o ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>¿Se requiere inspección interna?</label>
            <select required name="requiere_inspeccion_interna" class="form-control">
                <option value="No" <?= val($row,'requiere_inspeccion_interna','No')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'requiere_inspeccion_interna')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <?php if ($seccionesActivas['riesgo_externo']): ?>
        <div class="field">
            <label>Riesgo Externo (A/B/C)</label>
            <select required name="riesgo_externo" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach (catalogoNivelRiesgo() as $k => $meta): ?>
                <option value="<?= e($k) ?>" <?= val($row,'riesgo_externo')===$k?'selected':'' ?>><?= e($k) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-text">A. Bajo si todos los aspectos son "No". B. Medio si hay al menos un "Moderado" y ninguno "Elevado". C. Alto si hay al menos un "Elevado" (en ese caso no continúe la inspección interna).</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PASO 5 -->
<div class="wizard-pane" data-pane="5">
    <?php if ($seccionesActivas['piso_critico']): ?>
    <div class="section-title"><i class="bi bi-building-fill-exclamation"></i> Piso crítico y elementos con daño severo/completo</div>
    <div class="form-grid cols-2">
        <div class="field"><label class="req">Pisos inspeccionados</label><input name="pisos_inspeccionados" required placeholder="Ej: PB, 1, 2, 3" class="form-control" value="<?= e(val($row,'pisos_inspeccionados')) ?>" placeholder="Ej: PB, 1, 2, 3"></div>
        <div class="field">
            <label>Acceso a miembros estructurales principales</label>
            <select required name="acceso_miembros_estructurales" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach (catalogoAccesoMiembros() as $o): ?>
                <option value="<?= e($o) ?>" <?= val($row,'acceso_miembros_estructurales')===$o?'selected':'' ?>><?= e($o) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label class="req">Piso crítico</label><input name="piso_critico" required placeholder="Ej: Piso 3" class="form-control" value="<?= e(val($row,'piso_critico')) ?>"></div>
    </div>
    <label style="margin-top:10px;">N° de elementos con daño Severo/Completo (N), por tipo de elemento en el piso crítico</label>
    <div class="form-grid cols-4">
        <?php foreach (catalogoElementosPisoCritico() as $key => $label): ?>
        <div class="field"><label class="req"><?= e($label) ?></label><input type="number" min="0" name="elementos_piso_critico[severo][<?= $key ?>]" class="form-control" required placeholder="0" value="<?= e($pisoCriticoData['severo'][$key] ?? '') ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="field" style="margin-top:8px;">
        <label>Riesgo Estructural por daño Severo/Completo</label>
        <select required name="riesgo_estructural_severo" class="form-control">
            <option value="">Seleccione…</option>
            <?php foreach (catalogoRiesgoSevero() as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= val($row,'riesgo_estructural_severo')===$k?'selected':'' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="help-text">Si es catalogado como C. Alto, no continúe con la inspección interna: vaya a Decisión y coloque Etiqueta roja.</p>
    </div>
    <?php endif; ?>

    <?php if ($seccionesActivas['dano_moderado_piso_critico']): ?>
    <div class="section-title"><i class="bi bi-table"></i> Elementos estructurales con daño Moderado en el piso crítico</div>
    <div class="form-grid cols-4" style="margin-bottom:4px;">
        <div></div><div class="text-sm text-muted">Sin daño/Menor</div><div class="text-sm text-muted">Moderado</div><div class="text-sm text-muted">N° examinados</div>
    </div>
    <?php foreach (catalogoElementosPisoCritico() as $key => $label): ?>
    <div class="form-grid cols-4" style="margin-bottom:6px;">
        <div class="field"><label><?= e($label) ?></label></div>
        <div class="field"><input type="number" min="0" name="elementos_piso_critico[moderado][<?= $key ?>][sin_dano]" class="form-control" required placeholder="0" value="<?= e($pisoCriticoData['moderado'][$key]['sin_dano'] ?? '') ?>"></div>
        <div class="field"><input type="number" min="0" name="elementos_piso_critico[moderado][<?= $key ?>][moderado]" class="form-control" required placeholder="0" value="<?= e($pisoCriticoData['moderado'][$key]['moderado'] ?? '') ?>"></div>
        <div class="field"><input type="number" min="0" name="elementos_piso_critico[moderado][<?= $key ?>][examinados]" class="form-control" required placeholder="0" value="<?= e($pisoCriticoData['moderado'][$key]['examinados'] ?? '') ?>"></div>
    </div>
    <?php endforeach; ?>
    <div class="field" style="margin-top:8px;">
        <label>Riesgo Estructural por Daño Moderado</label>
        <select required name="riesgo_estructural_moderado" class="form-control">
            <option value="">Seleccione…</option>
            <?php foreach (catalogoNivelRiesgo() as $k => $meta): ?>
            <option value="<?= e($k) ?>" <?= val($row,'riesgo_estructural_moderado')===$k?'selected':'' ?>><?= e($k) ?> <?= $k==='A. Bajo'?'(< 10%)':($k==='B. Medio'?'(10-30%)':'(> 30%)') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="section-title"><i class="bi bi-bricks"></i> Daño en elementos estructurales</div>
    <div class="form-grid cols-2">
        <?php foreach ($elementosEstruct as $key => $label): ?>
        <div class="field">
            <label><?= e($label) ?></label>
            <select name="danos_estructurales[<?= $key ?>]" class="form-control">
                <option value="">Sin evaluar</option>
                <?php foreach ($nivelesDano as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($danosEst[$key] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:8px;">
                <?php bloqueFotos($key, 'Fotos de ' . $label, $fotosExistentes, true, 'environment', 'Fotografíe el daño de cerca (que se vea la grieta/deformación) y también con contexto (que se vea dónde está ubicado dentro del edificio).'); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title"><i class="bi bi-tools"></i> Intervención y porcentaje de daño</div>
    <div class="form-grid">
        <div class="field">
            <label>Requiere de intervención</label>
            <select required name="requiere_intervencion" class="form-control">
                <option value="No" <?= val($row,'requiere_intervencion','No')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'requiere_intervencion')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field"><label class="req">% Daño III (Moderado)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_iii" class="form-control" required placeholder="Ej: 10 (% del área con daño moderado)" value="<?= e(val($row,'pct_dano_iii', 0)) ?>"></div>
        <div class="field"><label class="req">% Daño IV (Severo)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_iv" class="form-control" required placeholder="Ej: 5 (% del área con daño severo)" value="<?= e(val($row,'pct_dano_iv', 0)) ?>"></div>
        <div class="field"><label class="req">% Daño V (Completo)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_v" class="form-control" required placeholder="Ej: 0 (% del área con daño completo)" value="<?= e(val($row,'pct_dano_v', 0)) ?>"></div>
        <div class="field"><label>m² de losas afectadas</label><input type="number" step="0.01" min="0" name="m2_losas" class="form-control" value="<?= e(val($row,'m2_losas')) ?>"></div>
        <div class="field"><label>Muros a reconstruir</label><input type="number" min="0" name="muros_reconstruir" class="form-control" value="<?= e(val($row,'muros_reconstruir')) ?>"></div>
    </div>
</div>

<!-- PASO 6 -->
<div class="wizard-pane" data-pane="6">
    <div class="section-title"><i class="bi bi-layout-wtf"></i> Daño en elementos no estructurales</div>
    <div class="form-grid cols-2">
        <?php foreach ($elementosNoEstruct as $key => $label): ?>
        <div class="field">
            <label><?= e($label) ?></label>
            <select name="danos_no_estructurales[<?= $key ?>]" class="form-control">
                <option value="">Sin evaluar</option>
                <?php foreach ($nivelesDano as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($danosNo[$key] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top:8px;">
                <?php bloqueFotos($key, 'Fotos de ' . $label, $fotosExistentes, true, 'environment', 'Fotografíe el daño de cerca y también con contexto, mostrando su ubicación dentro de la edificación.'); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($seccionesActivas['riesgo_componentes']): ?>
    <div class="field" style="max-width:420px;">
        <label>Riesgo de Componentes (no estructurales)</label>
        <select required name="riesgo_componentes" class="form-control">
            <option value="">Seleccione…</option>
            <?php foreach (catalogoNivelRiesgo() as $k => $meta): ?>
            <option value="<?= e($k) ?>" <?= val($row,'riesgo_componentes')===$k?'selected':'' ?>><?= e($k) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="section-title"><i class="bi bi-droplet-half"></i> Servicios y elementos complementarios</div>
    <div class="form-grid cols-4">
        <div class="check-row"><input type="checkbox" name="extra_ascensores" id="ex1" value="1" <?= !empty($extra['ascensores'])?'checked':'' ?>><label for="ex1">Ascensores</label></div>
        <div class="check-row"><input type="checkbox" name="extra_fuga_gas" id="ex2" value="1" <?= !empty($extra['fuga_gas'])?'checked':'' ?>><label for="ex2">Fuga de gas</label></div>
        <div class="check-row"><input type="checkbox" name="extra_fallas_electricas" id="ex3" value="1" <?= !empty($extra['fallas_electricas'])?'checked':'' ?>><label for="ex3">Fallas eléctricas</label></div>
        <div class="check-row"><input type="checkbox" name="extra_danos_aguas" id="ex4" value="1" <?= !empty($extra['danos_aguas'])?'checked':'' ?>><label for="ex4">Daños en aguas</label></div>
        <div class="check-row"><input type="checkbox" name="tiene_tanque_agua" id="ex5" value="1" <?= val($row,'tiene_tanque_agua')?'checked':'' ?>><label for="ex5">Tiene tanque de agua</label></div>
    </div>
    <div class="form-grid cols-2" style="margin-top:6px;">
        <div class="field" id="campo-cant-ascensores" style="<?= !empty($extra['ascensores']) ? '' : 'display:none;' ?>">
            <label>Cantidad de ascensores</label>
            <input type="number" min="0" name="extra_cant_ascensores" required placeholder="Ej: 2" class="form-control" value="<?= e($extra['cant_ascensores'] ?? '') ?>">
        </div>
        <div class="field" id="campo-estado-tanque" style="<?= val($row,'tiene_tanque_agua') ? '' : 'display:none;' ?>">
            <label>Estado del tanque de agua</label>
            <input name="extra_estado_tanque" required placeholder="Ej: Buen estado, Filtraciones, Grietas visibles" class="form-control" value="<?= e($extra['estado_tanque'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- PASO 7 -->
<div class="wizard-pane" data-pane="7">
    <div class="section-title"><i class="bi bi-people-fill"></i> Personas y animales afectados</div>
    <div class="form-grid cols-4">
        <div class="field"><label class="req">Familias</label><input type="number" min="0" name="familias" required placeholder="Ej: 3 (escriba 0 si no hay familias residiendo)" class="form-control" value="<?= e(val($row,'familias', 0)) ?>"></div>
        <div class="field"><label class="req">Hombres</label><input type="number" min="0" name="hombres" required placeholder="Ej: 4 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'hombres', 0)) ?>"></div>
        <div class="field"><label class="req">Mujeres</label><input type="number" min="0" name="mujeres" required placeholder="Ej: 5 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'mujeres', 0)) ?>"></div>
        <div class="field"><label class="req">Niños</label><input type="number" min="0" name="ninos" required placeholder="Ej: 2 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'ninos', 0)) ?>"></div>
        <div class="field"><label class="req">Adultos de 3ra edad</label><input type="number" min="0" name="adultos_tercera_edad" required placeholder="Ej: 1 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'adultos_tercera_edad', 0)) ?>"></div>
        <div class="field"><label class="req">Gestantes</label><input type="number" min="0" name="gestantes" required placeholder="Ej: 0 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'gestantes', 0)) ?>"></div>
        <div class="field"><label class="req">Movilidad reducida</label><input type="number" min="0" name="movilidad_reducida" required placeholder="Ej: 0 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'movilidad_reducida', 0)) ?>"></div>
        <div class="field"><label class="req">Mascotas</label><input type="number" min="0" name="mascotas" required placeholder="Ej: 0 (escriba 0 si no hay)" class="form-control" value="<?= e(val($row,'mascotas', 0)) ?>"></div>
    </div>

    <div class="section-title"><i class="bi bi-hammer"></i> Recursos requeridos para intervención</div>
    <div class="form-grid cols-4">
        <div class="field"><label class="req">Tiempo de acción (días)</label><input type="number" min="0" name="extra_tiempo_accion" required placeholder="Ej: 3 (días estimados)" class="form-control" value="<?= e($extra['tiempo_accion'] ?? '') ?>"></div>
        <div class="field"><label class="req">Mano de obra requerida</label><input name="extra_mano_obra" required placeholder="Ej: Albañil, Electricista, Plomero" class="form-control" value="<?= e($extra['mano_obra'] ?? '') ?>"></div>
        <div class="field"><label class="req">Herramientas requeridas</label><input name="extra_herramientas" required placeholder="Ej: Puntales, mazos, carretillas" class="form-control" value="<?= e($extra['herramientas'] ?? '') ?>"></div>
        <div class="field"><label class="req">Maquinaria requerida</label><input name="extra_maquinarias" required placeholder="Ej: Retroexcavadora, grúa, o "No se requiere"" class="form-control" value="<?= e($extra['maquinarias'] ?? '') ?>"></div>
    </div>
</div>

<!-- PASO 8 -->
<div class="wizard-pane" data-pane="8">
    <div class="section-title"><i class="bi bi-check2-square"></i> Decisión final</div>
    <div class="form-grid cols-2">
        <div class="field" style="grid-column:span 2;">
            <label class="req">Decisión final de la inspección</label>
            <select required name="decision_final" class="form-control">
                <?php foreach ($decisiones as $k => $meta): ?>
                    <option value="<?= e($k) ?>" <?= val($row,'decision_final')===$k?'selected':'' ?>><?= e($k) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label class="req">Etiqueta de inspección previa (si aplica)</label><input name="inspeccion_previa_etiqueta" required placeholder="Ej: Verde, Amarilla, Roja, o "Ninguna"" class="form-control" value="<?= e(val($row,'inspeccion_previa_etiqueta')) ?>"></div>
        <div class="field"><label class="req">Inspección especializada</label><input name="inspeccion_especializada" required placeholder="Ej: Estructural, Geotecnia, Eléctrica, o "No aplica"" class="form-control" value="<?= e(val($row,'inspeccion_especializada')) ?>"></div>
        <div class="field"><label class="req">Intervención de</label><input name="intervencion_de" required placeholder="Ej: Protección Civil, Bomberos, o "No aplica"" class="form-control" value="<?= e(val($row,'intervencion_de')) ?>"></div>
    </div>
    <div class="form-grid cols-2">
        <div class="field"><label class="req">Medidas de seguridad</label><textarea name="medidas_seguridad" class="form-control" required placeholder="Ej: Restringir el paso peatonal, desconectar el gas y la electricidad, apuntalar elementos en riesgo…"><?= e(val($row,'medidas_seguridad')) ?></textarea></div>
        <div class="field"><label class="req">Lugares del edificio para aplicar medidas</label><textarea name="lugares_medidas" class="form-control" required placeholder="Ej: Fachada frontal, escalera del piso 2, balcón del apartamento 4B…"><?= e(val($row,'lugares_medidas')) ?></textarea></div>
        <div class="field"><label class="req">Observaciones</label><textarea name="observaciones" class="form-control" required placeholder="Cualquier detalle adicional relevante que no se haya capturado en los campos anteriores"><?= e(val($row,'observaciones')) ?></textarea></div>
        <div class="field"><label class="req">Recomendaciones</label><textarea name="recomendaciones" class="form-control" required placeholder="Ej: Realizar evaluación estructural detallada antes de reingresar, desalojar de inmediato…"><?= e(val($row,'recomendaciones')) ?></textarea></div>
    </div>

    <?php if ($seccionesActivas['acciones_recomendadas']): ?>
    <div class="section-title"><i class="bi bi-list-check"></i> Acciones recomendadas</div>
    <label>Inspección Detallada</label>
    <div class="form-grid cols-4" style="margin-bottom:10px;">
        <?php foreach (catalogoInspeccionDetallada() as $key => $label): $chk = !empty($accionesData['inspeccion_detallada'][$key]); ?>
        <div class="check-row"><input type="checkbox" name="inspeccion_detallada[<?= $key ?>]" id="idet_<?= $key ?>" value="1" <?= $chk?'checked':'' ?>><label for="idet_<?= $key ?>"><?= e($label) ?></label></div>
        <?php endforeach; ?>
    </div>
    <label>Medidas de Prevención</label>
    <div class="form-grid cols-4">
        <?php foreach (catalogoMedidasPrevencion() as $key => $label): $chk = !empty($accionesData['medidas_prevencion'][$key]); ?>
        <div class="check-row"><input type="checkbox" name="medidas_prevencion[<?= $key ?>]" id="mprev_<?= $key ?>" value="1" <?= $chk?'checked':'' ?>><label for="mprev_<?= $key ?>"><?= e($label) ?></label></div>
        <?php endforeach; ?>
    </div>
    <div class="field" style="margin-top:8px;"><label class="req">Otra medida de prevención</label><input name="medida_prevencion_otra" required placeholder="Describa la medida adicional, o escriba "Ninguna"" class="form-control" value="<?= e($accionesData['medidas_prevencion']['otra_texto'] ?? '') ?>"></div>
    <?php endif; ?>

    <div class="section-title"><i class="bi bi-camera-fill"></i> Registro fotográfico</div>
    <div class="form-grid cols-2">
        <?php bloqueFotos('decision', 'Foto de la etiqueta / cartel de decisión colocado', $fotosExistentes, true, 'environment', 'Foto de la calcomanía o cartel de color (verde, amarillo o rojo) ya colocado en la entrada de la edificación.'); ?>
    </div>
</div>

</div>
<div class="card-header" style="border-top:1px solid var(--gris-100);justify-content:space-between;">
    <button type="button" class="btn btn-outline" id="btn-anterior" disabled><i class="bi bi-arrow-left"></i> Anterior</button>
    <div class="flex gap-8">
        <a href="<?= APP_URL_BASE ?>formulario/index.php" class="btn btn-outline">Cancelar</a>
        <button type="button" class="btn btn-primary" id="btn-siguiente">Siguiente <i class="bi bi-arrow-right"></i></button>
        <button type="submit" class="btn btn-accent" id="btn-guardar" style="display:none;"><i class="bi bi-save-fill"></i> Guardar inspección</button>
    </div>
</div>
<div class="progreso-guardado" id="progreso-guardado" aria-live="polite"></div>
</div>
</form>

<!-- Jerarquía territorial (estado → municipio → parroquia) para los
     selectores en cascada de la ubicación. Se emite embebido para no
     requerir una petición extra ni depender de conexión (uso en campo). -->
<script id="territorio-data" type="application/json"><?= json_encode(territorio(), JSON_UNESCAPED_UNICODE) ?></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    // El rol Administrador puede recorrer todos los pasos del formulario sin
    // llenar los campos obligatorios (útil para mostrar/demostrar el sistema).
    // Los demás roles sí deben completar los campos requeridos.
    const ES_ADMIN = <?= (($_SESSION['rol_nombre'] ?? '') === 'Administrador') ? 'true' : 'false' ?>;
    const panes = document.querySelectorAll('.wizard-pane');
    const steps = document.querySelectorAll('.wizard-steps .step');

    // Si es administrador, se quitan las marcas de "obligatorio" de todos los
    // campos para que pueda recorrer y mostrar el formulario completo sin
    // llenarlo. (Al guardar de verdad, el servidor sigue validando lo mínimo.)
    if (ES_ADMIN) {
        document.querySelectorAll('#form-inspeccion [required]').forEach(function (el) {
            el.removeAttribute('required');
        });
    }
    let current = 1;
    const total = panes.length;

    // ---- Botón "Agregar segundo profesional": revela el bloque bajo demanda ----
    document.getElementById('btn-agregar-profesional')?.addEventListener('click', function () {
        document.getElementById('bloque-segundo-profesional').style.display = '';
        this.style.display = 'none';
    });

    // ---- Cascada de ubicación: Estado → Municipio → Parroquia ----
    (function () {
        const TERR = JSON.parse(document.getElementById('territorio-data').textContent || '{}');
        const selEstado = document.getElementById('ubic-estado');
        const selMuni   = document.getElementById('ubic-municipio');
        const selParr   = document.getElementById('ubic-parroquia');
        if (!selEstado || !selMuni || !selParr) return;

        function llenar(sel, valores, actual) {
            const placeholder = sel.options[0] ? sel.options[0].outerHTML : '<option value="">Seleccione…</option>';
            sel.innerHTML = placeholder;
            valores.forEach(v => {
                const o = document.createElement('option');
                o.value = v; o.textContent = v;
                if (v === actual) o.selected = true;
                sel.appendChild(o);
            });
        }
        function municipios(estado) { return TERR[estado] ? Object.keys(TERR[estado]) : []; }
        function parroquias(estado, muni) { return (TERR[estado] && TERR[estado][muni]) ? TERR[estado][muni] : []; }

        function refrescarMunicipios(preservar) {
            const est = selEstado.value;
            const actual = preservar ? (selMuni.dataset.actual || selMuni.value) : '';
            llenar(selMuni, municipios(est), actual);
            refrescarParroquias(preservar);
        }
        function refrescarParroquias(preservar) {
            const est = selEstado.value, mun = selMuni.value;
            const actual = preservar ? (selParr.dataset.actual || selParr.value) : '';
            llenar(selParr, parroquias(est, mun), actual);
        }

        selEstado.addEventListener('change', () => { selMuni.dataset.actual=''; selParr.dataset.actual=''; refrescarMunicipios(false); });
        selMuni.addEventListener('change', () => { selParr.dataset.actual=''; refrescarParroquias(false); });

        // Carga inicial (respeta valores existentes al editar).
        if (selEstado.value) refrescarMunicipios(true);
    })();

    const chkMaterialOtros = document.getElementById('m4');
    const campoOtrosMateriales = document.getElementById('campo-otros-materiales');
    function actualizarCampoOtrosMateriales() {
        if (!campoOtrosMateriales) return;
        const visible = !!chkMaterialOtros?.checked;
        campoOtrosMateriales.style.display = visible ? '' : 'none';
        const input = campoOtrosMateriales.querySelector('input, select, textarea');
        if (input) input.required = visible; // oculto y "required" a la vez bloquearía el envío sin que se vea por qué
    }
    chkMaterialOtros?.addEventListener('change', actualizarCampoOtrosMateriales);
    actualizarCampoOtrosMateriales();

    // ---- "Cantidad de ascensores" y "Estado del tanque de agua": solo se
    // muestran (y son obligatorios) si se marca su checkbox correspondiente
    // (Ascensores / Tiene tanque de agua) en "Servicios y elementos
    // complementarios". Un campo oculto con "required" bloquearía el envío
    // del formulario sin que el usuario viera por qué, así que el
    // "required" se activa/desactiva junto con la visibilidad. ----
    function vincularCampoCondicional(checkboxId, campoId) {
        const chk = document.getElementById(checkboxId);
        const campo = document.getElementById(campoId);
        if (!chk || !campo) return;
        const input = campo.querySelector('input, select, textarea');
        const actualizar = () => {
            campo.style.display = chk.checked ? '' : 'none';
            if (input) input.required = chk.checked;
        };
        chk.addEventListener('change', actualizar);
        actualizar();
    }
    vincularCampoCondicional('ex1', 'campo-cant-ascensores');
    vincularCampoCondicional('ex5', 'campo-estado-tanque');

    // ---- Selector de ingeniero (directorio): al elegir uno, se copian sus
    // datos a los campos ocultos que en verdad se envían (mismos nombres de
    // siempre: ing1_nombre, ing1_cedula, etc.), y se muestran de forma
    // informativa en los campos de solo lectura. Si el select queda vacío
    // (edición de una inspección antigua sin ingeniero asignado todavía),
    // NO se tocan los campos ocultos -- conservan el texto histórico que ya
    // traían, para no perder datos de registros viejos. ----
    document.querySelectorAll('.ingeniero-select').forEach(function (select) {
        const prefix = select.dataset.prefix;
        select.addEventListener('change', function () {
            const opt = select.options[select.selectedIndex];
            const datos = opt && opt.value ? opt.dataset : { nombre: '', cedula: '', telefono: '', profesion: '', colegio: '' };
            document.getElementById(prefix + '_nombre_hidden').value = datos.nombre || '';
            document.getElementById(prefix + '_cedula_hidden').value = datos.cedula || '';
            document.getElementById(prefix + '_telefono_hidden').value = datos.telefono || '';
            document.getElementById(prefix + '_profesion_hidden').value = datos.profesion || '';
            document.getElementById(prefix + '_inscripcion_hidden').value = datos.colegio || '';
            document.getElementById(prefix + '_telefono_display').value = datos.telefono || '';
            document.getElementById(prefix + '_profesion_display').value = datos.profesion || '';
            document.getElementById(prefix + '_inscripcion_display').value = datos.colegio || '';
        });
    });

    // ---- Buscador de ingenieros: filtra las opciones del select mientras
    // se escribe (por nombre o cédula). No se puede ocultar <option> con
    // CSS de forma confiable en todos los navegadores, así que se
    // reconstruye el select con solo las opciones que coinciden. El listado
    // completo (sin filtrar) se guarda aparte por prefijo, y también se
    // actualiza cuando se usa el botón de refrescar -- si no, buscar
    // después de refrescar seguiría filtrando la lista vieja. ----
    const opcionesIngenieroCompletas = {};
    document.querySelectorAll('.ingeniero-select').forEach(function (select) {
        opcionesIngenieroCompletas[select.dataset.prefix] = Array.from(select.options).filter(o => o.value !== '');
    });

    function normalizarTexto(s) {
        return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function filtrarSelectIngeniero(prefix, q) {
        const select = document.getElementById(prefix + '_id');
        const todas = opcionesIngenieroCompletas[prefix] || [];
        if (!select) return;
        q = normalizarTexto((q || '').trim());
        const valorActual = select.value;
        select.innerHTML = '';
        const optDefault = document.createElement('option');
        optDefault.value = '';
        optDefault.textContent = todas.length ? 'Seleccione un ingeniero registrado…' : 'No hay ingenieros registrados';
        select.appendChild(optDefault);

        const coinciden = !q ? todas : todas.filter(o =>
            normalizarTexto(o.dataset.nombre || '').includes(q) || normalizarTexto(o.dataset.cedula || '').includes(q)
        );
        coinciden.forEach(o => select.appendChild(o.cloneNode(true)));

        // Si lo que había seleccionado sigue en los resultados filtrados, se
        // mantiene marcado; si no, la selección real no se pierde (los
        // campos ocultos ya tienen sus datos), solo deja de verse resaltada
        // en el desplegable hasta que se borre el texto de búsqueda.
        if (coinciden.some(o => o.value === valorActual)) {
            select.value = valorActual;
        }
    }

    document.querySelectorAll('.ingeniero-buscar').forEach(function (input) {
        input.addEventListener('input', function () {
            filtrarSelectIngeniero(input.dataset.prefix, input.value);
        });
    });
    function poblarSelectIngenieros(select, lista) {
        opcionesIngenieroCompletas[select.dataset.prefix] = lista.map(function (ing) {
            const opt = document.createElement('option');
            opt.value = ing.id;
            opt.textContent = ing.nombre_completo + ' — ' + ing.cedula;
            opt.dataset.nombre = ing.nombre_completo || '';
            opt.dataset.cedula = ing.cedula || '';
            opt.dataset.telefono = ing.telefono || '';
            opt.dataset.profesion = ing.profesion || '';
            opt.dataset.colegio = ing.colegio_inscripcion || '';
            return opt;
        });
        // Limpia el buscador asociado (si tenía texto, ya no aplicaría a la
        // lista recién actualizada) y vuelve a pintar el select completo.
        const buscador = document.querySelector('.ingeniero-buscar[data-prefix="' + select.dataset.prefix + '"]');
        if (buscador) buscador.value = '';
        filtrarSelectIngeniero(select.dataset.prefix, '');

        const valorActual = select.value;
        if (lista.some(function (ing) { return String(ing.id) === valorActual; })) {
            select.value = valorActual;
        }
    }

    document.querySelectorAll('.btn-refrescar-ingenieros').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const select = btn.parentElement.querySelector('.ingeniero-select');
            const iconoOriginal = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            try {
                const res = await fetch('<?= APP_URL_BASE ?>admin/ingenieros_json.php');
                const data = await res.json();
                poblarSelectIngenieros(select, data.ingenieros || []);
            } catch (e) {
                alert('No se pudo actualizar la lista de ingenieros. Verifique su conexión.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = iconoOriginal;
            }
        });
    });


    // ---- Mapa selector de ubicación (tipo "Google Maps") ----
    let mapaUbicacion = null;
    let marcadorUbicacion = null;
    const inputLat = document.getElementById('input-latitud');
    const inputLng = document.getElementById('input-longitud');
    const readout  = document.getElementById('coords-readout');

    function fijarCoordenadas(lat, lng) {
        inputLat.value = lat.toFixed(7);
        inputLng.value = lng.toFixed(7);
        readout.textContent = lat.toFixed(7) + ', ' + lng.toFixed(7);
        document.querySelector('#mapa-ubicacion .mapa-selector-hint')?.classList.remove('mapa-hint-error');
    }

    function initMapaUbicacion() {
        if (mapaUbicacion) return;

        const latInicial = parseFloat(inputLat.value) || 10.4880;
        const lngInicial = parseFloat(inputLng.value) || -66.9200;
        const zoomInicial = (inputLat.value && inputLng.value) ? 17 : 12;

        mapaUbicacion = L.map('mapa-ubicacion').setView([latInicial, lngInicial], zoomInicial);
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri',
            maxZoom: 19,
        }).addTo(mapaUbicacion);

        if (inputLat.value && inputLng.value) {
            marcadorUbicacion = L.marker([latInicial, lngInicial], { draggable: true }).addTo(mapaUbicacion);
            marcadorUbicacion.on('dragend', () => {
                const p = marcadorUbicacion.getLatLng();
                fijarCoordenadas(p.lat, p.lng);
            });
        }

        mapaUbicacion.on('click', (e) => {
            const { lat, lng } = e.latlng;
            if (marcadorUbicacion) {
                marcadorUbicacion.setLatLng(e.latlng);
            } else {
                marcadorUbicacion = L.marker(e.latlng, { draggable: true }).addTo(mapaUbicacion);
                marcadorUbicacion.on('dragend', () => {
                    const p = marcadorUbicacion.getLatLng();
                    fijarCoordenadas(p.lat, p.lng);
                });
            }
            fijarCoordenadas(lat, lng);
        });
    }

    document.getElementById('btn-geo')?.addEventListener('click', () => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(pos => {
            const { latitude, longitude } = pos.coords;
            fijarCoordenadas(latitude, longitude);
            if (mapaUbicacion) {
                mapaUbicacion.setView([latitude, longitude], 17);
                if (marcadorUbicacion) {
                    marcadorUbicacion.setLatLng([latitude, longitude]);
                } else {
                    marcadorUbicacion = L.marker([latitude, longitude], { draggable: true }).addTo(mapaUbicacion);
                    marcadorUbicacion.on('dragend', () => {
                        const p = marcadorUbicacion.getLatLng();
                        fijarCoordenadas(p.lat, p.lng);
                    });
                }
            }
        }, () => {}, { enableHighAccuracy: true, timeout: 8000 });
    });

    // ---- Navegación del wizard ----
    function render() {
        panes.forEach(p => p.classList.toggle('active', +p.dataset.pane === current));
        steps.forEach(s => {
            const n = +s.dataset.step;
            s.classList.toggle('active', n === current);
            s.classList.toggle('done', n < current);
        });
        document.getElementById('btn-anterior').disabled = current === 1;
        document.getElementById('btn-siguiente').style.display = current === total ? 'none' : 'inline-flex';
        document.getElementById('btn-guardar').style.display = current === total ? 'inline-flex' : 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });

        if (current === 2) {
            initMapaUbicacion();
            setTimeout(() => mapaUbicacion && mapaUbicacion.invalidateSize(), 150);
        }
    }

    /**
     * Valida el paso actual antes de dejarlo avanzar (usado tanto por el
     * botón "Siguiente" como por el clic directo sobre un número de paso
     * más adelante -- si no, alguien podía saltarse la validación tocando
     * el "2" del stepper en vez del botón).
     */
    function validarPasoActual() {
        // El administrador puede avanzar libremente para mostrar el sistema.
        if (ES_ADMIN) return true;
        const pane = document.querySelector('.wizard-pane.active');
        const stepActual = document.querySelector('.wizard-steps .step[data-step="' + current + '"]');
        const invalid = pane.querySelector(':invalid');
        if (invalid) {
            invalid.reportValidity();
            stepActual?.classList.add('con-error');
            return false;
        }

        // Paso 1: no se puede avanzar sin el profesional principal. El
        // select ya tiene "required" (la validación nativa de arriba ya lo
        // cubre), pero se agrega este chequeo explícito -- un aviso claro
        // en vez de solo el globito discreto del navegador, para que a
        // nadie se le pase por alto.
        if (current === 1) {
            const ing1 = document.getElementById('ing1_id');
            if (!ing1 || !ing1.value) {
                alert('Debes seleccionar el profesional responsable de la inspección antes de continuar.');
                ing1?.focus();
                stepActual?.classList.add('con-error');
                return false;
            }
        }

        // La ubicación en el mapa se guarda en campos ocultos (latitud/
        // longitud): un input oculto "required" no le muestra nada visible
        // al usuario si falla, así que se valida aparte con un aviso claro.
        if (current === 2 && (!inputLat.value || !inputLng.value)) {
            const hint = document.querySelector('#mapa-ubicacion .mapa-selector-hint');
            if (hint) {
                hint.classList.add('mapa-hint-error');
                hint.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            alert('Falta marcar la ubicación de la edificación en el mapa.\nToca o haz clic sobre el mapa para colocar el marcador antes de continuar.');
            stepActual?.classList.add('con-error');
            return false;
        }

        stepActual?.classList.remove('con-error');
        return true;
    }

    document.getElementById('btn-siguiente').addEventListener('click', () => {
        if (!validarPasoActual()) return;
        if (current < total) { current++; render(); }
    });
    document.getElementById('btn-anterior').addEventListener('click', () => { if (current > 1) { current--; render(); } });

    steps.forEach(s => s.addEventListener('click', () => {
        const destino = +s.dataset.step;
        if (destino > current && !validarPasoActual()) return; // avanzando: debe validar; retrocediendo, no hace falta
        current = destino;
        render();
    }));

    render();

    // Recalcula el tamaño del mapa cuando cambia el layout (sidebar, resize)
    window.addEventListener('sismos:layout-change', () => {
        if (mapaUbicacion) mapaUbicacion.invalidateSize();
    });

    // ---- Envío con soporte offline ----
    const form = document.getElementById('form-inspeccion');
    const btnGuardar = document.getElementById('btn-guardar');
    const textoOriginalBtn = btnGuardar.innerHTML;
    const cajaProgreso = document.getElementById('progreso-guardado');
    const INDEX_URL = '<?= APP_URL_BASE ?>formulario/index.php';
    const PROGRESO_URL = '<?= APP_URL_BASE ?>formulario/progreso.php';

    // ---- Badge de progreso (debajo del botón) ----
    // Muestra en vivo qué parte del guardado ya terminó el servidor, para
    // que la espera no se sienta como que "no está pasando nada". Se
    // alimenta consultando formulario/progreso.php mientras el guardado
    // real ocurre en paralelo — no es una animación simulada.
    const ICONOS_PASO = {
        pendiente:   '<i class="bi bi-circle"></i>',
        en_progreso: '<i class="bi bi-arrow-repeat girando"></i>',
        listo:       '<i class="bi bi-check-circle-fill"></i>',
        error:       '<i class="bi bi-exclamation-circle-fill"></i>',
    };

    function renderizarProgreso(pasos) {
        if (!pasos || !pasos.length) {
            cajaProgreso.innerHTML = '<div class="paso-progreso en_progreso">' + ICONOS_PASO.en_progreso + ' Enviando…</div>';
            return;
        }
        cajaProgreso.innerHTML = pasos.map(function (p) {
            const icono = ICONOS_PASO[p.estado] || ICONOS_PASO.pendiente;
            return '<div class="paso-progreso ' + p.estado + '">' + icono + ' ' + p.texto + '</div>';
        }).join('');
    }

    let pollingId = null;
    function iniciarPollingProgreso(token) {
        cajaProgreso.classList.add('activo');
        renderizarProgreso(null); // feedback inmediato, antes de la primera respuesta del servidor
        let intentos = 0;
        pollingId = setInterval(async function () {
            intentos++;
            if (intentos > 240) { // ~2 minutos a 500ms: red de seguridad, no debería llegar aquí
                detenerPollingProgreso();
                return;
            }
            try {
                const r = await fetch(PROGRESO_URL + '?token=' + encodeURIComponent(token), { credentials: 'same-origin' });
                if (!r.ok) return;
                const data = await r.json();
                renderizarProgreso(data.pasos);
            } catch (e) {
                // Un fallo puntual de polling no es grave: se reintenta en el próximo tick.
            }
        }, 500);
    }
    function detenerPollingProgreso() {
        if (pollingId) {
            clearInterval(pollingId);
            pollingId = null;
        }
    }

    // Un solo ID por "intento de guardado" de esta página: si el primer
    // envío falla y se reintenta (offline o por red inestable), viaja el
    // mismo ID, así el servidor puede reconocer que es un reintento y no
    // duplicar nada. Si el usuario termina y arranca una inspección nueva
    // (recarga la página), se genera uno distinto.
    (function inicializarClientSubmissionId() {
        const input = document.getElementById('client_submission_id');
        if (window.crypto && crypto.randomUUID) {
            input.value = crypto.randomUUID();
        } else {
            input.value = 'csid-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        }
    })();

    function mostrarConfirmacionOffline() {
        // OJO: no navegamos a otra página — si de verdad no hay señal, esa
        // navegación tampoco cargaría. La confirmación queda en esta misma
        // página; el usuario decide cuándo volver al listado.
        const wizard = form;
        wizard.style.display = 'none';
        const aviso = document.createElement('div');
        aviso.className = 'card';
        aviso.style.padding = '32px';
        aviso.style.textAlign = 'center';
        aviso.innerHTML = `
            <div style="font-size:40px;color:var(--amarillo);margin-bottom:10px;"><i class="bi bi-cloud-arrow-up"></i></div>
            <h2 style="margin:0 0 8px;">Guardado localmente</h2>
            <p class="text-sm text-muted" style="max-width:420px;margin:0 auto 20px;">
                No hay conexión en este momento. La inspección quedó guardada en este
                dispositivo y se subirá sola en cuanto vuelva la señal (no hace falta
                que hagas nada más).
            </p>
            <div class="flex gap-8" style="justify-content:center;">
                <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Registrar otra</a>
                <a href="${INDEX_URL}" class="btn btn-outline">Volver al listado</a>
            </div>
        `;
        wizard.after(aviso);
    }

    async function guardarOffline() {
        const formData = new FormData(form);
        await window.SismosOffline.guardarPendiente(form.action, formData, {
            nombre_edificio: (document.querySelector('[name="nombre_edificio"]') || {}).value || '',
        });
        await window.SismosOffline.actualizarBadge();
        mostrarConfirmacionOffline();
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const invalid = form.querySelector(':invalid');
        if (invalid) {
            // El campo inválido puede estar en un paso distinto al actual
            // (p. ej. si se saltó un paso con los números de arriba). Si no
            // navegamos ahí primero, reportValidity() no muestra nada
            // porque el campo está oculto (display:none).
            const pane = invalid.closest('.wizard-pane');
            if (pane && !pane.classList.contains('active')) {
                current = +pane.dataset.pane;
                render();
            }
            invalid.reportValidity();
            return;
        }

        btnGuardar.disabled = true;

        if (!navigator.onLine) {
            btnGuardar.innerHTML = '<i class="bi bi-cloud-arrow-up"></i> Guardando localmente…';
            cajaProgreso.classList.add('activo');
            cajaProgreso.innerHTML = '<div class="paso-progreso en_progreso"><i class="bi bi-cloud-arrow-up"></i> Sin conexión: guardando en este dispositivo…</div>';
            try {
                await guardarOffline();
            } catch (err) {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = textoOriginalBtn;
                cajaProgreso.classList.remove('activo');
                cajaProgreso.innerHTML = '';
                alert('No se pudo guardar localmente. Verifica que el navegador permita almacenamiento (IndexedDB).');
            }
            return;
        }

        btnGuardar.innerHTML = '<i class="bi bi-arrow-repeat girando"></i> Guardando…';
        const tokenEnvio = document.getElementById('client_submission_id').value;
        iniciarPollingProgreso(tokenEnvio);
        try {
            const formData = new FormData(form);
            const resp = await fetch(form.action, { method: 'POST', body: formData, credentials: 'same-origin' });
            detenerPollingProgreso();
            window.location.href = resp.redirected ? resp.url : INDEX_URL;
        } catch (err) {
            // La red falló justo al enviar (típico de señal intermitente): no se pierde nada.
            detenerPollingProgreso();
            btnGuardar.innerHTML = '<i class="bi bi-cloud-arrow-up"></i> Guardando localmente…';
            cajaProgreso.innerHTML = '<div class="paso-progreso en_progreso"><i class="bi bi-cloud-arrow-up"></i> Sin conexión: guardando en este dispositivo…</div>';
            try {
                await guardarOffline();
            } catch (err2) {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = textoOriginalBtn;
                cajaProgreso.classList.remove('activo');
                cajaProgreso.innerHTML = '';
                alert('Se perdió la conexión y no se pudo guardar localmente. Intenta de nuevo.');
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
