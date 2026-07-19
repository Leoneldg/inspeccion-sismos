<?php
/**
 * Levantamiento técnico del edificio (wizard por pasos).
 * Flujo pensado para el recorrido físico real del inspector:
 *   Paso 1: datos del edificio (pisos, aptos por piso, áreas comunes).
 *   Paso 2: recorrido PISO POR PISO. En cada piso: elementos comunes
 *           (ascensor, escaleras…) + los apartamentos de ese piso.
 *   Paso 3: cierre (azotea, tanques, impermeabilización) + resumen final.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
$puedeEditar = puede('seguimiento', 'editar');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) {
    flash('error', 'Edificio no especificado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp) {
    flash('error', 'El edificio no existe.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}
$ed = recEdificio($inspeccionId);
$edificioIdActual = (int)($ed['id'] ?? 0);

// Solo edita quien hizo el levantamiento (o un administrador). Los demás
// lo ven en modo consulta: así nadie modifica el trabajo de otro.
$esAutor = $edificioIdActual > 0 ? recPuedeEditarLevantamiento($edificioIdActual) : true;
$soloLectura = !$esAutor;
if ($soloLectura) $puedeEditar = false;

$autor = $edificioIdActual > 0 ? recAutorLevantamiento($edificioIdActual) : [];

$pisos = recPisos((int)$ed['id']);
$tiposElem = recTiposElementoPiso();

// Color/decisión de la edificación para la intro.
$catDecision = catalogoDecisionFinal();
$decisionEd = $insp['decision_final'] ?? '';
$colorEd = $catDecision[$decisionEd]['color'] ?? '#767c94';
$colorCorto = $catDecision[$decisionEd]['corto'] ?? ($decisionEd ?: 'Sin clasificar');

$pageTitle    = 'Levantamiento técnico';
$pageSubtitle = '';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>

<style>
    .wz-wrap { max-width:920px; margin:0 auto; }
    .wz-steps { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
    .wz-step { flex:1; min-width:120px; padding:10px 14px; border-radius:10px; background:#f2f5fc;
               border:1px solid #e6e9f2; color:#767c94; font-size:13px; cursor:pointer; }
    .wz-step.activo { background:#eaf0ff; border-color:#22366F; color:#22366F; font-weight:600; }
    .wz-step.hecho { background:#e5f7ee; color:#2E7D32; }
    .wz-step .n { display:inline-block; width:22px; height:22px; border-radius:50%; background:#fff;
                  text-align:center; line-height:22px; font-weight:700; margin-right:6px; font-size:12px; }
    .wz-panel { background:#fff; border:1px solid #e6e9f2; border-radius:12px; padding:22px 24px; }
    .wz-panel h3 { margin:0 0 4px; color:#22366F; font-size:16px; }
    .wz-panel .sub { color:#767c94; font-size:13px; margin:0 0 18px; }
    .hidden { display:none; }
    .campo-estado { margin:12px 0; }
    .campo-estado .tit { display:block; font-weight:600; font-size:13px; color:#2a3140; margin-bottom:5px; }
    .seg-radio { display:inline-flex; align-items:center; gap:5px; margin-right:14px; font-size:13px; color:#2a3140; cursor:pointer; }
    /* Piso: cada uno es una sección grande y colapsable */
    .piso-card { border:1px solid #d9e0ee; border-radius:8px; margin-bottom:6px; overflow:hidden; }
    .piso-head { padding:13px 16px; background:#22366F; color:#fff; cursor:pointer; font-weight:600;
                 display:flex; align-items:center; justify-content:space-between; }
    .piso-head .estado { font-size:11px; font-weight:500; opacity:.85; }
    .piso-body { padding:16px 18px; }
    .bloque-tit { font-size:13px; font-weight:700; color:#22366F; margin:6px 0 10px; text-transform:uppercase; letter-spacing:.4px; }
    .elem-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:8px 0; border-bottom:1px solid #f0f2f7; }
    .elem-nom { font-weight:600; color:#2a3140; min-width:130px; }
    .apto-card { border:1px solid #e6e9f2; border-radius:10px; margin:10px 0; overflow:hidden; }
    .apto-head { padding:10px 14px; background:#f2f5fc; cursor:pointer; font-weight:600; color:#22366F; }
    .apto-body { padding:12px 14px; }
    .subiendo { font-size:12px; color:#767c94; }

    /* --- Ajustes para teléfono: compactar para que no se colapse --- */
    @media (max-width: 620px) {
        .wz-panel { padding:16px 14px; }
        .wz-step { min-width:0; font-size:11px; padding:8px 6px; flex-basis:30%; }
        .wz-step .n { width:18px; height:18px; line-height:18px; margin-right:3px; }
        /* Cada elemento del piso se apila en vertical, ocupando el ancho */
        .elem-row { flex-direction:column; align-items:stretch; gap:6px; padding:12px 0; }
        .elem-nom { min-width:0; font-size:14px; }
        .elem-row .el-estado { width:100% !important; }
        .elem-row .seg-radio { margin-right:0; }
        .elem-row .btn { width:100%; justify-content:center; }
        /* Los campos de m² a reparar, 2 por fila */
        .amb-reparacion [style*="width:110px"], .amb-reparacion [style*="width:120px"] { width:calc(50% - 4px) !important; }
        .apto-body .field { width:calc(50% - 5px) !important; }

        /* --- Paso 1: los tres campos (pisos / aptos / total) uno por fila --- */
        #paso-1 .field { flex:1 1 100% !important; min-width:0 !important; }

        /* --- Selector de piso a ancho completo --- */
        #selector-piso { width:100% !important; }
        #paso-2 .field[style*="max-width"] { max-width:100% !important; }

        /* --- Jefe de familia: un campo por fila (se escribe con el pulgar) --- */
        .jefe-field { width:100% !important; flex:1 1 100% !important; }

        /* --- Áreas comunes: una por fila --- */
        .area-row { flex-direction:column !important; align-items:stretch !important; gap:8px !important; }
        .area-row .form-control, .area-row .btn { width:100% !important; }
        .area-row .area-m2, .area-row .area-trabajo { width:100% !important; }

        /* --- Cierre: los radios de estado no se aprietan --- */
        .campo-estado { flex-wrap:wrap !important; }
        .campo-estado .seg-radio { flex:1 1 46%; }

        /* Botonera del wizard */
        .wz-panel > div:last-child .btn { width:100%; justify-content:center; margin-bottom:6px; }
    }

    /* Pantallas muy angostas: los ambientes, 2 por fila */
    @media (max-width: 400px) {
        .apto-body .field { width:calc(50% - 4px) !important; }
        .wz-step { flex-basis:100%; }
    }

    /* --- Ajustes táctiles para trabajo en campo --- */
    @media (max-width: 900px) {
        /* 16px evita que iOS haga zoom automático al tocar un campo */
        .form-control, input[type=text], input[type=number], input[type=tel], input[type=date], select, textarea {
            font-size: 16px !important;
        }
        /* Área de toque cómoda (mínimo recomendado: 44px) */
        .btn, .btn-sm { min-height: 42px; display: inline-flex; align-items: center; justify-content: center; }
        .seg-radio { padding: 6px 0; display: inline-flex; align-items: center; gap: 6px; }
        .seg-radio input[type=checkbox], .seg-radio input[type=radio] { width: 20px; height: 20px; }
        /* Los sliders de avance necesitan más altura para el dedo */
        input[type=range] { height: 34px; }
    }
</style>

<?php if ($soloLectura): ?>
<div style="background:#eef2fb;border:2px solid #22366F33;border-radius:11px;
            padding:14px 18px;margin-bottom:16px;display:flex;gap:13px;
            align-items:center;flex-wrap:wrap;">
    <div style="font-size:26px;color:#22366F;"><i class="bi bi-eye-fill"></i></div>
    <div style="flex:1;min-width:220px;">
        <div style="font-weight:700;color:#22366F;font-size:15px;">Modo consulta</div>
        <div style="font-size:12.5px;color:#5b6478;margin-top:2px;">
            Este levantamiento lo hizo
            <strong><?= e($autor['creado_nombre'] ?? 'otra persona') ?></strong><?php
            if (!empty($autor['creado_en'])): ?>
            el <?= date('d/m/Y', strtotime($autor['creado_en'])) ?><?php endif; ?>.
            Puede verlo completo, pero solo su autor o un administrador pueden modificarlo.
        </div>
    </div>
    <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $inspeccionId ?>"
       class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-left"></i> Volver a la ficha
    </a>
</div>
<?php endif; ?>

<div class="wz-wrap">

<!-- Indicador de pasos -->
<div class="wz-steps">
    <div class="wz-step <?= $ed['completado'] ? 'hecho' : 'activo' ?>" id="tab-1" onclick="irPaso(1)">
        <span class="n">1</span> Datos del edificio
    </div>
    <div class="wz-step <?= $ed['completado'] ? 'activo' : '' ?>" id="tab-2" onclick="irPaso(2)">
        <span class="n">2</span> Recorrido piso por piso
    </div>
    <div class="wz-step" id="tab-3" onclick="irPaso(3)">
        <span class="n">3</span> Cierre (azotea y resumen)
    </div>
</div>

<!-- ============ PASO 1: DATOS DEL EDIFICIO ============ -->
<div class="wz-panel" id="paso-1">

    <?php
    // --- Encabezado con los datos de la inspección (solo lectura) ---
    $cat = catalogoDecisionFinal();
    $dec = $insp['decision_final'] ?? '';
    $colorDec = $cat[$dec]['color'] ?? '#767c94';
    $cortoDec = $cat[$dec]['corto'] ?? ($dec ?: 'Sin clasificar');
    $ubic = array_filter([
        $insp['avenida_calle'] ?? '', $insp['parroquia'] ?? '',
        $insp['municipio'] ?? '', $insp['estado'] ?? ''
    ]);
    ?>
    <div style="border:1px solid #e6e9f2;border-radius:12px;overflow:hidden;margin-bottom:22px;">
        <div style="background:<?= $colorDec ?>;color:#fff;padding:14px 18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.85;">Edificación a intervenir</div>
            <div style="font-size:19px;font-weight:700;"><?= e($insp['nombre_edificio'] ?: 'Sin nombre') ?></div>
            <div style="font-size:12px;opacity:.9;margin-top:2px;"><i class="bi bi-circle-fill" style="font-size:8px;"></i> <?= e($cortoDec) ?></div>
        </div>
        <div style="padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;">
            <?php
            $datos = [
                ['Código', $insp['codigo'] ?? '—', 'bi-upc-scan'],
                ['Uso', $insp['uso_edificacion'] ?? '—', 'bi-buildings'],
                ['Parroquia', mb_strtoupper($insp['parroquia'] ?? '—', 'UTF-8'), 'bi-geo-alt', true],
                ['Municipio', $insp['municipio'] ?? '—', 'bi-map'],
                ['Familias', $insp['familias'] ?? '—', 'bi-people'],
                ['Personas', $insp['numero_personas'] ?? '—', 'bi-person'],
                ['Pisos (inspección)', $insp['num_pisos'] ?? '—', 'bi-layers'],
                ['Ubicación', implode(', ', $ubic) ?: '—', 'bi-pin-map'],
            ];
            foreach ($datos as $fila):
                [$lbl, $val, $ico] = $fila;
                $destacar = $fila[3] ?? false;
            ?>
            <div style="font-size:13px;">
                <div style="color:#8b91a3;font-size:11px;"><i class="bi <?= $ico ?>"></i> <?= $lbl ?></div>
                <div style="color:#2a3140;font-weight:<?= $destacar ? '700' : '600' ?>;<?= $destacar ? 'font-size:16px;letter-spacing:.3px;' : '' ?>"><?= e((string)$val) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Foto de la etiqueta de la edificación -->
    <div class="bloque-tit"><i class="bi bi-tag"></i> Foto de la etiqueta</div>
    <p class="sub" style="margin-bottom:8px;">Tome la foto de la etiqueta pegada en la fachada de la edificación.</p>

    <div id="bloque-etiqueta">
        <button type="button" class="btn btn-outline" onclick="subirFotoEtiqueta(this)" style="margin-bottom:6px;">
            <i class="bi bi-camera"></i> Foto de la etiqueta
        </button>
        <div id="etiqueta-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;"></div>
    </div>

    <!-- Algunas edificaciones no tienen etiqueta: hay que poder dejarlo asentado -->
    <label class="check-row" style="display:flex;align-items:flex-start;gap:9px;
           background:#f7f9fd;border-radius:9px;padding:11px 13px;margin-bottom:8px;cursor:pointer;">
        <input type="checkbox" id="sin-etiqueta" style="margin-top:2px;"
               onchange="onSinEtiqueta(this)"
               <?= !empty($ed['sin_etiqueta']) ? 'checked' : '' ?>>
        <span>
            <span style="font-weight:600;color:#2a3140;font-size:14px;">
                Esta edificación no tiene etiqueta
            </span>
            <span style="display:block;font-size:12.5px;color:#5b6478;margin-top:2px;">
                Marque esta casilla si no encontró la etiqueta en la fachada.
                Queda registrado y puede continuar.
            </span>
        </span>
    </label>

    <div id="motivo-sin-etiqueta" style="display:<?= !empty($ed['sin_etiqueta']) ? 'block' : 'none' ?>;margin-bottom:20px;">
        <label class="text-sm" style="font-weight:600;">¿Por qué? <span class="text-muted">(opcional)</span></label>
        <select id="etiqueta-motivo" class="form-control" onchange="guardarSinEtiqueta()">
            <option value="">— Indique el motivo —</option>
            <?php
            $motivoAct = $ed['etiqueta_motivo'] ?? '';
            $motivos = [
                'No fue colocada'        => 'Nunca fue colocada',
                'Se desprendió'          => 'Se desprendió o se perdió',
                'Ilegible'               => 'Está pero ilegible o borrada',
                'Fachada inaccesible'    => 'No se pudo acceder a la fachada',
                'Edificación derrumbada' => 'La edificación está derrumbada',
                'Otro'                   => 'Otro motivo',
            ];
            foreach ($motivos as $val => $txt): ?>
            <option value="<?= e($val) ?>" <?= $motivoAct === $val ? 'selected' : '' ?>><?= e($txt) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="etiqueta-obs" class="form-control" style="margin-top:6px;"
               placeholder="Observación (opcional)" onblur="guardarSinEtiqueta()"
               value="<?= e($ed['etiqueta_obs'] ?? '') ?>">
    </div>

    <h3>Datos del edificio</h3>
    <p class="sub">Corrobore la información básica. Esto genera los pisos que va a recorrer.</p>
    <form id="form-edificio" onsubmit="return guardarEdificio(event)">
        <div class="flex gap-8" style="flex-wrap:wrap;">
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Cantidad de pisos *</label>
                <input type="number" id="num_pisos" class="form-control" min="1" max="200"
                       value="<?= $ed['num_pisos'] !== null ? (int)$ed['num_pisos'] : '' ?>" required
                       oninput="calcularTotalAptos()">
            </div>
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Apartamentos por piso</label>
                <input type="number" id="aptos_por_piso" class="form-control" min="0" max="100"
                       value="<?= $ed['aptos_por_piso'] !== null ? (int)$ed['aptos_por_piso'] : '' ?>"
                       oninput="calcularTotalAptos()">
            </div>
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Total de apartamentos</label>
                <input type="number" id="total_apartamentos" class="form-control" readonly
                       style="background:#f2f5fc;font-weight:700;color:#22366F;"
                       value="<?= (int)($ed['num_pisos'] ?? 0) * (int)($ed['aptos_por_piso'] ?? 0) ?>">
            </div>
        </div>

        <div style="margin-top:8px;">
            <label class="text-sm" style="font-weight:600;display:block;margin-bottom:8px;"><i class="bi bi-columns-gap"></i> Áreas comunes del edificio</label>
            <p class="sub" style="margin:0 0 10px;">Marque "Reparar" en las áreas que necesitan intervención. Se le pedirá una foto y qué trabajo requiere.</p>
            <?php
            $areasCat = recAreasComunesTipicas();
            $areasGuardadas = recAreasComunes((int)$ed['id']);
            $tiposTrabajo = ['mamposteria' => 'Mampostería', 'derrumbar' => 'Derrumbar / demoler', 'reconstruccion' => 'Reconstrucción'];
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                <?php foreach ($areasCat as $ak => $albl):
                    $ac = $areasGuardadas[$ak] ?? null;
                    $reparar = $ac && $ac['necesita_reparacion'];
                ?>
                <div class="area-row" data-area="<?= $ak ?>" style="border:1px solid #eef0f5;border-radius:8px;overflow:hidden;">
                    <label class="area-chk-lbl" style="display:flex;align-items:center;justify-content:space-between;gap:7px;padding:9px 11px;cursor:pointer;font-size:13px;<?= $reparar ? 'background:#fdf3e7;font-weight:600;color:#A66A00;' : '' ?>">
                        <span><?= e($albl) ?></span>
                        <span class="seg-radio" style="font-size:12px;white-space:nowrap;">
                            <input type="checkbox" class="area-reparar" <?= $reparar ? 'checked' : '' ?>> <i class="bi bi-tools"></i> Reparar
                        </span>
                    </label>
                    <!-- Detalle de reparación: foto + tipo de trabajo + m². Solo si "Reparar". -->
                    <div class="area-detalle" style="padding:10px 11px;background:#f9fafd;display:<?= $reparar ? 'block' : 'none' ?>;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="subirFotoArea('<?= $ak ?>', this)" style="margin-bottom:8px;">
                            <i class="bi bi-camera"></i> Foto del área a reparar
                        </button>
                        <div class="area-fotos" style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px;"></div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">¿Qué trabajo necesita?</label>
                        <select class="form-control area-trabajo" style="width:100%;padding:6px 8px;font-size:12.5px;margin-bottom:8px;">
                            <option value="">Seleccione…</option>
                            <?php foreach ($tiposTrabajo as $tk => $tl): ?>
                            <option value="<?= $tk ?>" <?= ($ac && ($ac['tipo_trabajo'] ?? '') === $tk) ? 'selected' : '' ?>><?= $tl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Metros cuadrados (m²)</label>
                        <input type="number" class="form-control area-m2" min="0" step="0.5" value="<?= $ac['metros_cuadrados'] ?? '' ?>"
                               placeholder="Ej: 12" style="width:100%;padding:6px 8px;font-size:12.5px;margin-bottom:8px;"
                               oninput="calcularMatArea(this)">
                        <div class="area-materiales" style="font-size:12px;color:#22366F;background:#eef2fb;border-radius:6px;padding:8px 10px;display:none;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($puedeEditar): ?>
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-right"></i> Guardar y empezar el recorrido</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ============ PASO 2: RECORRIDO PISO POR PISO ============ -->
<div class="wz-panel hidden" id="paso-2">
    <h3>Recorrido piso por piso</h3>
    <p class="sub">Seleccione un piso del listado. En cada uno registre lo común (ascensor, escaleras…) y luego entre a sus apartamentos.</p>

    <?php if (!$pisos): ?>
        <p class="text-muted">Primero complete los datos del edificio (Paso 1) para generar los pisos.</p>
    <?php else: ?>
        <!-- Selector de piso (dropdown) -->
        <div class="field" style="max-width:320px;margin-bottom:16px;">
            <label class="text-sm" style="font-weight:600;"><i class="bi bi-list-ol"></i> Seleccione el piso</label>
            <select id="selector-piso" class="form-control" onchange="mostrarPisoSeleccionado()">
                <option value="">— Elija un piso —</option>
                <?php foreach ($pisos as $piso): ?>
                <option value="<?= (int)$piso['id'] ?>">
                    <?= (int)$piso['numero_piso'] === 0 ? 'Planta Baja (PB)' : 'Piso ' . (int)$piso['numero_piso'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach ($pisos as $piso):
            $elems = recElementosPiso((int)$piso['id']);
        ?>
        <div class="piso-card piso-panel hidden" data-piso="<?= (int)$piso['id'] ?>" data-numero-piso="<?= (int)$piso['numero_piso'] ?>" id="piso-panel-<?= (int)$piso['id'] ?>">
            <div class="piso-head" style="cursor:default;">
                <span><i class="bi bi-building"></i> <?= (int)$piso['numero_piso'] === 0 ? 'Planta Baja (PB)' : 'Piso ' . (int)$piso['numero_piso'] ?></span>
            </div>
            <div class="piso-body">

                <!-- Áreas comunes del piso -->
                <div class="bloque-tit"><i class="bi bi-diagram-3"></i> Áreas comunes del piso</div>
                <label class="seg-radio"><input type="checkbox" class="piso-areas" onchange="guardarPisoReactivo(<?= (int)$piso['id'] ?>)" <?= $piso['tiene_areas_comunes'] ? 'checked' : '' ?>> Este piso tiene áreas comunes</label>
                <input type="text" class="form-control piso-areas-desc" placeholder="¿Cuáles?" value="<?= e($piso['areas_comunes_desc'] ?? '') ?>" style="margin-top:6px;" onblur="guardarPisoReactivo(<?= (int)$piso['id'] ?>)">

                <!-- Elementos comunes del piso -->
                <div class="bloque-tit" style="margin-top:16px;"><i class="bi bi-gear"></i> Elementos del piso</div>
                <?php foreach ($tiposElem as $tk => $tlbl):
                    $el = $elems[$tk] ?? null;
                    $estadoActual = $el['estado'] ?? '';
                    // El estado se considera "válido para foto" si tiene algún valor.
                    $estadoValido = $estadoActual !== '';
                ?>
                <div class="elem-row" data-tipo="<?= $tk ?>" <?= ($el && $el['id']) ? 'data-elem-id="'.(int)$el['id'].'"' : '' ?>>
                    <span class="elem-nom"><?= e($tlbl) ?></span>
                    <select class="form-control el-estado" style="width:auto;" onchange="onEstadoElemento(this, <?= (int)$piso['id'] ?>)">
                        <option value="">Estado del elemento…</option>
                        <?php foreach (['Bueno','Regular','Requiere reparación','No funciona'] as $es): ?>
                        <option value="<?= $es ?>" <?= ($el && $el['estado'] === $es) ? 'selected' : '' ?>><?= $es ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="seg-radio"><input type="checkbox" class="el-reparar" <?= ($el && $el['necesita_reparacion']) ? 'checked' : '' ?> onchange="guardarPisoReactivo(<?= (int)$piso['id'] ?>)"> Reparación</label>
                    <button type="button" class="btn btn-outline btn-sm btn-foto-elem" onclick="subirFotoElemento(this, <?= (int)$piso['id'] ?>, '<?= $tk ?>')" <?= $estadoValido ? '' : 'disabled' ?>>
                        <i class="bi bi-camera"></i> Foto
                    </button>
                    <!-- Tipo de trabajo y metros del elemento -->
                    <div class="el-trabajo-caja" style="width:100%;display:<?= ($el && $el['necesita_reparacion']) ? 'flex' : 'none' ?>;
                                gap:8px;flex-wrap:wrap;margin-top:8px;background:#fbf8ef;
                                border-radius:8px;padding:9px 11px;">
                        <select class="form-control el-trabajo" style="flex:2;min-width:170px;"
                                onchange="guardarPisoReactivo(<?= (int)$piso['id'] ?>)">
                            <option value="">— ¿Qué trabajo? —</option>
                        </select>
                        <input type="text" inputmode="decimal" class="form-control el-m2"
                               style="width:110px;" placeholder="m²"
                               value="<?= str_replace('.', ',', (string)($el['metros_cuadrados'] ?? '')) ?>"
                               oninput="normalizarDecimal(this)"
                               onchange="guardarPisoReactivo(<?= (int)$piso['id'] ?>)">
                    </div>
                    <div class="elem-fotos" style="display:flex;gap:6px;flex-wrap:wrap;width:100%;"></div>
                </div>
                <?php endforeach; ?>

                <!-- Apartamentos de este piso -->
                <div class="bloque-tit" style="margin-top:20px;"><i class="bi bi-door-open"></i> Apartamentos de este piso</div>
                <p class="sub" style="margin:0 0 10px;">Los apartamentos se generan solos con nomenclatura <b>Piso-Letra</b> (ej: <?= (int)$piso['numero_piso'] ?>-A, <?= (int)$piso['numero_piso'] ?>-B…).</p>

                <?php if ($puedeEditar): ?>
                <!-- Ajustar la cantidad: no todos los pisos tienen los mismos apartamentos -->
                <div class="apto-generador" style="margin-bottom:12px;padding:11px 13px;background:#f7f9fd;border-radius:9px;">
                    <label class="text-sm" style="display:block;margin-bottom:6px;">
                        Este piso tiene <b class="apto-actual"><?= (int)($ed['aptos_por_piso'] ?: 1) ?></b> apartamento(s).
                        Si es diferente, ajuste la cantidad y regenere:
                    </label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="number" class="form-control apto-cantidad" min="1" max="100"
                               value="<?= (int)($ed['aptos_por_piso'] ?: 1) ?>"
                               data-num="<?= (int)$piso['numero_piso'] ?>" style="width:100px;">
                        <button type="button" class="btn btn-outline btn-sm"
                                onclick="regenerarAptos(this, <?= (int)$piso['id'] ?>, <?= (int)$piso['numero_piso'] ?>)">
                            <i class="bi bi-arrow-repeat"></i> Regenerar apartamentos
                        </button>
                        <span class="apto-msg text-sm" style="margin-left:4px;"></span>
                    </div>
                    <div class="text-sm" style="color:#8a6d1a;margin-top:6px;font-size:12px;">
                        <i class="bi bi-info-circle"></i>
                        Si reduce la cantidad, se eliminan los apartamentos sobrantes con sus datos.
                    </div>
                </div>
                <?php endif; ?>

                <div class="apto-lista"></div>

            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:16px;">
        <button type="button" class="btn btn-outline" onclick="irPaso(1)"><i class="bi bi-arrow-left"></i> Datos del edificio</button>
        <button type="button" class="btn btn-primary" onclick="irPaso(3)">Ir al cierre <i class="bi bi-arrow-right"></i></button>
    </div>
</div>

<!-- ============ PASO 3: CIERRE (AZOTEA + RESUMEN) ============ -->
<div class="wz-panel hidden" id="paso-3">
    <h3>Cierre del levantamiento</h3>
    <p class="sub">Lo último del recorrido: al llegar a la azotea, registre su situación y la de los tanques de agua.</p>

    <form id="form-cierre" onsubmit="return guardarCierre(event)">
        <?php
        $globales = ['azotea' => 'Azotea', 'tanques' => 'Tanques de agua'];
        $opciones = ['Buena','Regular','Requiere reparación','No aplica'];
        foreach ($globales as $key => $lbl):
            $valActual = $ed[$key . '_estado'] ?? '';
        ?>
        <div class="campo-estado" data-cierre="<?= $key ?>">
            <label class="tit"><?= $lbl ?></label>
            <?php foreach ($opciones as $op): ?>
            <label class="seg-radio">
                <input type="radio" name="<?= $key ?>_estado" value="<?= $op ?>" <?= $valActual === $op ? 'checked' : '' ?>
                       onchange="onEstadoCierre('<?= $key ?>')">
                <?= $op ?>
            </label>
            <?php endforeach; ?>
        </div>
        <input type="text" class="form-control" name="<?= $key ?>_obs" placeholder="Observación (opcional)"
               value="<?= e($ed[$key . '_obs'] ?? '') ?>" style="margin-bottom:8px;">
        <!-- Foto condicional: solo si el estado es "Requiere reparación" -->
        <div class="cierre-foto" id="cierre-foto-<?= $key ?>" style="display:<?= $valActual === 'Requiere reparación' ? 'block' : 'none' ?>;margin-bottom:12px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="subirFotoCierre('<?= $key ?>', this)">
                <i class="bi bi-camera"></i> Foto del área que requiere reparación
            </button>
            <div class="cierre-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;"></div>
        </div>
        <?php endforeach; ?>

        <hr style="margin:18px 0;border:0;border-top:1px solid #eef0f5;">
        <div class="bloque-tit"><i class="bi bi-calendar-range"></i> Tiempo estimado de la reconstrucción</div>
        <div class="flex gap-8" style="flex-wrap:wrap;">
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Fecha de inicio estimada</label>
                <input type="date" name="fecha_inicio_estimada" class="form-control">
            </div>
            <div class="field" style="flex:1;min-width:160px;">
                <label class="text-sm">Fecha de fin estimada</label>
                <input type="date" name="fecha_fin_estimada" class="form-control">
            </div>
        </div>

        <?php if ($puedeEditar): ?>
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Guardar y ver resumen</button>
            <button type="button" class="btn btn-outline" onclick="irPaso(2)"><i class="bi bi-arrow-left"></i> Volver al recorrido</button>
        </div>
        <?php endif; ?>
    </form>

    <!-- Resumen de materiales (se llena al calcular) -->
    <div id="resumen-materiales" style="margin-top:20px;"></div>
</div>

</div><!-- /wz-wrap -->

<!-- Input de archivo oculto. Sin 'capture' para que el móvil ofrezca cámara Y galería. -->
<!-- Dos entradas: la cámara abre a tomar la foto, la galería a elegirla. -->
<input type="file" id="rec-file-camara" accept="image/*" capture="environment" style="display:none;" onchange="_onFotoElegida(this, true)">
<input type="file" id="rec-file-galeria" accept="image/*" style="display:none;" onchange="_onFotoElegida(this, false)">

<!-- Comprobante: disponible en cualquier momento, con o sin señal -->
<button type="button" onclick="comprobanteLocal()"
        style="position:fixed;left:12px;bottom:56px;z-index:1400;background:#fff;
               border:1px solid #dbe0ec;border-radius:20px;padding:7px 14px;font-size:12.5px;
               font-weight:600;color:#22366F;cursor:pointer;box-shadow:0 2px 8px rgba(20,30,60,.12);"
        title="Constancia de lo registrado hasta ahora">
    <i class="bi bi-file-earmark-text"></i> Comprobante
</button>

<!-- Acceso a las fotos guardadas en el teléfono -->
<button type="button" onclick="ObrasFotos.verGaleria()"
        style="position:fixed;left:12px;bottom:12px;z-index:1400;background:#fff;
               border:1px solid #dbe0ec;border-radius:20px;padding:7px 14px;font-size:12.5px;
               font-weight:600;color:#22366F;cursor:pointer;box-shadow:0 2px 8px rgba(20,30,60,.12);"
        title="Fotos guardadas en este teléfono">
    <i class="bi bi-images"></i> Mis fotos
</button>

<script>
const INSPECCION_ID = <?= $inspeccionId ?>;
const EDIFICIO_ID = <?= (int)$ed['id'] ?>;
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const PUEDE_EDITAR = <?= $puedeEditar ? 'true' : 'false' ?>;
const SOLO_LECTURA = <?= $soloLectura ? 'true' : 'false' ?>;

/**
 * En modo consulta se desactivan los campos y botones de edición,
 * pero las fotos siguen siendo ampliables y todo queda visible.
 */
function aplicarSoloLectura() {
    if (!SOLO_LECTURA) return;
    const cont = document.querySelector('.wz-wrap');
    if (!cont) return;

    cont.querySelectorAll('input, select, textarea').forEach(el => {
        el.disabled = true;
        el.style.background = '#f7f9fd';
        el.style.cursor = 'not-allowed';
    });

    cont.querySelectorAll('button').forEach(b => {
        const txt = (b.textContent || '').toLowerCase();
        // Los botones de navegación y de ver siguen activos.
        if (txt.includes('siguiente') || txt.includes('anterior')
            || txt.includes('volver') || txt.includes('comprobante')) return;
        b.disabled = true;
        b.style.opacity = '.45';
        b.style.cursor = 'not-allowed';
    });
}
// El script va al final: el DOM ya está listo, no hace falta esperar.
try { aplicarSoloLectura(); } catch (e) { /* seguir */ }
const RECETAS_TRABAJO = <?= json_encode(array_map(
    fn($lista) => array_map(fn($r) => [
        'material' => $r['material'], 'unidad' => $r['unidad'],
        'cantidad' => (float)$r['cantidad'],
    ], $lista),
    recRecetasTrabajo()
), JSON_UNESCAPED_UNICODE) ?>;

const TIPOS_TRABAJO = <?= json_encode(array_map(fn($t) => [
    'clave' => $t['clave'], 'nombre' => $t['nombre'],
    'descripcion' => $t['descripcion'] ?? '',
    'aplica' => $t['aplica_a'] ?? '',
], recTiposTrabajo()), JSON_UNESCAPED_UNICODE) ?>;

/**
 * Avisa qué superficies cuentan para el trabajo elegido.
 * Levantar una pared consume metros de PARED: si el técnico llena
 * también techo y piso, esos metros no entran en el cálculo.
 */
function avisoSuperficies(sel) {
    const row = sel.closest('.amb-row');
    if (!row) return;
    const t = (TIPOS_TRABAJO || []).find(x => x.clave === sel.value);
    let aviso = row.querySelector('.amb-aplica');
    if (!aviso) {
        aviso = document.createElement('div');
        aviso.className = 'amb-aplica';
        aviso.style.cssText = 'font-size:11.5px;margin:-4px 0 8px;';
        sel.parentNode.insertBefore(aviso, sel.nextSibling);
    }
    if (!t || !t.aplica) { aviso.textContent = ''; return; }
    const sups = t.aplica.split(',').map(x => x.trim()).filter(Boolean);
    aviso.style.color = '#8a6d1a';
    const nombres = { pared: 'PARED', techo: 'TECHO', piso: 'PISO' };
    const legibles = sups.map(x => nombres[x] || x.toUpperCase());
    aviso.innerHTML = '<i class="bi bi-info-circle"></i> Anote los metros en: '
        + '<strong>' + legibles.join(' o ') + '</strong>'
        + (legibles.length === 1
            ? ' — los demás campos no cuentan para este trabajo.'
            : '');
}

const APTOS_POR_PISO = <?= (int)($ed['aptos_por_piso'] ?: 1) ?>;
const EDIFICIO_NOMBRE    = <?= json_encode($insp['nombre_edificio'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
const EDIFICIO_CODIGO    = <?= json_encode($insp['codigo'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
const EDIFICIO_PARROQUIA = <?= json_encode($insp['parroquia'] ?? '', JSON_UNESCAPED_UNICODE) ?>;

function irPaso(n) {
    [1,2,3].forEach(i => {
        document.getElementById('paso-'+i).classList.toggle('hidden', i !== n);
        document.getElementById('tab-'+i).classList.toggle('activo', i === n);
    });
    if (n === 3) cargarResumen();
    window.scrollTo({top:0, behavior:'smooth'});
}

// Al marcar/desmarcar "Reparar" en un área, mostrar u ocultar su detalle.
document.querySelectorAll('.area-row .area-reparar').forEach(chk => {
    chk.addEventListener('change', function(){
        const row = this.closest('.area-row');
        const detalle = row.querySelector('.area-detalle');
        const lbl = row.querySelector('.area-chk-lbl');
        if (this.checked) {
            detalle.style.display = 'block';
            lbl.style.background = '#fdf3e7';
            lbl.style.fontWeight = '600';
            lbl.style.color = '#A66A00';
        } else {
            detalle.style.display = 'none';
            lbl.style.background = '';
            lbl.style.fontWeight = '';
            lbl.style.color = '';
        }
    });
});

// Subir foto de un área común a reparar.
let _areaFotoDestino = null;
// Destino de la próxima foto. Se declara aquí porque varias funciones
// de más arriba la usan antes de que el script llegue a su definición.
var _fotoDestino = null;

/** Foto de un área común del edificio. */
function subirFotoArea(areaKey, btn) {
    const cont = btn.closest('.area-row').querySelector('.area-fotos');
    cont.dataset.area = areaKey;
    _areaFotoDestino = cont;
    elegirOrigenFoto({
        nivel: 'edificio',
        refId: EDIFICIO_ID,
        pideParte: false,
        parteFija: areaKey,
        cont: cont,
    });
}

// Calcular materiales de un área según m² y tipo de trabajo.
async function calcularMatArea(inp) {
    const row = inp.closest('.area-row');
    const m2 = aNumero(inp.value);
    const trabajo = row.querySelector('.area-trabajo').value;
    const cont = row.querySelector('.area-materiales');
    if (m2 <= 0 || !trabajo) { cont.style.display = 'none'; return; }
    try {
        const res = await fetch(URL_BASE + 'calcular_materiales.php?tipo=' + encodeURIComponent(trabajo) + '&m2=' + m2);
        const d = await res.json();
        if (d.ok && d.materiales && d.materiales.length) {
            cont.innerHTML = '<b><i class="bi bi-box-seam"></i> Materiales estimados:</b><br>' +
                d.materiales.map(m => `• ${m.material}: <b>${m.cantidad}</b> ${m.unidad}`).join('<br>');
            cont.style.display = 'block';
        } else { cont.style.display = 'none'; }
    } catch(e) { cont.style.display = 'none'; }
}
// Recalcular también al cambiar el tipo de trabajo.
document.querySelectorAll('.area-trabajo').forEach(sel => {
    sel.addEventListener('change', function(){
        calcularMatArea(this.closest('.area-row').querySelector('.area-m2'));
    });
});

// ================= PASO 1: DATOS DEL EDIFICIO =================
// Campo calculado: total de apartamentos = pisos × apartamentos por piso.
function calcularTotalAptos() {
    const pisos = parseInt(document.getElementById('num_pisos').value) || 0;
    const app = parseInt(document.getElementById('aptos_por_piso').value) || 0;
    document.getElementById('total_apartamentos').value = pisos * app;
}

async function guardarEdificio(ev) {
    ev.preventDefault();
    if (!PUEDE_EDITAR) return false;
    const payload = {
        inspeccion_id: INSPECCION_ID,
        num_pisos: document.getElementById('num_pisos').value,
        aptos_por_piso: document.getElementById('aptos_por_piso').value,
        tiene_areas_comunes: 1,
        areas_comunes: [],
    };
    // Recolectar las áreas marcadas para reparar, con su trabajo y m².
    document.querySelectorAll('.area-row').forEach(row => {
        if (row.querySelector('.area-reparar').checked) {
            payload.areas_comunes.push({
                tipo: row.dataset.area,
                necesita_reparacion: 1,
                tipo_trabajo: row.querySelector('.area-trabajo').value,
                metros_cuadrados: aNumero(row.querySelector('.area-m2').value),
            });
        }
    });
    // Copia local siempre, antes de intentar enviar.
    guardarBorrador('paso1_' + INSPECCION_ID, payload);

    // SIN SEÑAL: se generan los pisos aquí mismo para poder seguir llenando.
    if (!navigator.onLine) {
        await continuarSinSenal(payload);
        return false;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials:'same-origin'
        });
        const texto = await res.text();
        let data;
        try { data = JSON.parse(texto); }
        catch (e) { await continuarSinSenal(payload); return false; }

        if (data.ok) {
            borrarBorrador('paso1_' + INSPECCION_ID);
            location.href = 'levantamiento.php?inspeccion=' + INSPECCION_ID + '&paso=2';
        } else {
            alert(data.mensaje || 'Error al guardar.');
        }
    } catch (e) {
        // Se cayó la señal: seguir trabajando igual.
        await continuarSinSenal(payload);
    }
    return false;
}

/**
 * Permite continuar el levantamiento sin conexión.
 * Genera los pisos y apartamentos en el navegador, con la misma
 * nomenclatura que usaría el servidor, y encola el guardado real.
 */
async function continuarSinSenal(payload) {
    if (window.ObrasOffline) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
            'Datos del edificio (paso 1)');
    }

    const nPisos = parseInt(payload.num_pisos) || 0;
    const nAptos = parseInt(payload.aptos_por_piso) || 0;
    if (nPisos < 1) { alert('Indique la cantidad de pisos.'); return; }

    // Estructura local: los ids son negativos para distinguirlos de los
    // reales del servidor. Al sincronizar se reemplazan por los definitivos.
    const estructura = { inspeccion: INSPECCION_ID, pisos: [], creada_en: new Date().toISOString() };
    for (let p = 1; p <= nPisos; p++) {
        const piso = { id: -p, numero_piso: p, apartamentos: [] };
        for (let a = 1; a <= nAptos; a++) {
            const letra = a <= 26 ? String.fromCharCode(64 + a) : String(a);
            piso.apartamentos.push({
                id: -(p * 1000 + a),
                identificador: p + '-' + letra,
                jefe_nombre: '', jefe_cedula: '', jefe_telefono: '',
                num_habitaciones: 0, num_salas: 0, num_banos: 0,
                num_cocinas: 0, num_balcones: 0,
                local: true,
            });
        }
        estructura.pisos.push(piso);
    }

    try {
        localStorage.setItem('estructura_' + INSPECCION_ID, JSON.stringify(estructura));
    } catch (e) { /* seguir igual */ }

    alert('Sin señal: puede continuar el levantamiento.\n\n'
        + 'Se prepararon ' + nPisos + ' piso(s) con ' + nAptos + ' apartamento(s) cada uno. '
        + 'Todo lo que registre se guardará en el teléfono y subirá al recuperar la conexión.');

    dibujarEstructuraLocal(estructura);
    irPaso(2);
}

/** Dibuja los pisos y apartamentos generados sin conexión. */
function dibujarEstructuraLocal(estructura) {
    const panel = document.getElementById('paso-2');
    if (!panel) return;

    // Aviso de que se está trabajando con una estructura local.
    if (!document.getElementById('aviso-local')) {
        const av = document.createElement('div');
        av.id = 'aviso-local';
        av.style.cssText = 'background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;'
            + 'padding:11px 14px;margin-bottom:14px;font-size:13px;color:#8a6d1a;';
        av.innerHTML = '<i class="bi bi-phone-fill"></i> <strong>Trabajando sin señal.</strong> '
            + 'Los pisos y apartamentos se crearon en su teléfono. '
            + 'Al recuperar la conexión se enviarán al sistema.';
        panel.insertBefore(av, panel.firstChild);
    }

    // Selector de pisos.
    let sel = document.getElementById('selector-piso');
    if (!sel) {
        const cont = document.createElement('div');
        cont.className = 'field';
        cont.style.cssText = 'max-width:320px;margin-bottom:16px;';
        cont.innerHTML = '<label class="text-sm" style="font-weight:600;">'
            + '<i class="bi bi-list-ol"></i> Seleccione el piso</label>'
            + '<select id="selector-piso" class="form-control" onchange="mostrarPisoLocal()"></select>';
        panel.appendChild(cont);
        sel = document.getElementById('selector-piso');
    }
    sel.innerHTML = '<option value="">— Elija un piso —</option>'
        + estructura.pisos.map(p => '<option value="' + p.id + '">Piso ' + p.numero_piso + '</option>').join('');

    // Contenedor de los apartamentos del piso elegido.
    if (!document.getElementById('piso-local-cont')) {
        const c = document.createElement('div');
        c.id = 'piso-local-cont';
        panel.appendChild(c);
    }
    window._estructuraLocal = estructura;
}

/** Muestra los apartamentos del piso seleccionado (modo sin señal). */
function mostrarPisoLocal() {
    const sel = document.getElementById('selector-piso');
    const cont = document.getElementById('piso-local-cont');
    if (!sel || !cont || !window._estructuraLocal) return;
    const pid = parseInt(sel.value);
    if (!pid) { cont.innerHTML = ''; return; }

    const piso = window._estructuraLocal.pisos.find(p => p.id === pid);
    if (!piso) return;

    cont.innerHTML = '<div class="bloque-tit" style="margin-top:16px;">'
        + '<i class="bi bi-door-open"></i> Apartamentos del piso ' + piso.numero_piso + '</div>'
        + '<div class="apto-lista" id="apto-lista-local"></div>';

    const lista = document.getElementById('apto-lista-local');
    piso.apartamentos.forEach(a => pintarApartamento(a, lista));
}

// ================= PASO 2: PISOS =================
// Muestra solo el piso seleccionado en el dropdown.
function mostrarPisoSeleccionado() {
    const pisoId = document.getElementById('selector-piso').value;
    document.querySelectorAll('.piso-panel').forEach(p => p.classList.add('hidden'));
    if (!pisoId) return;
    const panel = document.getElementById('piso-panel-' + pisoId);
    if (!panel) return;
    panel.classList.remove('hidden');
    // Cargar apartamentos si aún no se han cargado.
    if (!panel.dataset.cargado) {
        panel.dataset.cargado = '1';
        cargarAptosDelPiso(panel);
    }
}

async function cargarAptosDelPiso(card) {
    const pisoId = parseInt(card.dataset.piso);
    const lista = card.querySelector('.apto-lista');
    const res = await fetch(URL_BASE + 'listar_rec_aptos.php?piso_id=' + pisoId);
    const data = await res.json();
    if (data.ok && data.apartamentos && data.apartamentos.length) {
        lista.innerHTML = '';
        data.apartamentos.forEach(a => pintarApartamento(a, lista));
    } else {
        // No hay apartamentos: generarlos automáticamente según el paso 1.
        const cantidad = APTOS_POR_PISO;
        // El número de piso se obtiene del texto del panel (o del orden).
        const numeroPiso = parseInt(card.dataset.numeroPiso || '0');
        if (cantidad > 0) generarAptosAuto(card, pisoId, numeroPiso, cantidad);
    }
}

async function generarAptosAuto(card, pisoId, numeroPiso, cantidad) {
    const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'generar', piso_id: pisoId, cantidad, numero_piso: numeroPiso })
    });
    const data = await res.json();
    if (data.ok) {
        const lista = card.querySelector('.apto-lista');
        lista.innerHTML = '';
        data.apartamentos.forEach(a => pintarApartamento(a, lista));
    }
}

// Al cambiar el "Estado del elemento": habilita la foto y guarda reactivamente.
function onEstadoElemento(sel, pisoId) {
    const row = sel.closest('.elem-row');
    const btnFoto = row.querySelector('.btn-foto-elem');
    const chkRep = row.querySelector('.el-reparar');
    if (sel.value === '') {
        btnFoto.disabled = true;
    } else {
        btnFoto.disabled = false;
        // Si el estado requiere acción, marcar reparación por defecto.
        if (sel.value === 'Requiere reparación' || sel.value === 'No funciona') {
            chkRep.checked = true;
        }
    }
    mostrarCajaElemento(row);
    guardarPisoReactivo(pisoId);
}

/** Muestra u oculta la caja de trabajo según la casilla de reparación. */
function mostrarCajaElemento(row) {
    const chk = row.querySelector('.el-reparar');
    const caja = row.querySelector('.el-trabajo-caja');
    if (caja) caja.style.display = (chk && chk.checked) ? 'flex' : 'none';
}

/** Llena los selectores de trabajo de los elementos del piso. */
function llenarTrabajosElementos() {
    // Si el catálogo no cargó (falta el SQL), no hacer nada: el resto
    // de la pantalla debe seguir funcionando.
    if (typeof TIPOS_TRABAJO === 'undefined' || !Array.isArray(TIPOS_TRABAJO)) return;
    document.querySelectorAll('.el-trabajo').forEach(sel => {
        if (sel.dataset.lleno) return;
        sel.dataset.lleno = '1';
        const actual = sel.dataset.valor || '';
        sel.innerHTML = '<option value="">— ¿Qué trabajo? —</option>'
            + TIPOS_TRABAJO.map(t =>
                '<option value="' + t.clave + '"' + (t.clave === actual ? ' selected' : '') + '>'
                + t.nombre + '</option>').join('');
    });
    // Mostrar la caja en los que ya vienen marcados.
    document.querySelectorAll('.elem-row').forEach(mostrarCajaElemento);
}

// El script está al final de la página, así que el DOM ya está listo.
// Usar DOMContentLoaded aquí no dispararía nunca.
try { llenarTrabajosElementos(); } catch (e) { /* no interrumpir el resto */ }

// Si la página abre directamente en el paso 3, cargar el resumen:
// antes solo se cargaba al cambiar de paso.
try {
    const tab3 = document.getElementById('tab-3');
    if (tab3 && tab3.classList.contains('activo')) cargarResumen();
} catch (e) { /* nada */ }

// Al marcar o desmarcar reparación, mostrar u ocultar la caja.
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('el-reparar')) {
        const row = e.target.closest('.elem-row');
        if (row) mostrarCajaElemento(row);
    }
});

// Guardado reactivo del piso (reemplaza el botón "Guardar elementos del piso").
let _guardarPisoTimer = {};
function guardarPisoReactivo(pisoId) {
    // Debounce: espera 600ms sin cambios antes de guardar.
    clearTimeout(_guardarPisoTimer[pisoId]);
    _guardarPisoTimer[pisoId] = setTimeout(() => guardarPisoAhora(pisoId), 600);
}

async function guardarPisoAhora(pisoId) {
    const card = document.getElementById('piso-panel-' + pisoId);
    if (!card) return;
    const elementos = [];
    card.querySelectorAll('.elem-row').forEach(row => {
        const selT = row.querySelector('.el-trabajo');
        const inpM = row.querySelector('.el-m2');
        elementos.push({
            tipo: row.dataset.tipo,
            presente: row.querySelector('.el-estado').value !== '' ? 1 : 0,
            estado: row.querySelector('.el-estado').value,
            necesita_reparacion: row.querySelector('.el-reparar').checked ? 1 : 0,
            tipo_trabajo: selT ? selT.value : '',
            metros_cuadrados: inpM ? aNumero(inpM.value) : 0,
        });
    });
    const payload = {
        piso_id: pisoId,
        tiene_areas_comunes: card.querySelector('.piso-areas').checked ? 1 : 0,
        areas_comunes_desc: card.querySelector('.piso-areas-desc').value,
        elementos,
    };
    const res = await fetch(URL_BASE + 'guardar_rec_piso.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok && data.elementos) {
        card.querySelectorAll('.elem-row').forEach(row => {
            const id = data.elementos[row.dataset.tipo];
            if (id) row.dataset.elemId = id;
        });
    }
}

// ================= APARTAMENTOS (dentro de cada piso) =================
// Regenera los apartamentos de un piso con la cantidad indicada.
// Si se reduce, el servidor elimina los sobrantes con sus datos.
async function regenerarAptos(btn, pisoId, numeroPiso) {
    const card = document.getElementById('piso-panel-' + pisoId);
    if (!card) return;
    const input = card.querySelector('.apto-cantidad');
    const msg = card.querySelector('.apto-msg');
    const cantidad = parseInt(input.value) || 0;

    if (cantidad < 1 || cantidad > 100) {
        msg.textContent = 'Indique entre 1 y 100.';
        msg.style.color = '#A61C1C';
        return;
    }

    // Si va a eliminar apartamentos, pedir confirmación explícita.
    const actuales = card.querySelectorAll('.apto-lista .apto-card').length;
    if (actuales > 0 && cantidad < actuales) {
        const van = actuales - cantidad;
        const ok = confirm('Se eliminarán ' + van + ' apartamento(s) con sus ambientes, '
            + 'avances y fotos.\n\nEsta acción no se puede deshacer. ¿Continuar?');
        if (!ok) { input.value = actuales; return; }
    }

    btn.disabled = true;
    msg.textContent = 'Regenerando…';
    msg.style.color = '#5b6478';

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ accion:'generar', piso_id: pisoId,
                                   cantidad: cantidad, numero_piso: numeroPiso })
        });
        const data = await res.json();
        if (data.sesion_expirada) { alert(data.mensaje); return; }
        if (!data.ok) {
            msg.textContent = data.mensaje || 'No se pudo regenerar.';
            msg.style.color = '#A61C1C';
            return;
        }
        const lista = card.querySelector('.apto-lista');
        lista.innerHTML = '';
        (data.apartamentos || []).forEach(a => pintarApartamento(a, lista));
        const actual = card.querySelector('.apto-actual');
        if (actual) actual.textContent = (data.apartamentos || []).length;
        msg.textContent = '✓ ' + (data.apartamentos || []).length + ' apartamento(s)';
        msg.style.color = '#2E7D32';
        setTimeout(() => { msg.textContent = ''; }, 3000);
    } catch (e) {
        msg.textContent = 'Sin conexión. Intente de nuevo.';
        msg.style.color = '#A61C1C';
    } finally {
        btn.disabled = false;
    }
}

function pintarApartamento(a, lista) {
    // Al final de esta función se reaplica el modo consulta, porque los
    // campos nuevos nacen habilitados.
    const card = document.createElement('div');
    card.className = 'apto-card';
    card.innerHTML = `
        <div class="apto-head" onclick="this.nextElementSibling.classList.toggle('hidden')">
            <i class="bi bi-door-open"></i> Apartamento ${a.identificador}
        </div>
        <div class="apto-body hidden">
            <!-- Datos del jefe de familia (obligatorios) -->
            <div style="background:#f7f9fd;border-radius:9px;padding:12px 14px;margin-bottom:14px;">
                <div class="bloque-tit" style="margin:0 0 10px;"><i class="bi bi-person-vcard"></i> Jefe de familia</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="field jefe-field" style="flex:2;min-width:180px;">
                        <label class="text-sm">Nombre completo *</label>
                        <input type="text" class="form-control jefe-nombre" value="${a.jefe_nombre||''}" placeholder="Nombre y apellido">
                    </div>
                    <div class="field jefe-field" style="flex:1;min-width:120px;">
                        <label class="text-sm">Cédula *</label>
                        <input type="text" class="form-control jefe-cedula" value="${a.jefe_cedula||''}" placeholder="V-12345678" inputmode="numeric">
                    </div>
                    <div class="field jefe-field" style="flex:1;min-width:120px;">
                        <label class="text-sm">Teléfono *</label>
                        <input type="tel" class="form-control jefe-telefono" value="${a.jefe_telefono||''}" placeholder="0412-1234567" inputmode="tel">
                    </div>
                </div>
            </div>
            <!-- Cantidad de ambientes -->
            <div class="bloque-tit" style="margin:0 0 8px;"><i class="bi bi-grid-3x3-gap"></i> Ambientes del apartamento</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                ${['habitaciones','salas','banos','cocinas','balcones'].map(t => `
                    <div class="field" style="width:95px;">
                        <label class="text-sm" style="text-transform:capitalize;">${t==='banos'?'Baños':t}</label>
                        <input type="number" min="0" max="30" class="form-control amb-${t}" value="${a['num_'+t]||0}">
                    </div>`).join('')}
                <button type="button" class="btn btn-primary btn-sm" onclick="guardarApto(this, ${a.id})">
                    <i class="bi bi-check-lg"></i> Generar ambientes
                </button>
            </div>

            <!-- Casos en que no se puede levantar el apartamento -->
            <div style="margin-top:12px;padding-top:11px;border-top:1px solid #f0f2f7;">
                <div class="text-sm" style="color:#5b6478;margin-bottom:7px;">
                    Si no se puede hacer el levantamiento:
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline btn-sm"
                            style="border-color:#2E7D3255;color:#2E7D32;"
                            onclick="marcarVisita(${a.id}, 'no_requiere', this)">
                        <i class="bi bi-hand-thumbs-up"></i> No requiere ayuda
                    </button>
                    <button type="button" class="btn btn-outline btn-sm"
                            style="border-color:#97a0b855;color:#5b6478;"
                            onclick="marcarVisita(${a.id}, 'no_esta', this)">
                        <i class="bi bi-door-closed"></i> Ocupante no se encuentra
                    </button>
                    <button type="button" class="btn btn-outline btn-sm"
                            style="border-color:#A61C1C55;color:#A61C1C;"
                            onclick="marcarVisita(${a.id}, 'permiso_denegado', this)">
                        <i class="bi bi-x-octagon"></i> Permiso denegado
                    </button>
                </div>
            </div>

            <div class="amb-lista" style="margin-top:14px;"></div>
        </div>`;
    lista.appendChild(card);

    // Autoguardado local mientras escribe + recuperar lo pendiente.
    const cuerpo = card.querySelector('.apto-body');
    if (cuerpo) {
        cuerpo.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', () => autoguardarApto(a.id, cuerpo));
        });
        // Si quedó algo escrito sin guardar, se recupera.
        const b = leerBorrador(a.id);
        if (b && b.datos && !a.jefe_nombre) restaurarBorrador(a.id, cuerpo);
    }
    // Si hay ambientes guardados en el teléfono (aún sin subir), se muestran
    // esos: son los que el técnico ve y puede fotografiar.
    const locales = leerAmbientesLocales(a.id);
    if (locales && locales.ambientes && locales.ambientes.length) {
        const cuerpoAp = card.querySelector('.apto-body');
        pintarAmbientesLocales(cuerpoAp, {
            num_habitaciones: a.num_habitaciones, num_salas: a.num_salas,
            num_banos: a.num_banos, num_cocinas: a.num_cocinas,
            num_balcones: a.num_balcones,
        }, a.id);
        marcarAptoPendiente(cuerpoAp, 'Guardado en el teléfono');
    } else {
        // Siempre se intenta cargar lo ya guardado: puede haber ambientes
        // con fotos aunque las cantidades del formulario estén en cero.
        // Sin esto, quien vuelve a cargar fotos tenía que crear todo de nuevo.
        cargarAmbientes(a.id, card.querySelector('.amb-lista'));
    }

    try { aplicarSoloLectura(); } catch (e) { /* seguir */ }
}

/**
 * Marca un apartamento que no se pudo levantar y pasa al siguiente.
 *   'no_requiere' → la familia dice que no necesita reparación
 *   'no_esta'     → no había nadie
 * No pide datos del jefe de familia: no tendría sentido.
 */
async function marcarVisita(aptoId, estado, btn) {
    const textos = {
        no_requiere:       '¿Confirma que este apartamento NO REQUIERE ayuda?',
        no_esta:           '¿Confirma que EL OCUPANTE NO SE ENCUENTRA?',
        permiso_denegado:  '¿Confirma que NO DIERON PERMISO para entrar?',
    };
    if (!confirm(textos[estado] || '¿Confirma?')) return;

    const ejemplos = {
        no_esta:          'Ej: se visitó dos veces, sin respuesta',
        permiso_denegado: 'Ej: el ocupante no permitió el ingreso',
        no_requiere:      'Ej: la familia indica que no tiene daños',
    };
    const obs = prompt('Nota (opcional):\n\n' + (ejemplos[estado] || ''), '');
    if (obs === null) return;   // canceló

    const card = btn.closest('.apto-card');
    const cuerpo = btn.closest('.apto-body');
    btn.disabled = true;

    const payload = { accion:'marcar_visita', apartamento_id: aptoId,
                      estado: estado, observacion: obs };

    // Sin señal: queda en cola.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
            'Apartamento ' + aptoId + ' · ' + estado);
        pintarAptoMarcado(card, cuerpo, estado, obs);
        saltarAlSiguiente(card);
        return;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials:'same-origin'
        });
        const d = await res.json();
        if (!d.ok) { alert(d.mensaje || 'No se pudo guardar.'); btn.disabled = false; return; }
        pintarAptoMarcado(card, cuerpo, estado, obs);
        saltarAlSiguiente(card);
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
                'Apartamento ' + aptoId + ' · ' + estado);
            pintarAptoMarcado(card, cuerpo, estado, obs);
            saltarAlSiguiente(card);
        } else {
            alert('Sin conexión. Intente de nuevo.');
            btn.disabled = false;
        }
    }
}

/** Deja el apartamento marcado visualmente y cierra su detalle. */
function pintarAptoMarcado(card, cuerpo, estado, obs) {
    const estilos = {
        no_requiere:      { color: '#2E7D32', icono: 'bi-hand-thumbs-up-fill',
                            texto: 'No requiere ayuda' },
        no_esta:          { color: '#5b6478', icono: 'bi-door-closed-fill',
                            texto: 'Ocupante no se encuentra' },
        permiso_denegado: { color: '#A61C1C', icono: 'bi-x-octagon-fill',
                            texto: 'Permiso denegado' },
    };
    const e = estilos[estado] || estilos.no_esta;
    const color = e.color, icono = e.icono, texto = e.texto;

    const cab = card.querySelector('.apto-head');
    if (cab && !cab.querySelector('.apto-marca')) {
        cab.insertAdjacentHTML('beforeend',
            '<span class="apto-marca" style="float:right;font-size:11.5px;font-weight:600;'
            + 'color:' + color + ';background:' + color + '18;border-radius:20px;'
            + 'padding:2px 10px;"><i class="bi ' + icono + '"></i> ' + texto + '</span>');
    }

    if (cuerpo) {
        cuerpo.innerHTML = '<div style="background:' + color + '10;border:1px solid '
            + color + '44;border-radius:9px;padding:13px 15px;">'
            + '<div style="font-weight:700;color:' + color + ';font-size:14px;">'
            + '<i class="bi ' + icono + '"></i> ' + texto + '</div>'
            + (obs ? '<div style="font-size:12.5px;color:#5b6478;margin-top:5px;">' + obs + '</div>' : '')
            + '<button type="button" class="btn btn-outline btn-sm" style="margin-top:9px;"'
            + ' onclick="location.reload()">'
            + '<i class="bi bi-arrow-counterclockwise"></i> Corregir</button></div>';
        cuerpo.classList.add('hidden');
    }
}

/** Abre el siguiente apartamento del piso, para no perder el hilo. */
function saltarAlSiguiente(card) {
    const siguiente = card.nextElementSibling;
    if (!siguiente || !siguiente.classList.contains('apto-card')) {
        // Era el último: avisar y quedarse donde está.
        const msg = document.createElement('div');
        msg.style.cssText = 'background:#eef7f0;border-radius:8px;padding:10px 13px;'
            + 'margin-top:9px;font-size:13px;color:#2E7D32;font-weight:600;';
        msg.innerHTML = '<i class="bi bi-check-circle-fill"></i> '
            + 'Terminó los apartamentos de este piso.';
        card.parentNode.appendChild(msg);
        setTimeout(() => msg.remove(), 5000);
        return;
    }

    const cuerpo = siguiente.querySelector('.apto-body');
    if (cuerpo) cuerpo.classList.remove('hidden');
    siguiente.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Poner el cursor en el primer campo, listo para escribir.
    setTimeout(() => {
        const primero = siguiente.querySelector('.jefe-nombre');
        if (primero) primero.focus();
    }, 400);
}

async function guardarApto(btn, aptoId) {
    const cont = btn.closest('.apto-body');
    // Validar que los datos del jefe de familia estén completos (obligatorios).
    const nombre = cont.querySelector('.jefe-nombre').value.trim();
    const cedula = cont.querySelector('.jefe-cedula').value.trim();
    const telefono = cont.querySelector('.jefe-telefono').value.trim();
    if (!nombre || !cedula || !telefono) {
        alert('Complete los datos del jefe de familia (nombre, cédula y teléfono) antes de continuar.');
        return;
    }

    // Los ambientes marcados como "necesita reparación" deben tener metros
    // cuadrados: sin ese dato no se pueden calcular los materiales.
    const sinMetros = [];
    const sinTrabajo = [];
    const sinFoto = [];
    cont.querySelectorAll('.amb-row').forEach(row => {
        const chk = row.querySelector('.amb-reparar');
        if (!chk || !chk.checked) {
            marcarAmbienteSinMetros(row, false);
            marcarAmbienteSinFoto(row, false);
            return;
        }

        const nom = row.querySelector('.amb-nom');
        const etiqueta = nom ? nom.textContent.trim() : 'un ambiente';

        // El tipo de trabajo define qué materiales se piden: es obligatorio.
        const selT = row.querySelector('.amb-trabajo');
        if (selT && !selT.value) {
            sinTrabajo.push(etiqueta);
            selT.style.border = '2px solid #A61C1C';
        } else if (selT) {
            selT.style.border = '';
        }

        const falta = !ambienteTieneMetros(row);
        marcarAmbienteSinMetros(row, falta);
        if (falta) sinMetros.push(etiqueta);

        // Un ambiente a reparar necesita foto: es la evidencia del daño.
        const nFotos = row.querySelectorAll('.amb-fotos img').length;
        const faltaFoto = nFotos === 0;
        marcarAmbienteSinFoto(row, faltaFoto);
        if (faltaFoto) sinFoto.push(etiqueta);
    });

    // Un solo aviso con todo lo que falta: tres alertas seguidas
    // interrumpen demasiado el trabajo en campo.
    if (sinTrabajo.length || sinMetros.length || sinFoto.length) {
        let msg = 'Falta completar estos ambientes:\n';
        if (sinTrabajo.length) msg += '\nQué trabajo hacer:\n· ' + sinTrabajo.join('\n· ');
        if (sinMetros.length)  msg += '\nMetros cuadrados:\n· ' + sinMetros.join('\n· ');
        if (sinFoto.length)    msg += '\nFoto del daño:\n· ' + sinFoto.join('\n· ');
        alert(msg);

        // Llevar al primer campo que falta.
        const primero = cont.querySelector('.amb-trabajo[style*="2px solid"]')
                     || cont.querySelector('.amb-reparacion[style*="2px solid"]')
                     || cont.querySelector('.amb-fotos[style*="2px dashed"]');
        if (primero) primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const payload = {
        accion:'guardar_apto', apartamento_id: aptoId,
        jefe_nombre: nombre, jefe_cedula: cedula, jefe_telefono: telefono,
        num_habitaciones: cont.querySelector('.amb-habitaciones').value,
        num_salas:        cont.querySelector('.amb-salas').value,
        num_banos:        cont.querySelector('.amb-banos').value,
        num_cocinas:      cont.querySelector('.amb-cocinas').value,
        num_balcones:     cont.querySelector('.amb-balcones').value,
    };
    // Guardar copia local ANTES de enviar: si se cae la señal, no se pierde.
    guardarBorrador(aptoId, payload);

    // Apartamento creado sin conexión (id negativo): todavía no existe en el
    // servidor. Se guarda en la estructura local y se enviará al sincronizar.
    if (aptoId < 0) {
        guardarAptoLocal(aptoId, payload);
        pintarAmbientesLocales(cont, payload);
        marcarAptoPendiente(cont, 'Guardado en el teléfono');
        return;
    }

    // Sin señal: queda en cola y se sube después.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
            'Apartamento ' + aptoId + ' · ' + nombre);
        // Guardar también la estructura del apartamento, para poder seguir
        // trabajando en él (ver los ambientes, tomar fotos) sin señal.
        guardarAmbientesLocales(aptoId, payload);
        pintarAmbientesLocales(cont, payload, aptoId);
        marcarAptoPendiente(cont, 'Guardado en el teléfono');
        return;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_apto.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials: 'same-origin'
        });
        const texto = await res.text();
        let data;
        try { data = JSON.parse(texto); }
        catch (e) {
            // El servidor respondió HTML (sesión caída o error): no perder nada.
            if (window.ObrasOffline) {
                await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
                    'Apartamento ' + aptoId + ' · ' + nombre);
                guardarAmbientesLocales(aptoId, payload);
                pintarAmbientesLocales(cont, payload, aptoId);
                marcarAptoPendiente(cont, 'Guardado en el teléfono');
            } else {
                alert('El servidor respondió algo inesperado. Sus datos siguen en pantalla, intente de nuevo.');
            }
            return;
        }
        if (data.sesion_expirada) { alert(data.mensaje); return; }
        if (data.ok) {
            borrarBorrador(aptoId);
            // Ya están en el servidor: se limpia la copia local.
            try { localStorage.removeItem('ambientes_' + aptoId); } catch (e) {}
            pintarAmbientes(data.ambientes, cont.querySelector('.amb-lista'));
            marcarAptoPendiente(cont, '');
        } else {
            alert(data.mensaje || 'Error al guardar.');
        }
    } catch (e) {
        // Se cayó la señal a mitad: encolar en vez de perder el trabajo.
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
                'Apartamento ' + aptoId + ' · ' + nombre);
            guardarAmbientesLocales(aptoId, payload);
            pintarAmbientesLocales(cont, payload, aptoId);
            marcarAptoPendiente(cont, 'Guardado en el teléfono');
        } else {
            alert('Se perdió la conexión.\n\nSus datos siguen en pantalla. Intente guardar de nuevo cuando tenga señal.');
        }
    }
}

// Guarda lo escrito mientras el usuario llena, sin esperar a que envíe.
function autoguardarApto(aptoId, cont) {
    const v = sel => { const el = cont.querySelector(sel); return el ? el.value : ''; };
    guardarBorrador(aptoId, {
        accion:'guardar_apto', apartamento_id: aptoId,
        jefe_nombre: v('.jefe-nombre'), jefe_cedula: v('.jefe-cedula'),
        jefe_telefono: v('.jefe-telefono'),
        num_habitaciones: v('.amb-habitaciones'), num_salas: v('.amb-salas'),
        num_banos: v('.amb-banos'), num_cocinas: v('.amb-cocinas'),
        num_balcones: v('.amb-balcones'),
    });
}

// ---- Autoguardado periódico: respaldo cada 30 segundos ----
// Aunque el usuario no toque nada, lo escrito se conserva. Si se va la
// señal o se cierra el navegador, al volver está todo.
let _ultimoRespaldo = '';

function respaldarTodo() {
    try {
        const estado = { inspeccion: INSPECCION_ID, fecha: new Date().toISOString(), aptos: {} };

        // Datos de cada apartamento que esté abierto en pantalla.
        document.querySelectorAll('.apto-card').forEach(card => {
            const cuerpo = card.querySelector('.apto-body');
            if (!cuerpo) return;
            const btn = cuerpo.querySelector('button[onclick^="guardarApto"]');
            if (!btn) return;
            const m = btn.getAttribute('onclick').match(/guardarApto\(this,\s*(\d+)\)/);
            if (!m) return;
            const id = m[1];
            const v = sel => { const el = cuerpo.querySelector(sel); return el ? el.value : ''; };
            const datos = {
                jefe_nombre: v('.jefe-nombre'), jefe_cedula: v('.jefe-cedula'),
                jefe_telefono: v('.jefe-telefono'),
                num_habitaciones: v('.amb-habitaciones'), num_salas: v('.amb-salas'),
                num_banos: v('.amb-banos'), num_cocinas: v('.amb-cocinas'),
                num_balcones: v('.amb-balcones'),
            };
            // Solo respaldar si tiene algo escrito.
            if (Object.values(datos).some(x => x && x !== '0')) estado.aptos[id] = datos;
        });

        // Cierre, si está llenándolo.
        const form = document.getElementById('form-cierre');
        if (form) {
            const c = {};
            ['azotea','tanques'].forEach(k => {
                const sel = form.querySelector('input[name="'+k+'_estado"]:checked');
                if (sel) c[k+'_estado'] = sel.value;
                const obs = form.querySelector('input[name="'+k+'_obs"]');
                if (obs && obs.value) c[k+'_obs'] = obs.value;
            });
            if (Object.keys(c).length) estado.cierre = c;
        }

        const serial = JSON.stringify(estado);
        if (serial === _ultimoRespaldo) return;   // sin cambios, no reescribir
        _ultimoRespaldo = serial;
        localStorage.setItem('respaldo_lev_' + INSPECCION_ID, serial);
        mostrarSelloRespaldo();
    } catch (e) { /* si no hay espacio, seguir trabajando igual */ }
}

// Sello discreto que confirma que hay respaldo local.
function mostrarSelloRespaldo() {
    let sello = document.getElementById('sello-respaldo');
    if (!sello) {
        sello = document.createElement('div');
        sello.id = 'sello-respaldo';
        sello.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:1400;'
            + 'background:#eef7f0;border:1px solid #2E7D3244;color:#2E7D32;'
            + 'border-radius:20px;padding:6px 13px;font-size:12px;font-weight:600;'
            + 'box-shadow:0 2px 8px rgba(20,30,60,.12);';
        document.body.appendChild(sello);
    }
    const h = new Date().toLocaleTimeString('es-VE', {hour:'2-digit', minute:'2-digit'});
    sello.innerHTML = '<i class="bi bi-shield-check"></i> Respaldado ' + h;
    sello.style.opacity = '1';
    clearTimeout(sello._t);
    sello._t = setTimeout(() => { sello.style.opacity = '.45'; }, 4000);
}

// Recupera el respaldo si el navegador se cerró sin guardar.
function revisarRespaldoPrevio() {
    try {
        const raw = localStorage.getItem('respaldo_lev_' + INSPECCION_ID);
        if (!raw) return;
        const est = JSON.parse(raw);
        const n = Object.keys(est.aptos || {}).length;
        if (!n) return;
        const cuando = est.fecha ? new Date(est.fecha).toLocaleString('es-VE') : '';
        const aviso = document.createElement('div');
        aviso.style.cssText = 'background:#fffbf0;border:1px solid #C9A22755;border-radius:9px;'
            + 'padding:12px 14px;margin-bottom:14px;font-size:13px;color:#8a6d1a;';
        aviso.innerHTML = '<i class="bi bi-clock-history"></i> <strong>Hay un respaldo sin guardar.</strong> '
            + 'Se encontraron datos de ' + n + ' apartamento(s) escritos el ' + cuando + '. '
            + 'Al abrir cada apartamento aparecerán para que los revise y guarde.';
        const panel = document.getElementById('paso-2');
        if (panel) panel.insertBefore(aviso, panel.firstChild);
    } catch (e) { /* nada */ }
}

// Arranque del respaldo automático.
document.addEventListener('DOMContentLoaded', function () {
    revisarRespaldoPrevio();
    setInterval(respaldarTodo, 30000);              // cada 30 segundos
    window.addEventListener('beforeunload', respaldarTodo);  // al cerrar
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) respaldarTodo();       // al cambiar de app
    });
});

/** Guarda los datos de un apartamento creado sin conexión. */
function guardarAptoLocal(aptoId, datos) {
    try {
        const est = window._estructuraLocal
            || JSON.parse(localStorage.getItem('estructura_' + INSPECCION_ID) || 'null');
        if (!est) return;
        est.pisos.forEach(p => p.apartamentos.forEach(a => {
            if (a.id === aptoId) Object.assign(a, datos, { completado: true });
        }));
        localStorage.setItem('estructura_' + INSPECCION_ID, JSON.stringify(est));
        window._estructuraLocal = est;
    } catch (e) { /* seguir */ }
}

/**
 * Guarda los ambientes de un apartamento en el teléfono.
 * Así se pueden ver y fotografiar aunque no haya señal, y al sincronizar
 * las fotos se asocian al ambiente correcto.
 */
function guardarAmbientesLocales(aptoId, datos) {
    try {
        const tipos = [
            ['Habitación', parseInt(datos.num_habitaciones) || 0],
            ['Sala',       parseInt(datos.num_salas) || 0],
            ['Baño',       parseInt(datos.num_banos) || 0],
            ['Cocina',     parseInt(datos.num_cocinas) || 0],
            ['Balcón',     parseInt(datos.num_balcones) || 0],
        ];
        const ambientes = [];
        let n = 1;
        tipos.forEach(([tipo, cant]) => {
            for (let i = 1; i <= cant; i++) {
                ambientes.push({
                    // Id temporal: negativo y único dentro del apartamento.
                    id: -(Math.abs(aptoId) * 100 + n),
                    tipo: tipo, numero: i,
                    etiqueta: tipo + ' ' + i,
                    apartamento_id: aptoId,
                    local: true,
                });
                n++;
            }
        });
        localStorage.setItem('ambientes_' + aptoId, JSON.stringify({
            apartamento_id: aptoId,
            jefe_nombre: datos.jefe_nombre || '',
            ambientes: ambientes,
            fecha: new Date().toISOString(),
        }));
    } catch (e) { /* si no hay espacio, se sigue trabajando */ }
}

/** Recupera los ambientes guardados de un apartamento. */
function leerAmbientesLocales(aptoId) {
    try {
        const raw = localStorage.getItem('ambientes_' + aptoId);
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}

/**
 * Dibuja los ambientes sin consultar al servidor.
 * Cada uno permite tomar fotos: quedan en cola con su ambiente temporal
 * y se reasignan al ambiente real cuando el apartamento se sincroniza.
 */
function pintarAmbientesLocales(cont, datos, aptoId) {
    const lista = cont.querySelector('.amb-lista');
    if (!lista) return;

    const guardado = leerAmbientesLocales(aptoId);
    const ambientes = guardado ? guardado.ambientes : [];

    if (!ambientes.length) {
        lista.innerHTML = '<div style="font-size:12px;color:#767c94;padding:6px 0;">'
            + 'Indique cuántos ambientes tiene el apartamento.</div>';
        return;
    }

    let html = '<div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:8px;'
        + 'padding:9px 12px;margin-bottom:10px;font-size:12.5px;color:#8a6d1a;">'
        + '<i class="bi bi-phone-fill"></i> Estos ambientes están guardados en su teléfono. '
        + 'Puede tomarles fotos: todo subirá junto al recuperar la señal.</div>';

    ambientes.forEach(am => {
        html += '<div class="amb-row" data-amb-local="' + am.id + '" '
            + 'style="display:flex;align-items:center;gap:10px;padding:9px 4px;'
            + 'border-bottom:1px solid #f0f2f7;flex-wrap:wrap;">'
            + '<i class="bi bi-door-closed" style="color:#2d4488;"></i>'
            + '<span style="flex:1;min-width:120px;font-size:13.5px;font-weight:600;color:#2a3140;">'
            + am.etiqueta + '</span>'
            + '<button type="button" class="btn btn-outline btn-sm" '
            + 'onclick="fotoAmbienteLocal(' + am.id + ', \'' + am.etiqueta + '\', ' + aptoId + ')">'
            + '<i class="bi bi-camera"></i> Foto</button>'
            + '<span class="amb-fotos-local" id="amb-fotos-' + am.id + '" '
            + 'style="display:flex;gap:5px;flex-wrap:wrap;"></span>'
            + '</div>';
    });

    lista.innerHTML = html;
}

/**
 * Toma una foto de un ambiente que todavía no existe en el servidor.
 * Se encola indicando el apartamento y la posición del ambiente, para
 * que al sincronizar se asocie al ambiente correcto.
 */
function fotoAmbienteLocal(ambLocalId, etiqueta, aptoId) {
    elegirOrigenFoto({
        nivel: 'ambiente_local',
        refId: ambLocalId,
        aptoId: aptoId,
        etiqueta: etiqueta,
        cont: document.getElementById('amb-fotos-' + ambLocalId),
        pideParte: false,
        parteFija: 'antes',
    });
}

// ---- Borradores locales: lo escrito no se pierde aunque se corte todo ----
function guardarBorrador(aptoId, datos) {
    try {
        localStorage.setItem('borrador_apto_' + aptoId, JSON.stringify({
            datos: datos, fecha: new Date().toISOString()
        }));
    } catch (e) { /* si no hay espacio, seguir igual */ }
}

function borrarBorrador(aptoId) {
    try { localStorage.removeItem('borrador_apto_' + aptoId); } catch (e) {}
}

function leerBorrador(aptoId) {
    try {
        const raw = localStorage.getItem('borrador_apto_' + aptoId);
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}

// Restaura lo que el usuario había escrito y no llegó a guardarse.
function restaurarBorrador(aptoId, cont) {
    const b = leerBorrador(aptoId);
    if (!b || !b.datos) return false;
    const d = b.datos;
    const set = (sel, val) => { const el = cont.querySelector(sel); if (el && val != null) el.value = val; };
    set('.jefe-nombre', d.jefe_nombre);
    set('.jefe-cedula', d.jefe_cedula);
    set('.jefe-telefono', d.jefe_telefono);
    set('.amb-habitaciones', d.num_habitaciones);
    set('.amb-salas', d.num_salas);
    set('.amb-banos', d.num_banos);
    set('.amb-cocinas', d.num_cocinas);
    set('.amb-balcones', d.num_balcones);
    marcarAptoPendiente(cont, 'Se recuperó lo que había escrito');
    return true;
}

function marcarAptoPendiente(cont, texto) {
    let el = cont.querySelector('.apto-estado');
    if (!el) {
        el = document.createElement('div');
        el.className = 'apto-estado';
        el.style.cssText = 'font-size:12.5px;font-weight:600;margin-top:8px;';
        cont.appendChild(el);
    }
    if (!texto) { el.textContent = ''; return; }
    el.style.color = '#8a6d1a';
    el.innerHTML = '<i class="bi bi-phone-fill"></i> ' + texto;
}

async function cargarAmbientes(aptoId, contenedor) {
    const res = await fetch(URL_BASE + 'listar_rec_aptos.php?ambientes_de=' + aptoId);
    const data = await res.json();
    if (data.ok) pintarAmbientes(data.ambientes, contenedor);
}

function pintarAmbientes(ambientes, contenedor) {
    if (!ambientes || !ambientes.length) { contenedor.innerHTML = ''; return; }
    const iconos = {'Habitación':'bi-door-closed','Sala':'bi-tv','Balcón':'bi-flower1','Cocina':'bi-fire'};
    let html = '';
    ambientes.forEach(am => {
        const rep = am.necesita_reparacion == 1;
        html += `
        <div class="amb-row" data-amb="${am.id}" style="border:1px solid #e8ebf3;border-radius:9px;padding:10px 12px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span class="amb-nom" style="font-weight:600;color:#2a3140;"><i class="bi ${iconos[am.tipo]||'bi-square'}"></i> ${am.tipo} ${am.numero}</span>
                <label class="seg-radio"><input type="checkbox" class="amb-reparar" ${rep?'checked':''} onchange="toggleReparar(this, ${am.id})"> Necesita reparación</label>
                <button type="button" class="btn btn-outline btn-sm" onclick="fotoAmbiente(this, ${am.id})"><i class="bi bi-camera"></i> Foto${rep?'s':''}</button>
                <span class="amb-hint text-sm" style="color:#767c94;">${rep?'Suba fotos indicando la parte':'Suba 1 foto del estado'}</span>
            </div>
            <div class="amb-reparacion" style="${rep?'':'display:none;'}margin-top:10px;padding:10px;background:#fbf8ef;border-radius:8px;">
                <div style="background:#fff;border:1px solid #C9A22755;border-radius:7px;
                            padding:8px 11px;margin-bottom:10px;font-size:12px;color:#8a6d1a;">
                    <strong><i class="bi bi-exclamation-circle-fill"></i> Este ambiente necesita
                    tres datos obligatorios:</strong><br>
                    1. Qué trabajo hacer &nbsp;·&nbsp; 2. Cuántos metros &nbsp;·&nbsp; 3. Foto del daño
                </div>
                <div class="text-sm" style="font-weight:600;color:#8a6d1a;margin-bottom:6px;">
                    <i class="bi bi-tools"></i> ¿Qué trabajo hay que hacer?
                    <span style="color:#A61C1C;">*</span>
                </div>
                <select class="form-control amb-trabajo" style="margin-bottom:10px;"
                        onchange="guardarTrabajo(${am.id}, this); avisoSuperficies(this); recalcularMateriales(this.closest('.amb-row'));">
                    <option value="">— Seleccione el trabajo —</option>
                    ${(Array.isArray(TIPOS_TRABAJO) ? TIPOS_TRABAJO : []).map(t =>
                        `<option value="${t.clave}" ${am.tipo_trabajo === t.clave ? 'selected' : ''}
                                 title="${t.descripcion || ''}">${t.nombre}</option>`).join('')}
                </select>
                <div class="text-sm" style="font-weight:600;color:#8a6d1a;margin-bottom:6px;">
                    <i class="bi bi-rulers"></i> Metros cuadrados a reparar
                    <span style="color:#A61C1C;">*</span>
                    <span style="font-weight:400;font-size:11.5px;color:#8a6d1a;">
                        — indique al menos uno
                    </span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    ${['pared','techo','piso','closet'].map(sup => `
                        <div style="width:110px;">
                            <label class="text-sm" style="text-transform:capitalize;">${sup} (m²)</label>
                            <input type="text" inputmode="decimal" class="form-control sup-${sup}"
                                   data-sup="${sup}" value="0"
                                   oninput="normalizarDecimal(this)"
                                   onchange="guardarReparacion(${am.id}, this)">
                        </div>`).join('')}
                </div>
                <div class="amb-materiales text-sm" style="margin-top:8px;color:#55617f;"></div>
            </div>
            <div class="amb-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;"></div>
        </div>`;
    });
    contenedor.innerHTML = html;
    try { aplicarSoloLectura(); } catch (e) { /* seguir */ }
    ambientes.forEach(am => {
        fetch(URL_BASE + 'listar_rec_aptos.php?fotos_de=' + am.id).then(r=>r.json()).then(d=>{
            if (d.ok && d.fotos.length) {
                const row = contenedor.querySelector('.amb-row[data-amb="'+am.id+'"] .amb-fotos');
                d.fotos.forEach(f => agregarMiniFoto(row, f));
            }
        });
        fetch(URL_BASE + 'listar_rec_aptos.php?reparaciones_de=' + am.id).then(r=>r.json()).then(d=>{
            if (d.ok && d.reparaciones && d.reparaciones.length) {
                const row = contenedor.querySelector('.amb-row[data-amb="'+am.id+'"]');
                let trabajoGuardado = '';
                d.reparaciones.forEach(rep => {
                    const inp = row.querySelector('.sup-'+rep.tipo_superficie);
                    // Se muestra con coma, como se escribe en Venezuela.
                    if (inp) inp.value = String(rep.metros_cuadrados).replace('.', ',');
                    if (rep.tipo_trabajo) trabajoGuardado = rep.tipo_trabajo;
                });
                // Restaurar el tipo de trabajo elegido antes.
                if (trabajoGuardado) {
                    const sel = row.querySelector('.amb-trabajo');
                    if (sel) { sel.value = trabajoGuardado; avisoSuperficies(sel); }
                }
                recalcularMateriales(row);
            }
        });
    });
}

/**
 * Convierte el punto en coma mientras se escribe.
 * En Venezuela el separador decimal es la coma, pero los teclados de
 * celular suelen ofrecer punto. Así el técnico escribe como quiera.
 */
function normalizarDecimal(inp) {
    const pos = inp.selectionStart;
    let v = inp.value;

    // Solo dígitos y un separador.
    v = v.replace(/[^0-9.,]/g, '');
    v = v.replace(/\./g, ',');          // el punto pasa a coma
    const partes = v.split(',');
    if (partes.length > 2) {            // más de una coma: se queda la primera
        v = partes[0] + ',' + partes.slice(1).join('');
    }
    // Máximo dos decimales.
    const p = v.split(',');
    if (p[1] && p[1].length > 2) v = p[0] + ',' + p[1].slice(0, 2);

    if (v !== inp.value) {
        inp.value = v;
        try { inp.setSelectionRange(pos, pos); } catch (e) {}
    }
}

/** Convierte "12,5" a 12.5 para enviarlo al servidor. */
function aNumero(txt) {
    if (txt === null || txt === undefined) return 0;
    const n = parseFloat(String(txt).replace(',', '.'));
    return isNaN(n) ? 0 : n;
}

/** Guarda el tipo de trabajo elegido para un ambiente. */
async function guardarTrabajo(ambId, sel) {
    const payload = { accion:'guardar_trabajo', ambiente_id: ambId, tipo_trabajo: sel.value };
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
            'Tipo de trabajo · ambiente ' + ambId);
        return;
    }
    try {
        await fetch(URL_BASE + 'guardar_rec_apto.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials:'same-origin'
        });
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
                'Tipo de trabajo · ambiente ' + ambId);
        }
    }
}

function ambienteTieneMetros(row) {
    if (!row) return true;
    const chk = row.querySelector('.amb-reparar');
    if (!chk || !chk.checked) return true;   // sin reparación, no aplica
    let suma = 0;
    row.querySelectorAll('.amb-reparacion input[type=number]').forEach(i => {
        suma += aNumero(i.value);
    });
    return suma > 0;
}

/** Resalta el recuadro de fotos cuando falta la evidencia del daño. */
function marcarAmbienteSinFoto(row, falta) {
    if (!row) return;
    const cont = row.querySelector('.amb-fotos');
    if (!cont) return;
    if (falta) {
        cont.style.border = '2px dashed #A61C1C';
        cont.style.borderRadius = '8px';
        cont.style.padding = '9px';
        cont.style.background = '#fff6f6';
        if (!cont.querySelector('.amb-falta-foto')) {
            const aviso = document.createElement('div');
            aviso.className = 'amb-falta-foto';
            aviso.style.cssText = 'font-size:12px;color:#A61C1C;font-weight:600;width:100%;';
            aviso.innerHTML = '<i class="bi bi-camera-fill"></i> '
                + 'Falta la foto que muestre el daño.';
            cont.appendChild(aviso);
        }
    } else {
        cont.style.border = '';
        cont.style.padding = '';
        cont.style.background = '';
        const aviso = cont.querySelector('.amb-falta-foto');
        if (aviso) aviso.remove();
    }
}

/** Resalta en rojo los ambientes que están sin metros. */
function marcarAmbienteSinMetros(row, falta) {
    if (!row) return;
    const caja = row.querySelector('.amb-reparacion');
    if (!caja) return;
    if (falta) {
        caja.style.border = '2px solid #A61C1C';
        caja.style.background = '#fff6f6';
        let aviso = caja.querySelector('.amb-falta-m2');
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.className = 'amb-falta-m2';
            aviso.style.cssText = 'font-size:12px;color:#A61C1C;font-weight:600;margin-top:6px;';
            aviso.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> '
                + 'Falta indicar los metros cuadrados a reparar.';
            caja.appendChild(aviso);
        }
    } else {
        caja.style.border = '';
        caja.style.background = '#fbf8ef';
        const aviso = caja.querySelector('.amb-falta-m2');
        if (aviso) aviso.remove();
    }
}

async function toggleReparar(chk, ambId) {
    const row = chk.closest('.amb-row');
    row.querySelector('.amb-hint').textContent = chk.checked ? 'Suba fotos indicando la parte' : 'Suba 1 foto del estado';
    const bloque = row.querySelector('.amb-reparacion');
    if (bloque) bloque.style.display = chk.checked ? 'block' : 'none';
    await fetch(URL_BASE + 'guardar_rec_apto.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ accion:'guardar_ambiente', ambiente_id: ambId, necesita_reparacion: chk.checked?1:0 })
    });
}

async function guardarReparacion(ambId, input) {
    const row = input.closest('.amb-row');
    const reparaciones = [];
    row.querySelectorAll('[data-sup]').forEach(inp => {
        const m2 = aNumero(inp.value);
        if (m2 > 0) reparaciones.push({ tipo_superficie: inp.dataset.sup, metros_cuadrados: m2 });
    });

    // El tipo de trabajo viaja junto a los metros: así no se pierde si el
    // técnico llena los m² antes de elegir el trabajo.
    const selT = row.querySelector('.amb-trabajo');
    reparaciones.tipo_trabajo = selT ? selT.value : '';

    const payload = {
        accion: 'guardar_reparaciones', nivel: 'ambiente', ref_id: ambId,
        reparaciones: reparaciones,
        tipo_trabajo: selT ? selT.value : '',
    };

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
            'Metros del ambiente ' + ambId);
        recalcularMateriales(row);
        return;
    }

    try {
        await fetch(URL_BASE + 'guardar_rec_apto.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials:'same-origin'
        });
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_apto.php', payload,
                'Metros del ambiente ' + ambId);
        }
    }
    recalcularMateriales(row);
}

/**
 * Vista previa de materiales según el trabajo elegido y los metros.
 * Se calcula en el navegador con las mismas recetas del servidor, para
 * que funcione también sin señal.
 */
function recalcularMateriales(row) {
    const cont = row.querySelector('.amb-materiales');
    if (!cont) return;

    const selT = row.querySelector('.amb-trabajo');
    const trabajo = selT ? selT.value : '';

    let total = 0;
    row.querySelectorAll('[data-sup]').forEach(inp => { total += parseFloat(inp.value) || 0; });

    if (!total) { cont.innerHTML = ''; return; }

    if (!trabajo) {
        cont.innerHTML = '<div style="margin-top:5px;font-size:12px;color:#a8871f;">'
            + '<i class="bi bi-exclamation-circle"></i> '
            + 'Elija el trabajo para ver los materiales.</div>';
        return;
    }

    const receta = RECETAS_TRABAJO[trabajo] || [];
    if (!receta.length) { cont.innerHTML = ''; return; }

    const enteros = ['unidad', 'saco', 'pieza', 'pliego'];
    const items = receta.map(r => {
        let c = total * parseFloat(r.cantidad);
        c = enteros.includes(r.unidad) ? Math.ceil(c) : Math.round(c * 100) / 100;
        return '<span style="display:inline-block;background:#eef2fb;border-radius:14px;'
             + 'padding:2px 9px;margin:2px;font-size:11.5px;">'
             + r.material + ': <b>' + c.toLocaleString('es-VE') + '</b> ' + r.unidad + '</span>';
    }).join('');

    cont.innerHTML = '<div style="margin-top:5px;"><i class="bi bi-box-seam"></i> '
        + '<span style="font-size:11.5px;color:#5b6478;">Materiales estimados para '
        + total.toLocaleString('es-VE') + ' m²:</span><br>' + items + '</div>';
}

// ================= FOTOS (compartido) =================
// Al tocar "Foto" se abre el selector nativo del teléfono INMEDIATAMENTE
// (cámara o galería, el móvil pregunta). La "parte" (pared, techo…) se pide
// DESPUÉS de elegir la imagen, con botones — nunca con prompt, porque eso
// bloqueaba la apertura de la cámara en el móvil.
// (la variable _fotoDestino se declara al inicio del script)

/**
 * Marca que la edificación no tiene etiqueta.
 * Al activarla se oculta el botón de foto y se pide el motivo, para que
 * quede claro que no es un olvido sino una constancia.
 */
function onSinEtiqueta(chk) {
    const bloque = document.getElementById('bloque-etiqueta');
    const motivo = document.getElementById('motivo-sin-etiqueta');

    if (chk.checked) {
        // Si ya había fotos cargadas, avisar antes de marcar.
        const fotos = document.getElementById('etiqueta-fotos');
        if (fotos && fotos.children.length > 0) {
            if (!confirm('Ya hay una foto de etiqueta cargada.\n\n¿Seguro que esta edificación no tiene etiqueta?')) {
                chk.checked = false;
                return;
            }
        }
        bloque.style.display = 'none';
        motivo.style.display = 'block';
    } else {
        bloque.style.display = '';
        motivo.style.display = 'none';
    }
    guardarSinEtiqueta();
}

/** Guarda la constancia de "sin etiqueta" (funciona con y sin señal). */
async function guardarSinEtiqueta() {
    const chk = document.getElementById('sin-etiqueta');
    if (!chk) return;
    const payload = {
        inspeccion_id: INSPECCION_ID,
        accion: 'sin_etiqueta',
        sin_etiqueta: chk.checked ? 1 : 0,
        etiqueta_motivo: (document.getElementById('etiqueta-motivo') || {}).value || '',
        etiqueta_obs: (document.getElementById('etiqueta-obs') || {}).value || '',
    };

    // Copia local siempre.
    guardarBorrador('etiqueta_' + INSPECCION_ID, payload);

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
            chk.checked ? 'Sin etiqueta' : 'Tiene etiqueta');
        avisoEtiqueta('Guardado en el teléfono', '#8a6d1a');
        return;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials: 'same-origin'
        });
        const texto = await res.text();
        let d;
        try { d = JSON.parse(texto); } catch (e) { d = null; }
        if (d && d.ok) {
            avisoEtiqueta('Guardado', '#2E7D32');
        } else if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
                chk.checked ? 'Sin etiqueta' : 'Tiene etiqueta');
            avisoEtiqueta('Guardado en el teléfono', '#8a6d1a');
        }
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
                chk.checked ? 'Sin etiqueta' : 'Tiene etiqueta');
            avisoEtiqueta('Guardado en el teléfono', '#8a6d1a');
        }
    }
}

function avisoEtiqueta(texto, color) {
    let el = document.getElementById('etiqueta-aviso');
    if (!el) {
        el = document.createElement('div');
        el.id = 'etiqueta-aviso';
        el.style.cssText = 'font-size:12.5px;font-weight:600;margin:-4px 0 14px;';
        const motivo = document.getElementById('motivo-sin-etiqueta');
        if (motivo && motivo.parentNode) motivo.parentNode.insertBefore(el, motivo.nextSibling);
    }
    el.style.color = color;
    el.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + texto;
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.textContent = ''; }, 3000);
}

function subirFotoEtiqueta(btn) {
    // La etiqueta se guarda a nivel 'edificio', con parte 'etiqueta'.
    elegirOrigenFoto({
        nivel:'edificio', refId: EDIFICIO_ID,
        pideParte: false, parteFija: 'etiqueta',
        cont: document.getElementById('etiqueta-fotos')
    });
}

function subirFotoElemento(btn, pisoId, tipo) {
    const row = btn.closest('.elem-row');
    if (!row.dataset.elemId) {
        alert('Primero guarde los elementos del piso para poder adjuntar fotos.');
        return;
    }
    const reparaEl = row.querySelector('.el-reparar').checked;
    elegirOrigenFoto({
        nivel:'elemento_piso', refId: row.dataset.elemId,
        pideParte: reparaEl,
        parteFija: reparaEl ? null : 'antes',
        cont: row.querySelector('.elem-fotos')
    });
}

function fotoAmbiente(btn, ambId) {
    const row = btn.closest('.amb-row');
    const necesitaReparar = row.querySelector('.amb-reparar').checked;
    if (!necesitaReparar && row.querySelectorAll('.amb-fotos img').length >= 1) {
        alert('Este ambiente no necesita reparación: basta con una foto. Marque "Necesita reparación" para agregar más.');
        return;
    }
    elegirOrigenFoto({
        nivel:'ambiente', refId: ambId,
        pideParte: necesitaReparar,
        // Sin reparación es la foto del estado inicial: se marca como
        // "antes" para que el seguimiento la muestre.
        parteFija: necesitaReparar ? null : 'antes',
        cont: row.querySelector('.amb-fotos')
    });
}

/**
 * Pregunta si toma la foto con la cámara o la elige de la galería.
 * Las de cámara se respaldan siempre en el teléfono: esas no quedan
 * en la galería por sí solas y se perderían si falla la subida.
 */
function elegirOrigenFoto(destino) {
    _fotoDestino = destino;
    const capa = document.createElement('div');
    capa.id = 'origen-foto';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2300;'
        + 'display:flex;align-items:flex-end;justify-content:center;';
    capa.innerHTML =
        '<div style="background:#fff;border-radius:14px 14px 0 0;width:100%;max-width:440px;padding:18px 18px 22px;">'
        + '<div style="font-weight:700;color:#22366F;font-size:16px;margin-bottom:14px;text-align:center;">'
        + '¿Cómo quiere agregar la foto?</div>'
        + '<button type="button" onclick="_abrirCamara()" '
        + 'style="width:100%;display:flex;align-items:center;gap:12px;background:#22366F;color:#fff;'
        + 'border:0;border-radius:10px;padding:14px 16px;font-size:15px;font-weight:600;'
        + 'cursor:pointer;margin-bottom:10px;">'
        + '<i class="bi bi-camera-fill" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Tomar foto ahora<br>'
        + '<span style="font-size:12px;font-weight:400;opacity:.85;">Se guarda en su teléfono</span></span></button>'
        + '<button type="button" onclick="_abrirGaleria()" '
        + 'style="width:100%;display:flex;align-items:center;gap:12px;background:#fff;color:#22366F;'
        + 'border:2px solid #dbe0ec;border-radius:10px;padding:14px 16px;font-size:15px;font-weight:600;'
        + 'cursor:pointer;margin-bottom:10px;">'
        + '<i class="bi bi-images" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Elegir de la galería<br>'
        + '<span style="font-size:12px;font-weight:400;color:#5b6478;">Una foto que ya tomó</span></span></button>'
        + '<button type="button" onclick="_cerrarOrigen()" '
        + 'style="width:100%;background:transparent;border:0;color:#5b6478;padding:10px;'
        + 'font-size:14px;cursor:pointer;">Cancelar</button>'
        + '</div>';
    document.body.appendChild(capa);
}

function _cerrarOrigen() {
    const c = document.getElementById('origen-foto');
    if (c) c.remove();
}
function _abrirCamara() {
    _cerrarOrigen();
    document.getElementById('rec-file-camara').click();
}
function _abrirGaleria() {
    _cerrarOrigen();
    document.getElementById('rec-file-galeria').click();
}

async function _onFotoElegida(input, desdeCamara) {
    if (!input.files || !input.files[0] || !_fotoDestino) { input.value=''; return; }
    const archivo = input.files[0];
    const destino = _fotoDestino;
    input.value = '';            // liberar el input para la próxima
    _fotoDestino = null;

    // Si el elemento necesita reparación, preguntar la parte DESPUÉS de tener la foto.
    if (destino.parteFija) {
        enviarFoto(archivo, destino, destino.parteFija, desdeCamara);
    } else if (destino.pideParte) {
        pedirParte(parte => enviarFoto(archivo, destino, parte, desdeCamara));
    } else {
        enviarFoto(archivo, destino, null, desdeCamara);
    }
}

/* Muestra un pequeño panel con botones de parte (pared, techo, piso, closet…)
   más un campo "otra". Llama al callback con la parte elegida. */
function pedirParte(callback) {
    const partes = ['Pared','Techo','Piso','Closet','Puerta','Ventana','Baño','Otra'];
    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.55);z-index:9999;display:flex;align-items:flex-end;justify-content:center;';
    ov.innerHTML = `
      <div style="background:#fff;width:100%;max-width:480px;border-radius:16px 16px 0 0;padding:18px 18px 26px;">
        <div style="font-weight:700;color:#22366F;font-size:15px;margin-bottom:4px;">¿Qué parte muestra la foto?</div>
        <div style="font-size:12px;color:#767c94;margin-bottom:12px;">Toque una opción</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          ${partes.map(p=>`<button type="button" class="pp-btn" data-p="${p}" style="flex:1;min-width:90px;padding:12px;border:1px solid #d4d9e6;border-radius:10px;background:#f7f9fd;font-size:14px;font-weight:600;color:#2a3140;">${p}</button>`).join('')}
        </div>
        <input type="text" id="pp-otra" placeholder="Especifique si eligió Otra…" style="width:100%;margin-top:10px;padding:11px;border:1px solid #d4d9e6;border-radius:10px;font-size:14px;display:none;">
        <button type="button" id="pp-cancel" style="width:100%;margin-top:12px;padding:11px;border:0;border-radius:10px;background:#eef0f5;color:#55617f;font-size:14px;">Cancelar</button>
      </div>`;
    document.body.appendChild(ov);

    ov.querySelectorAll('.pp-btn').forEach(b => b.onclick = () => {
        if (b.dataset.p === 'Otra') {
            const inp = ov.querySelector('#pp-otra');
            inp.style.display = 'block'; inp.focus();
            inp.onkeydown = (e) => { if (e.key==='Enter' && inp.value.trim()) { document.body.removeChild(ov); callback(inp.value.trim()); } };
        } else {
            document.body.removeChild(ov);
            callback(b.dataset.p);
        }
    });
    ov.querySelector('#pp-cancel').onclick = () => document.body.removeChild(ov);
}

async function enviarFoto(archivo, destino, parte, desdeCamara) {
    const cont = destino.cont;

    // RESPALDO en el teléfono antes de intentar subirla.
    // Es imprescindible en las de CÁMARA: esas no quedan en la galería
    // por sí solas, así que si falla la subida se perderían.
    // Las de galería ya están en el teléfono, pero se respaldan igual
    // para poder reintentar sin volver a buscarlas.
    // El respaldo NO debe bloquear la subida: si IndexedDB está lento o
    // bloqueado, la foto tiene que enviarse igual.
    let idLocal = null;
    if (window.ObrasFotos) {
        try {
            idLocal = await Promise.race([
                ObrasFotos.respaldar(archivo, {
                    inspeccion_id: INSPECCION_ID, nivel: destino.nivel, ref_id: destino.refId,
                    parte: parte || '', origen: desdeCamara ? 'camara' : 'galeria',
                    descripcion: (parte ? parte + ' · ' : '') + destino.nivel + ' #' + destino.refId,
                }),
                new Promise(r => setTimeout(() => r(null), 3000)),
            ]);
        } catch (e) {
            console.warn('No se pudo respaldar la foto localmente:', e);
        }
    }

    const fd = new FormData();
    fd.append('nivel', destino.nivel);
    fd.append('ref_id', destino.refId);
    if (parte) fd.append('parte', parte);
    fd.append('foto', archivo);

    // Foto de un ambiente creado sin conexión: todavía no existe en el
    // servidor, así que se guarda indicando a qué apartamento pertenece
    // y qué posición ocupa. Al sincronizar se asocia al ambiente real.
    if (destino.nivel === 'ambiente_local') {
        await ObrasOffline.encolar('foto_ambiente_pendiente', URL_BASE + 'subir_foto_rec.php',
            { apartamento_id: destino.aptoId, ambiente_local: destino.refId,
              etiqueta: destino.etiqueta, parte: parte || 'antes',
              foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' },
            'Foto · ' + destino.etiqueta + ' (apto ' + destino.aptoId + ')');
        if (cont) {
            cont.insertAdjacentHTML('beforeend',
                '<span style="font-size:11px;color:#8a6d1a;background:#fffbf0;'
                + 'border:1px solid #C9A22755;border-radius:6px;padding:2px 7px;">'
                + '<i class="bi bi-phone-fill"></i> 1</span>');
        }
        return;
    }

    // Sin señal: queda en cola y se sube después.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('foto', URL_BASE + 'subir_foto_rec.php',
            { nivel: destino.nivel, ref_id: destino.refId, parte: parte || '',
              foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' },
            'Foto ' + (parte || '') + ' · ' + destino.nivel + ' #' + destino.refId);
        if (cont) cont.insertAdjacentHTML('beforeend',
            '<div style="text-align:center;font-size:11px;color:#8a6d1a;padding:4px 8px;">'
            + '<i class="bi bi-phone-fill"></i><br>En el teléfono</div>');
        return;
    }

    if (cont) cont.insertAdjacentHTML('beforeend', '<span class="subiendo">Subiendo…</span>');
    console.log('Subiendo foto:', {
        nivel: destino.nivel, ref_id: destino.refId, parte: parte,
        peso_kb: Math.round((archivo.size || 0) / 1024),
    });
    try {
        const res = await fetch(URL_BASE + 'subir_foto_rec.php',
            { method:'POST', body: fd, credentials:'same-origin' });
        const texto = await res.text();
        if (cont) cont.querySelector('.subiendo')?.remove();
        let data;
        try { data = JSON.parse(texto); }
        catch (e) {
            // Respuesta inesperada (sesión caída, error): no perder la foto.
            if (window.ObrasOffline) {
                await ObrasOffline.encolar('foto', URL_BASE + 'subir_foto_rec.php',
                    { nivel: destino.nivel, ref_id: destino.refId, parte: parte || '',
                      foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' },
                    'Foto ' + (parte || '') + ' · ' + destino.nivel + ' #' + destino.refId);
                alert('El servidor no respondió bien.\n\nLa foto quedó guardada en el teléfono y se subirá después.');
            } else {
                alert('El servidor respondió algo inesperado. La foto está guardada en el teléfono.');
            }
            return;
        }
        if (data.ok) {
            if (cont) agregarMiniFoto(cont, data.foto);
            if (idLocal && window.ObrasFotos) ObrasFotos.marcarSubida(idLocal);
        } else {
            // Mostrar el motivo real: antes fallaba sin decir por qué.
            console.error('Subida rechazada:', data);
            alert('No se pudo guardar la foto.\n\n'
                + (data.mensaje || 'El servidor la rechazó sin indicar el motivo.'));
        }
    } catch(e) {
        if (cont) cont.querySelector('.subiendo')?.remove();
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('foto', URL_BASE + 'subir_foto_rec.php',
                { nivel: destino.nivel, ref_id: destino.refId, parte: parte || '',
                  foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' },
                'Foto ' + (parte || '') + ' · ' + destino.nivel + ' #' + destino.refId);
            if (cont) cont.insertAdjacentHTML('beforeend',
                '<div style="text-align:center;font-size:11px;color:#8a6d1a;padding:4px 8px;">'
                + '<i class="bi bi-phone-fill"></i><br>En el teléfono</div>');
        } else {
            alert('Se perdió la conexión.\n\nLa foto está guardada en el teléfono.');
        }
    }
}

function agregarMiniFoto(cont, f) {
    const parte = f.parte
        ? '<div style="font-size:10px;color:#55617f;text-align:center;">' + f.parte + '</div>' : '';
    const alt = (f.parte || 'Foto').replace(/"/g, '&quot;').replace(/'/g, '');
    cont.insertAdjacentHTML('beforeend',
        '<div style="text-align:center;">'
        + '<img src="' + f.ruta + '" title="Toque para ampliar" '
        + 'style="width:86px;height:86px;object-fit:cover;border-radius:7px;'
        + 'border:1px solid #d8dce6;cursor:zoom-in;transition:transform .12s;" '
        + 'onmouseover="this.style.transform=\'scale(1.06)\'" '
        + 'onmouseout="this.style.transform=\'\'" '
        + 'onclick="ampliarFoto(\'' + f.ruta + '\', \'' + alt + '\')">'
        + parte + '</div>');
}

/**
 * Visor a pantalla completa para revisar las fotos del levantamiento.
 * Permite acercar hasta 4 aumentos y descargar la imagen.
 */
function ampliarFoto(ruta, titulo) {
    const capa = document.createElement('div');
    capa.id = 'lev-visor';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(10,14,24,.94);z-index:3000;'
        + 'display:flex;flex-direction:column;';

    capa.innerHTML = `
        <div style="display:flex;align-items:center;gap:11px;padding:12px 16px;
                    background:rgba(0,0,0,.35);color:#fff;flex-shrink:0;">
            <div style="flex:1;min-width:0;font-weight:700;font-size:15px;">${titulo || 'Foto'}</div>
            <button onclick="zoomLev(-1)" title="Alejar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:19px;cursor:pointer;">−</button>
            <button onclick="zoomLev(1)" title="Acercar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:19px;cursor:pointer;">+</button>
            <a href="${ruta}" download title="Descargar"
               style="background:rgba(255,255,255,.15);color:#fff;width:38px;height:38px;
                      border-radius:9px;display:flex;align-items:center;justify-content:center;
                      text-decoration:none;"><i class="bi bi-download"></i></a>
            <button onclick="cerrarVisorLev()" title="Cerrar"
                    style="background:rgba(255,255,255,.15);border:0;color:#fff;width:38px;height:38px;
                           border-radius:9px;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="lev-visor-cont" style="flex:1;overflow:auto;display:flex;align-items:center;
                                        justify-content:center;padding:14px;">
            <img id="lev-visor-img" src="${ruta}" style="max-width:100%;max-height:100%;
                 transition:transform .18s;transform-origin:center;">
        </div>
        <div style="padding:9px 16px;background:rgba(0,0,0,.35);color:#ffffffaa;
                    font-size:12px;text-align:center;flex-shrink:0;">
            Toque + o − para acercar · Toque fuera de la imagen para cerrar
        </div>`;

    capa.addEventListener('click', e => {
        if (e.target === capa || e.target.id === 'lev-visor-cont') cerrarVisorLev();
    });
    document.body.appendChild(capa);
    window._zoomLev = 1;
    document.addEventListener('keydown', _escLev);
}

function _escLev(e) { if (e.key === 'Escape') cerrarVisorLev(); }

function cerrarVisorLev() {
    const v = document.getElementById('lev-visor');
    if (v) v.remove();
    document.removeEventListener('keydown', _escLev);
}

function zoomLev(dir) {
    const img = document.getElementById('lev-visor-img');
    if (!img) return;
    window._zoomLev = Math.min(4, Math.max(0.5, (window._zoomLev || 1) + dir * 0.35));
    img.style.transform = 'scale(' + window._zoomLev + ')';
}

// ================= PASO 3: CIERRE Y RESUMEN =================
// Muestra la foto solo si el estado es "Requiere reparación".
function onEstadoCierre(key) {
    const sel = document.querySelector('input[name="'+key+'_estado"]:checked');
    const foto = document.getElementById('cierre-foto-' + key);
    if (foto) foto.style.display = (sel && sel.value === 'Requiere reparación') ? 'block' : 'none';
}

// Subir foto del área de cierre (azotea/tanques) que requiere reparación.
function subirFotoCierre(key, btn) {
    elegirOrigenFoto({
        nivel:'edificio', refId: EDIFICIO_ID, pideParte:false, parteFija: key + '_reparacion',
        cont: btn.parentElement.querySelector('.cierre-fotos')
    });
}

async function guardarCierre(ev) {
    ev.preventDefault();
    if (!PUEDE_EDITAR) return false;
    const form = document.getElementById('form-cierre');
    const payload = { inspeccion_id: INSPECCION_ID, accion:'cierre' };
    ['azotea','tanques'].forEach(k => {
        const sel = form.querySelector('input[name="'+k+'_estado"]:checked');
        payload[k+'_estado'] = sel ? sel.value : '';
        payload[k+'_obs'] = form.querySelector('input[name="'+k+'_obs"]').value;
    });
    payload.fecha_inicio_estimada = form.querySelector('input[name="fecha_inicio_estimada"]').value;
    payload.fecha_fin_estimada = form.querySelector('input[name="fecha_fin_estimada"]').value;

    // Copia local del cierre, por si falla el envío.
    guardarBorrador('cierre_' + INSPECCION_ID, payload);

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
            'Cierre del levantamiento');
        alert('Sin señal: el cierre quedó guardado en el teléfono y se enviará al recuperar la conexión.');
        ofrecerComprobante();
        return false;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload), credentials:'same-origin'
        });
        const texto = await res.text();
        let data;
        try { data = JSON.parse(texto); }
        catch (e) {
            if (window.ObrasOffline) {
                await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
                    'Cierre del levantamiento');
                alert('El servidor no respondió bien. El cierre quedó guardado y se reintentará.');
            }
            return false;
        }
        if (data.ok) {
            borrarBorrador('cierre_' + INSPECCION_ID);
            cargarResumen();
            ofrecerComprobante();
        } else if (data.puede_confirmar) {
            // Faltan datos, pero el técnico puede cerrar igual si lo decide.
            if (confirm(data.mensaje)) {
                payload.confirmar_incompleto = 1;
                const res2 = await fetch(URL_BASE + 'guardar_rec_edificio.php', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify(payload), credentials:'same-origin'
                });
                const d2 = await res2.json();
                if (d2.ok) {
                    borrarBorrador('cierre_' + INSPECCION_ID);
                    cargarResumen();
                    ofrecerComprobante();
                } else {
                    alert(d2.mensaje || 'No se pudo cerrar.');
                }
            }
        } else {
            alert(data.mensaje || 'Error al guardar.');
        }
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_rec_edificio.php', payload,
                'Cierre del levantamiento');
            alert('Se perdió la conexión. El cierre quedó guardado en el teléfono.');
            ofrecerComprobante();
        } else {
            alert('Se perdió la conexión. Intente cerrar de nuevo cuando tenga señal.');
        }
    }
    return false;
}

/**
 * Al cerrar, ofrece descargar el comprobante en PDF.
 * Queda en el teléfono como respaldo de todo lo registrado.
 */
/**
 * Comprobante generado EN EL TELÉFONO, sin necesidad de servidor.
 * Abre una ventana lista para imprimir o guardar como PDF.
 * Es la constancia de lo registrado cuando se trabajó sin señal.
 */
function comprobanteLocal() {
    const est = window._estructuraLocal
        || JSON.parse(localStorage.getItem('estructura_' + INSPECCION_ID) || 'null');

    const ahora = new Date();
    const fecha = ahora.toLocaleDateString('es-VE');
    const hora  = ahora.toLocaleTimeString('es-VE', {hour:'2-digit', minute:'2-digit'});

    let totApt = 0, totJefes = 0, totAmb = 0;
    let filas = '';

    if (est && est.pisos) {
        est.pisos.forEach(p => {
            const aptos = p.apartamentos || [];
            if (!aptos.length) return;
            filas += '<tr><td colspan="6" style="background:#eef2fb;font-weight:700;color:#22366F;'
                + 'padding:7px 6px;">Piso ' + p.numero_piso + '</td></tr>';
            aptos.forEach(a => {
                totApt++;
                if (a.jefe_nombre) totJefes++;
                const amb = (parseInt(a.num_habitaciones)||0) + (parseInt(a.num_salas)||0)
                          + (parseInt(a.num_banos)||0) + (parseInt(a.num_cocinas)||0)
                          + (parseInt(a.num_balcones)||0);
                totAmb += amb;
                filas += '<tr>'
                    + '<td style="font-weight:700;color:#22366F;">' + (a.identificador||'') + '</td>'
                    + '<td>' + (a.jefe_nombre || '— sin registrar —') + '</td>'
                    + '<td>' + (a.jefe_cedula || '—') + '</td>'
                    + '<td>' + (a.jefe_telefono || '—') + '</td>'
                    + '<td style="text-align:center;">' + amb + '</td>'
                    + '<td style="text-align:center;color:#8a6d1a;font-size:10px;">pendiente</td>'
                    + '</tr>';
            });
        });
    }

    if (!filas) {
        alert('Todavía no hay apartamentos registrados para incluir en el comprobante.');
        return;
    }

    const aviso = totJefes < totApt
        ? '<div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:7px;'
          + 'padding:8px 11px;font-size:11px;color:#8a6d1a;margin-bottom:10px;">'
          + '<b>Atención:</b> ' + (totApt - totJefes) + ' apartamento(s) sin datos del jefe de familia.</div>'
        : '';

    const html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        + '<title>Comprobante del levantamiento</title><style>'
        + '*{font-family:Arial,sans-serif;box-sizing:border-box;}'
        + 'body{margin:24px;color:#1a1f2b;font-size:12px;}'
        + '.cab{border-bottom:3px solid #C9A227;padding-bottom:12px;margin-bottom:14px;}'
        + '.cab h1{margin:0;font-size:21px;color:#22366F;}'
        + '.cab .sub{color:#55617f;font-size:12px;margin-top:3px;}'
        + '.cab .folio{float:right;text-align:right;font-size:11px;color:#55617f;}'
        + 'h2{font-size:12px;text-transform:uppercase;color:#22366F;margin:15px 0 7px;'
        + 'padding-bottom:4px;border-bottom:1px solid #e8ebf3;}'
        + 'table{width:100%;border-collapse:collapse;}'
        + 'th{background:#22366F;color:#fff;font-size:10px;padding:6px 5px;text-align:left;text-transform:uppercase;}'
        + 'td{font-size:10.5px;padding:5px;border-bottom:1px solid #e8ebf3;}'
        + '.datos td{padding:4px 6px;font-size:11.5px;}'
        + '.datos .lbl{color:#55617f;width:130px;}'
        + '.res{display:table;width:100%;border-spacing:6px;margin-bottom:8px;}'
        + '.rc{display:table-cell;text-align:center;padding:10px 6px;border:1px solid #dde3ef;border-radius:8px;}'
        + '.rc .n{font-size:20px;font-weight:800;color:#22366F;}'
        + '.rc .l{font-size:9px;text-transform:uppercase;color:#55617f;}'
        + '.nota{background:#f4f7fd;border-radius:8px;padding:9px 12px;font-size:11px;color:#3a4256;margin-bottom:12px;}'
        + '.firma{margin-top:26px;display:table;width:100%;border-spacing:20px;}'
        + '.firma div{display:table-cell;width:50%;border-top:1px solid #2a3140;padding-top:5px;'
        + 'font-size:10.5px;color:#55617f;text-align:center;}'
        + '@media print{.noprint{display:none;}}'
        + '</style></head><body>'
        + '<div class="cab"><div class="folio">' + fecha + '<br>' + hora + '</div>'
        + '<h1>Levantamiento técnico</h1>'
        + '<div class="sub">Gestión de Obras Avanzadas · Constancia registrada sin conexión</div></div>'
        + '<div class="nota"><b>Este comprobante se generó en el teléfono, sin conexión.</b> '
        + 'Los datos están guardados en el dispositivo y se enviarán al sistema al recuperar la señal. '
        + 'Consérvelo como respaldo de lo registrado.</div>'
        + '<h2>Edificación</h2>'
        + '<table class="datos">'
        + '<tr><td class="lbl">Nombre</td><td><b>' + (EDIFICIO_NOMBRE || '—') + '</b></td>'
        + '<td class="lbl">Código</td><td><b>' + (EDIFICIO_CODIGO || '—') + '</b></td></tr>'
        + '<tr><td class="lbl">Parroquia</td><td><b>' + (EDIFICIO_PARROQUIA || '—') + '</b></td>'
        + '<td class="lbl">Pisos</td><td><b>' + (est ? est.pisos.length : 0) + '</b></td></tr>'
        + '</table>'
        + '<h2>Resumen de lo registrado</h2>'
        + '<div class="res">'
        + '<div class="rc"><div class="n">' + (est ? est.pisos.length : 0) + '</div><div class="l">Pisos</div></div>'
        + '<div class="rc"><div class="n">' + totApt + '</div><div class="l">Apartamentos</div></div>'
        + '<div class="rc"><div class="n">' + totJefes + '</div><div class="l">Jefes de familia</div></div>'
        + '<div class="rc"><div class="n">' + totAmb + '</div><div class="l">Ambientes</div></div>'
        + '</div>' + aviso
        + '<h2>Detalle</h2>'
        + '<table><thead><tr><th style="width:52px;">Apto</th><th>Jefe de familia</th>'
        + '<th style="width:86px;">Cédula</th><th style="width:92px;">Teléfono</th>'
        + '<th style="width:60px;">Ambientes</th><th style="width:70px;">Estado</th>'
        + '</tr></thead><tbody>' + filas + '</tbody></table>'
        + '<div class="firma"><div>Firma del técnico responsable</div>'
        + '<div>Firma del supervisor</div></div>'
        + '<p class="noprint" style="margin-top:18px;text-align:center;">'
        + '<button onclick="window.print()" style="padding:10px 24px;background:#22366F;color:#fff;'
        + 'border:0;border-radius:8px;cursor:pointer;font-size:15px;font-weight:600;">'
        + 'Guardar como PDF</button></p>'
        + '<script>window.onload=function(){setTimeout(function(){window.print();},500);};<\/script>'
        + '</body></html>';

    const w = window.open('', '_blank');
    if (!w) { alert('El navegador bloqueó la ventana. Permita las ventanas emergentes e intente de nuevo.'); return; }
    w.document.write(html);
    w.document.close();
}

function ofrecerComprobante() {
    const capa = document.createElement('div');
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2100;'
        + 'display:flex;align-items:center;justify-content:center;padding:16px;';
    capa.innerHTML =
        '<div style="background:#fff;border-radius:12px;max-width:400px;width:100%;padding:22px 24px;text-align:center;">'
        + '<div style="font-size:42px;color:#2E7D32;line-height:1;"><i class="bi bi-check-circle-fill"></i></div>'
        + '<h3 style="margin:10px 0 4px;color:#22366F;font-size:19px;">Levantamiento cerrado</h3>'
        + '<p style="font-size:13.5px;color:#5b6478;margin:0 0 16px;">'
        + 'Guarde el comprobante en su teléfono. Es la constancia de todo lo que registró, '
        + 'por si hiciera falta verificarlo.</p>'
        + (navigator.onLine
            ? '<a href="' + URL_BASE + 'comprobante_levantamiento.php?inspeccion=' + INSPECCION_ID + '" '
              + 'target="_blank" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:8px;">'
              + '<i class="bi bi-file-earmark-pdf-fill"></i> Descargar comprobante</a>'
            : '<button onclick="comprobanteLocal()" class="btn btn-primary" '
              + 'style="width:100%;justify-content:center;margin-bottom:8px;">'
              + '<i class="bi bi-file-earmark-pdf-fill"></i> Generar comprobante</button>')
        + '<button onclick="this.closest(\'div\').parentElement.remove()" '
        + 'style="width:100%;background:transparent;border:1px solid #dbe0ec;border-radius:8px;'
        + 'padding:9px;color:#55617f;cursor:pointer;font-size:13.5px;">Ahora no</button>'
        + '</div>';
    document.body.appendChild(capa);
}

async function cargarResumen() {
    const cont = document.getElementById('resumen-materiales');
    if (!cont) return;
    cont.innerHTML = '<p class="text-muted">Calculando materiales del edificio…</p>';

    let data;
    try {
        const res = await fetch(URL_BASE + 'resumen_materiales.php?edificio_id=' + EDIFICIO_ID,
                                { credentials: 'same-origin' });
        const texto = await res.text();
        try { data = JSON.parse(texto); }
        catch (e) {
            console.error('Resumen: respuesta inesperada', texto.slice(0, 200));
            cont.innerHTML = '<p class="text-muted">No se pudo cargar el resumen.</p>';
            return;
        }
    } catch (e) {
        cont.innerHTML = '<p class="text-muted">Sin conexión para calcular el resumen.</p>';
        return;
    }

    if (!data.ok) {
        cont.innerHTML = '<p class="text-muted">' + (data.mensaje || 'No se pudo cargar el resumen.') + '</p>';
        return;
    }

    const mats = Object.entries(data.materiales || {});
    const trab = Object.entries(data.por_trabajo || {});

    let html = '<div style="border:3px solid #C9A227;border-radius:12px;overflow:hidden;">'
        + '<div style="background:#C9A227;color:#22366F;padding:13px 18px;">'
        + '<div style="font-size:17px;font-weight:800;">'
        + '<i class="bi bi-building-fill-check"></i> MATERIALES DEL EDIFICIO COMPLETO</div>'
        + '<div style="font-size:12.5px;font-weight:600;opacity:.85;margin-top:2px;">'
        + 'Suma de todos los pisos, apartamentos y áreas comunes</div></div>'
        + '<div style="padding:16px 18px;background:#fffdf5;">';

    if (data.total_m2 > 0) {
        html += '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">'
            + '<div style="background:#fff;border:1px solid #C9A22755;border-radius:10px;padding:12px 18px;">'
            + '<div style="font-size:26px;font-weight:800;color:#22366F;line-height:1;">'
            + Number(data.total_m2).toLocaleString('es-VE', {maximumFractionDigits:2}) + ' m²</div>'
            + '<div style="font-size:11px;color:#5b6478;text-transform:uppercase;margin-top:3px;">'
            + 'Total a reparar</div></div>';

        trab.forEach(([nombre, cant]) => {
            html += '<div style="background:#fff;border:1px solid #e5e8f0;border-radius:10px;padding:12px 15px;">'
                + '<div style="font-size:17px;font-weight:700;color:#2d4488;">'
                + Number(cant).toLocaleString('es-VE') + ' m²</div>'
                + '<div style="font-size:11.5px;color:#5b6478;">' + nombre + '</div></div>';
        });
        html += '</div>';
    }

    if (mats.length) {
        html += '<table style="width:100%;border-collapse:collapse;background:#fff;border-radius:9px;overflow:hidden;">'
            + '<tr style="background:#22366F;color:#fff;">'
            + '<th style="text-align:left;padding:9px 12px;font-size:12px;text-transform:uppercase;">Material</th>'
            + '<th style="text-align:right;padding:9px 12px;font-size:12px;text-transform:uppercase;">Cantidad</th></tr>';
        mats.forEach(([m, c], i) => {
            html += '<tr style="background:' + (i % 2 ? '#f7f9fd' : '#fff') + ';">'
                + '<td style="padding:8px 12px;font-size:13.5px;">' + m + '</td>'
                + '<td style="padding:8px 12px;text-align:right;font-weight:700;color:#22366F;font-size:14px;">'
                + Number(c).toLocaleString('es-VE') + '</td></tr>';
        });
        html += '</table>'
            + '<div style="font-size:11.5px;color:#8a6d1a;margin-top:9px;">'
            + '<i class="bi bi-info-circle"></i> Cálculo aproximado. Verifique en obra antes de solicitar.</div>';
    } else if (data.total_m2 > 0) {
        html += '<div style="background:#fff;border:1px solid #C9A22755;border-radius:9px;'
            + 'padding:12px 15px;font-size:13px;color:#8a6d1a;">'
            + '<i class="bi bi-exclamation-triangle-fill"></i> '
            + '<strong>Hay metros registrados pero no se calcularon materiales.</strong><br>'
            + 'Falta indicar qué trabajo hay que hacer en cada ambiente.</div>';
    } else {
        html += '<p style="margin:0;color:#5b6478;font-size:13.5px;">'
            + 'Todavía no hay reparaciones con metros cuadrados registrados.</p>';
    }

    html += '</div></div>';
    cont.innerHTML = html;
}

// Cargar la foto de etiqueta si ya existe.
(function cargarEtiqueta() {
    fetch(URL_BASE + 'listar_rec_aptos.php?fotos_nivel=edificio&ref_id=' + EDIFICIO_ID)
        .then(r => r.json()).then(d => {
            if (d.ok && d.fotos && d.fotos.length) {
                const cont = document.getElementById('etiqueta-fotos');
                d.fotos.filter(f => f.parte === 'etiqueta').forEach(f => agregarMiniFoto(cont, f));
            }
        }).catch(()=>{});
})();

// Si venimos de guardar el paso 1, abrir directo el recorrido.
<?php if (($_GET['paso'] ?? '') === '2'): ?>
irPaso(2);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
