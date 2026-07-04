<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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
    $fotosExistentes = obtenerFotosInspeccion($editId);
} else {
    requierePermiso('formulario', 'crear');
    $danosEst = [];
    $danosNo  = [];
    $extra    = [];
}

function val($row, $key, $default = '') {
    return $row[$key] ?? $default;
}

/** Imprime el bloque de subida de fotos + miniaturas existentes para una categoría dada. */
function bloqueFotos(string $categoria, string $etiqueta, array $fotosExistentes, bool $multiple = true, string $capture = 'environment'): void {
    $existentes = $fotosExistentes[$categoria] ?? [];
    ?>
    <div class="foto-input-box">
        <label><i class="bi bi-camera-fill"></i> <?= e($etiqueta) ?></label>
        <input type="file" name="fotos[<?= e($categoria) ?>][]" accept="image/*" capture="<?= e($capture) ?>"<?= $multiple ? ' multiple' : '' ?>>
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
        <?php endif; ?>
    </div>
    <?php
}

$pageTitle    = $editId ? 'Editar inspección' : 'Nueva inspección';
$pageSubtitle = 'Instrumento para Inspección de Edificaciones Afectadas por Sismos';
$activeModule = 'formulario';

$parroquias      = catalogoParroquias();
sort($parroquias, SORT_LOCALE_STRING);
$usos            = catalogoUsoEdificacion();
$tiposEstruct    = catalogoTipoEstructural();
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
    <div class="step active" data-step="1">1. Profesionales</div>
    <div class="step" data-step="2">2. Identificación y ubicación</div>
    <div class="step" data-step="3">3. Características</div>
    <div class="step" data-step="4">4. Riesgo y colapso</div>
    <div class="step" data-step="5">5. Daños estructurales</div>
    <div class="step" data-step="6">6. Daños no estructurales</div>
    <div class="step" data-step="7">7. Personas afectadas</div>
    <div class="step" data-step="8">8. Decisión y recomendaciones</div>
</div>

<div class="card">
<div class="card-body">

<!-- PASO 1 -->
<div class="wizard-pane active" data-pane="1">
    <div class="section-title"><i class="bi bi-person-badge-fill"></i> Profesional responsable de la inspección</div>
    <div class="form-grid">
        <div class="field"><label class="req">Nombre y apellido</label><input required name="ing1_nombre" class="form-control" value="<?= e(val($row,'ing1_nombre')) ?>"></div>
        <div class="field"><label class="req">Cédula</label><input required name="ing1_cedula" class="form-control" value="<?= e(val($row,'ing1_cedula')) ?>"></div>
        <div class="field"><label>Teléfono</label><input name="ing1_telefono" class="form-control" value="<?= e(val($row,'ing1_telefono')) ?>"></div>
        <div class="field"><label>Profesión</label><input name="ing1_profesion" class="form-control" value="<?= e(val($row,'ing1_profesion')) ?>"></div>
        <div class="field"><label>N° de inscripción en el colegio de ingenieros</label><input name="ing1_inscripcion" class="form-control" value="<?= e(val($row,'ing1_inscripcion')) ?>"></div>
    </div>
    <div class="form-grid cols-2">
        <?php bloqueFotos('foto_inspector', 'Foto del inspector (tipo carnet)', $fotosExistentes, false, 'user'); ?>
    </div>

    <div class="section-title"><i class="bi bi-person-badge"></i> Segundo profesional (opcional)</div>
    <div class="form-grid">
        <div class="field"><label>Nombre y apellido</label><input name="ing2_nombre" class="form-control" value="<?= e(val($row,'ing2_nombre')) ?>"></div>
        <div class="field"><label>Cédula</label><input name="ing2_cedula" class="form-control" value="<?= e(val($row,'ing2_cedula')) ?>"></div>
        <div class="field"><label>Teléfono</label><input name="ing2_telefono" class="form-control" value="<?= e(val($row,'ing2_telefono')) ?>"></div>
        <div class="field"><label>Profesión</label><input name="ing2_profesion" class="form-control" value="<?= e(val($row,'ing2_profesion')) ?>"></div>
        <div class="field"><label>N° de inscripción en el colegio de ingenieros</label><input name="ing2_inscripcion" class="form-control" value="<?= e(val($row,'ing2_inscripcion')) ?>"></div>
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
        <div class="field"><label>Cantidad de apartamentos</label><input type="number" min="0" name="cantidad_apartamentos" class="form-control" value="<?= e(val($row,'cantidad_apartamentos', 0)) ?>"></div>
        <div class="field"><label>N° de pisos</label><input type="number" min="0" name="num_pisos" class="form-control" value="<?= e(val($row,'num_pisos', 0)) ?>"></div>
        <div class="field"><label>N° de semisótanos</label><input type="number" min="0" name="num_semisotanos" class="form-control" value="<?= e(val($row,'num_semisotanos', 0)) ?>"></div>
        <div class="field"><label>N° de sótanos</label><input type="number" min="0" name="num_sotanos" class="form-control" value="<?= e(val($row,'num_sotanos', 0)) ?>"></div>
    </div>

    <div class="section-title"><i class="bi bi-geo-alt-fill"></i> Ubicación</div>
    <div class="form-grid">
        <div class="field"><label>Estado</label><input name="estado" class="form-control" value="<?= e(val($row,'estado','Distrito Capital')) ?>"></div>
        <div class="field"><label>Ciudad</label><input name="ciudad" class="form-control" value="<?= e(val($row,'ciudad','Caracas')) ?>"></div>
        <div class="field"><label>Municipio</label><input name="municipio" class="form-control" value="<?= e(val($row,'municipio','Libertador')) ?>"></div>
        <div class="field">
            <label class="req">Parroquia</label>
            <select required name="parroquia" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach ($parroquias as $p): ?>
                    <option value="<?= e($p) ?>" <?= val($row,'parroquia') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Comuna o circuito</label><input name="comuna_circuito" class="form-control" value="<?= e(val($row,'comuna_circuito')) ?>"></div>
        <div class="field"><label>Urbanización</label><input name="urbanizacion" class="form-control" value="<?= e(val($row,'urbanizacion')) ?>"></div>
        <div class="field"><label>Sector</label><input name="sector" class="form-control" value="<?= e(val($row,'sector')) ?>"></div>
        <div class="field"><label>Avenida o calle</label><input name="avenida_calle" class="form-control" value="<?= e(val($row,'avenida_calle')) ?>"></div>
        <div class="field"><label>Nombre de la comunidad</label><input name="nombre_comunidad" class="form-control" value="<?= e(val($row,'nombre_comunidad')) ?>"></div>
        <div class="field"><label>Huso</label><input name="huso" class="form-control" value="<?= e(val($row,'huso')) ?>"></div>
    </div>

    <label style="margin-top:6px;"><i class="bi bi-geo-fill"></i> Ubicación en el mapa</label>
    <p class="help-text" style="margin-top:-2px;margin-bottom:8px;">Toque o haga clic sobre el mapa para colocar el marcador en la edificación. Puede arrastrarlo para ajustar la posición.</p>
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
        <?php bloqueFotos('general', 'Vista general de la edificación / fachada', $fotosExistentes); ?>
    </div>
</div>

<!-- PASO 3 -->
<div class="wizard-pane" data-pane="3">
    <div class="section-title"><i class="bi bi-diagram-3-fill"></i> Características constructivas</div>
    <div class="form-grid cols-2">
        <div class="field">
            <label>Uso de la edificación</label>
            <select name="uso_edificacion" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach ($usos as $u): ?><option <?= val($row,'uso_edificacion')===$u?'selected':'' ?>><?= e($u) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Tipo estructural</label>
            <select name="tipo_estructural" class="form-control">
                <option value="">Seleccione…</option>
                <?php foreach ($tiposEstruct as $t): ?><option <?= val($row,'tipo_estructural')===$t?'selected':'' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <label style="margin-top:10px;">Materiales presentes</label>
    <div class="form-grid cols-4">
        <div class="check-row"><input type="checkbox" name="material_acero" id="m1" value="1" <?= val($row,'material_acero')?'checked':'' ?>><label for="m1">Acero</label></div>
        <div class="check-row"><input type="checkbox" name="material_conexiones" id="m2" value="1" <?= val($row,'material_conexiones')?'checked':'' ?>><label for="m2">Conexiones</label></div>
        <div class="check-row"><input type="checkbox" name="material_mamposteria" id="m3" value="1" <?= val($row,'material_mamposteria')?'checked':'' ?>><label for="m3">Mampostería</label></div>
        <div class="check-row"><input type="checkbox" name="material_otros" id="m4" value="1" <?= val($row,'material_otros')?'checked':'' ?>><label for="m4">Otros</label></div>
    </div>
    <div class="field"><label>Especifique otros materiales</label><input name="material_otros_especifique" class="form-control" value="<?= e(val($row,'material_otros_especifique')) ?>"></div>
</div>

<!-- PASO 4 -->
<div class="wizard-pane" data-pane="4">
    <div class="section-title"><i class="bi bi-exclamation-diamond-fill"></i> Evaluación de riesgo general</div>
    <div class="form-grid cols-2">
        <div class="field">
            <label>Colapso de la estructura</label>
            <select name="colapso_estructura" class="form-control">
                <?php foreach (['No','Parcial','Total'] as $o): ?><option <?= val($row,'colapso_estructura','No')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Riesgo de edificios aledaños</label>
            <select name="riesgo_edificios_aledanos" class="form-control">
                <option value="No" <?= val($row,'riesgo_edificios_aledanos')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'riesgo_edificios_aledanos')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field">
            <label>Amenaza geológica</label>
            <select name="amenaza_geologica" class="form-control">
                <option value="No" <?= val($row,'amenaza_geologica')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'amenaza_geologica')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field">
            <label>Asentamiento del edificio</label>
            <select name="asentamiento_edificio" class="form-control">
                <option value="No" <?= val($row,'asentamiento_edificio')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'asentamiento_edificio')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field">
            <label>Inclinación del edificio</label>
            <select name="inclinacion_edificio" class="form-control">
                <option value="No" <?= val($row,'inclinacion_edificio')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'inclinacion_edificio')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field">
            <label>¿Se requiere inspección interna?</label>
            <select name="requiere_inspeccion_interna" class="form-control">
                <option value="No" <?= val($row,'requiere_inspeccion_interna','No')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'requiere_inspeccion_interna')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
    </div>
</div>

<!-- PASO 5 -->
<div class="wizard-pane" data-pane="5">
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
                <?php bloqueFotos($key, 'Fotos de ' . $label, $fotosExistentes); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title"><i class="bi bi-tools"></i> Intervención y porcentaje de daño</div>
    <div class="form-grid">
        <div class="field">
            <label>Requiere de intervención</label>
            <select name="requiere_intervencion" class="form-control">
                <option value="No" <?= val($row,'requiere_intervencion','No')==='No'?'selected':'' ?>>No</option>
                <option value="Si" <?= val($row,'requiere_intervencion')==='Si'?'selected':'' ?>>Sí</option>
            </select>
        </div>
        <div class="field"><label>% Daño III (Moderado)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_iii" class="form-control" value="<?= e(val($row,'pct_dano_iii', 0)) ?>"></div>
        <div class="field"><label>% Daño IV (Severo)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_iv" class="form-control" value="<?= e(val($row,'pct_dano_iv', 0)) ?>"></div>
        <div class="field"><label>% Daño V (Completo)</label><input type="number" step="0.01" min="0" max="100" name="pct_dano_v" class="form-control" value="<?= e(val($row,'pct_dano_v', 0)) ?>"></div>
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
                <?php bloqueFotos($key, 'Fotos de ' . $label, $fotosExistentes); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title"><i class="bi bi-droplet-half"></i> Servicios y elementos complementarios</div>
    <div class="form-grid cols-4">
        <div class="check-row"><input type="checkbox" name="extra_ascensores" id="ex1" value="1" <?= !empty($extra['ascensores'])?'checked':'' ?>><label for="ex1">Ascensores</label></div>
        <div class="check-row"><input type="checkbox" name="extra_fuga_gas" id="ex2" value="1" <?= !empty($extra['fuga_gas'])?'checked':'' ?>><label for="ex2">Fuga de gas</label></div>
        <div class="check-row"><input type="checkbox" name="extra_fallas_electricas" id="ex3" value="1" <?= !empty($extra['fallas_electricas'])?'checked':'' ?>><label for="ex3">Fallas eléctricas</label></div>
        <div class="check-row"><input type="checkbox" name="extra_danos_aguas" id="ex4" value="1" <?= !empty($extra['danos_aguas'])?'checked':'' ?>><label for="ex4">Daños en aguas</label></div>
    </div>
    <div class="form-grid cols-2" style="margin-top:6px;">
        <div class="field"><label>Cantidad de ascensores</label><input type="number" min="0" name="extra_cant_ascensores" class="form-control" value="<?= e($extra['cant_ascensores'] ?? '') ?>"></div>
        <div class="field"><label>Estado del tanque de agua</label><input name="extra_estado_tanque" class="form-control" value="<?= e($extra['estado_tanque'] ?? '') ?>"></div>
    </div>
</div>

<!-- PASO 7 -->
<div class="wizard-pane" data-pane="7">
    <div class="section-title"><i class="bi bi-people-fill"></i> Personas y animales afectados</div>
    <div class="form-grid cols-4">
        <div class="field"><label>Familias</label><input type="number" min="0" name="familias" class="form-control" value="<?= e(val($row,'familias', 0)) ?>"></div>
        <div class="field"><label>Hombres</label><input type="number" min="0" name="hombres" class="form-control" value="<?= e(val($row,'hombres', 0)) ?>"></div>
        <div class="field"><label>Mujeres</label><input type="number" min="0" name="mujeres" class="form-control" value="<?= e(val($row,'mujeres', 0)) ?>"></div>
        <div class="field"><label>Niños</label><input type="number" min="0" name="ninos" class="form-control" value="<?= e(val($row,'ninos', 0)) ?>"></div>
        <div class="field"><label>Adultos de 3ra edad</label><input type="number" min="0" name="adultos_tercera_edad" class="form-control" value="<?= e(val($row,'adultos_tercera_edad', 0)) ?>"></div>
        <div class="field"><label>Gestantes</label><input type="number" min="0" name="gestantes" class="form-control" value="<?= e(val($row,'gestantes', 0)) ?>"></div>
        <div class="field"><label>Movilidad reducida</label><input type="number" min="0" name="movilidad_reducida" class="form-control" value="<?= e(val($row,'movilidad_reducida', 0)) ?>"></div>
        <div class="field"><label>Mascotas</label><input type="number" min="0" name="mascotas" class="form-control" value="<?= e(val($row,'mascotas', 0)) ?>"></div>
    </div>

    <div class="section-title"><i class="bi bi-hammer"></i> Recursos requeridos para intervención</div>
    <div class="form-grid cols-4">
        <div class="field"><label>Tiempo de acción (días)</label><input type="number" min="0" name="extra_tiempo_accion" class="form-control" value="<?= e($extra['tiempo_accion'] ?? '') ?>"></div>
        <div class="field"><label>Mano de obra requerida</label><input name="extra_mano_obra" class="form-control" value="<?= e($extra['mano_obra'] ?? '') ?>"></div>
        <div class="field"><label>Herramientas requeridas</label><input name="extra_herramientas" class="form-control" value="<?= e($extra['herramientas'] ?? '') ?>"></div>
        <div class="field"><label>Maquinaria requerida</label><input name="extra_maquinarias" class="form-control" value="<?= e($extra['maquinarias'] ?? '') ?>"></div>
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
        <div class="field"><label>Etiqueta de inspección previa (si aplica)</label><input name="inspeccion_previa_etiqueta" class="form-control" value="<?= e(val($row,'inspeccion_previa_etiqueta')) ?>"></div>
        <div class="field"><label>Inspección especializada</label><input name="inspeccion_especializada" class="form-control" value="<?= e(val($row,'inspeccion_especializada')) ?>"></div>
        <div class="field"><label>Intervención de</label><input name="intervencion_de" class="form-control" value="<?= e(val($row,'intervencion_de')) ?>"></div>
    </div>
    <div class="form-grid cols-2">
        <div class="field"><label>Medidas de seguridad</label><textarea name="medidas_seguridad" class="form-control"><?= e(val($row,'medidas_seguridad')) ?></textarea></div>
        <div class="field"><label>Lugares del edificio para aplicar medidas</label><textarea name="lugares_medidas" class="form-control"><?= e(val($row,'lugares_medidas')) ?></textarea></div>
        <div class="field"><label>Observaciones</label><textarea name="observaciones" class="form-control"><?= e(val($row,'observaciones')) ?></textarea></div>
        <div class="field"><label>Recomendaciones</label><textarea name="recomendaciones" class="form-control"><?= e(val($row,'recomendaciones')) ?></textarea></div>
    </div>

    <div class="section-title"><i class="bi bi-camera-fill"></i> Registro fotográfico</div>
    <div class="form-grid cols-2">
        <?php bloqueFotos('decision', 'Foto de la etiqueta / cartel de decisión colocado', $fotosExistentes); ?>
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    const panes = document.querySelectorAll('.wizard-pane');
    const steps = document.querySelectorAll('.wizard-steps .step');
    let current = 1;
    const total = panes.length;

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

    document.getElementById('btn-siguiente').addEventListener('click', () => {
        const pane = document.querySelector('.wizard-pane.active');
        const invalid = pane.querySelector(':invalid');
        if (invalid) { invalid.reportValidity(); return; }
        if (current < total) { current++; render(); }
    });
    document.getElementById('btn-anterior').addEventListener('click', () => { if (current > 1) { current--; render(); } });

    steps.forEach(s => s.addEventListener('click', () => { current = +s.dataset.step; render(); }));

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
