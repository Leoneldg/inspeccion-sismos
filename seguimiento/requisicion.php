<?php
/**
 * REQUISICIÓN — el documento.
 *
 * Muestra una requisición y permite prepararla mientras esté en
 * borrador. Al emitirla queda cerrada como constancia de lo solicitado.
 *
 * Uso: requisicion.php?id=12
 *      requisicion.php?inspeccion=4449   (abre la última o crea una)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/requisiciones.php';

requierePermiso('seguimiento', 'ver');
$puedeEditar = puede('seguimiento', 'editar');
reqAsegurarTablas();

$reqId = (int)($_GET['id'] ?? 0);

// Si llega por inspección, se busca la última requisición de esa
// edificación; si no hay ninguna, se ofrece crearla.
if ($reqId <= 0) {
    $inspeccionId = (int)($_GET['inspeccion'] ?? 0);
    if ($inspeccionId <= 0) {
        header('Location: ' . APP_URL_BASE . 'seguimiento/requisiciones.php');
        exit;
    }
    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) {
        flash('error', 'Esta edificación todavía no tiene levantamiento técnico.');
        header('Location: ' . APP_URL_BASE . 'seguimiento/requisiciones.php');
        exit;
    }
    $lista = reqDeEdificio($edificioId);
    if ($lista) {
        header('Location: ' . APP_URL_BASE . 'seguimiento/requisicion.php?id=' . (int)$lista[0]['id']);
        exit;
    }
    if (!$puedeEditar) {
        flash('error', 'Esta edificación todavía no tiene requisiciones.');
        header('Location: ' . APP_URL_BASE . 'seguimiento/requisiciones.php');
        exit;
    }
    $nueva = reqCrear($edificioId);
    if (empty($nueva['ok'])) {
        flash('error', $nueva['mensaje'] ?? 'No se pudo crear la requisición.');
        header('Location: ' . APP_URL_BASE . 'seguimiento/requisiciones.php');
        exit;
    }
    header('Location: ' . APP_URL_BASE . 'seguimiento/requisicion.php?id=' . (int)$nueva['id']);
    exit;
}

$req = reqObtener($reqId);
if (!$req) {
    flash('error', 'La requisición no existe.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/requisiciones.php');
    exit;
}

$editable    = $puedeEditar && reqEsEditable($req);
$estadoInfo  = reqEstadoInfo($req['estado'] ?? 'borrador');
$rubros      = reqRubros();
$itemsPorRub = reqItemsPorRubro();
$renglones   = reqRenglones($reqId);
$unidades    = reqUnidades();
$totalRen    = reqTotalRenglones($reqId);
$otrasReq    = $editable ? reqParaCopiar($reqId) : [];
$hermanas    = reqDeEdificio((int)$req['edificio_id']);

$pageTitle    = $req['numero'];
$pageSubtitle = $req['nombre_edificio'] ?: 'Edificación';
$activeModule = 'requisiciones';
include __DIR__ . '/../includes/header.php';
?>

<style>
.rq-wrap { max-width: 1000px; margin: 0 auto; }

/* Cabecera del documento */
.rq-doc {
    background: #fff; border: 1px solid #e6e9f2; border-radius: 12px;
    padding: 0; margin-bottom: 16px; overflow: hidden;
}
.rq-doc-top {
    background: linear-gradient(135deg, #22366F 0%, #2d4488 100%);
    color: #fff; padding: 16px 20px;
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 14px; flex-wrap: wrap;
}
.rq-num { font-size: 20px; font-weight: 800; letter-spacing: .5px; }
.rq-edif { font-size: 13px; opacity: .9; margin-top: 2px; }
.rq-sello {
    border-radius: 20px; padding: 5px 14px; font-size: 12px;
    font-weight: 700; white-space: nowrap; display: inline-flex;
    align-items: center; gap: 6px;
}
.rq-doc-datos {
    padding: 12px 20px; display: flex; gap: 26px; flex-wrap: wrap;
    border-bottom: 1px solid #eef0f5; background: #fafbfe;
}
.rq-dato .et {
    font-size: 10.5px; text-transform: uppercase; color: #8a93a8;
    letter-spacing: .3px; font-weight: 600;
}
.rq-dato .va { font-size: 13px; color: #2a3140; font-weight: 600; margin-top: 1px; }
.rq-doc-pie { padding: 12px 20px; display: flex; gap: 9px; flex-wrap: wrap; align-items: center; }

/* Aviso de estado */
.rq-aviso-estado {
    padding: 10px 14px; border-radius: 8px; font-size: 12.5px;
    margin-bottom: 15px; line-height: 1.5;
    display: flex; align-items: flex-start; gap: 9px;
}

/* Formulario */
.rq-form {
    background: #fff; border: 1px solid #e6e9f2; border-radius: 12px;
    padding: 16px 18px; margin-bottom: 16px;
}
.rq-form h3 {
    margin: 0 0 12px; font-size: 14.5px; color: #22366F;
    display: flex; align-items: center; gap: 7px;
}
.rq-fila { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.rq-campo { flex: 1 1 190px; min-width: 0; }
.rq-campo label {
    display: block; font-size: 11.5px; font-weight: 600;
    color: #45506b; margin-bottom: 4px;
}
.rq-campo .form-control { width: 100%; }
.rq-campo-chico { flex: 0 0 110px; }

/* Renglones por rubro */
.rq-rubro {
    background: #fff; border: 1px solid #e6e9f2;
    border-radius: 12px; margin-bottom: 12px; overflow: hidden;
}
.rq-rubro-cab {
    padding: 11px 16px; display: flex; align-items: center;
    justify-content: space-between; gap: 10px; cursor: pointer;
    background: #f7f9fd; border-bottom: 1px solid #eef0f5;
}
.rq-rubro-cab .tit {
    display: flex; align-items: center; gap: 9px;
    font-weight: 700; font-size: 13.5px;
}
.rq-rubro-cab .cuenta {
    font-size: 11.5px; color: #5b6478; font-weight: 600;
    background: #fff; border: 1px solid #e6e9f2;
    border-radius: 20px; padding: 2px 10px;
}
.rq-linea {
    display: flex; align-items: center; gap: 11px;
    padding: 9px 16px; border-bottom: 1px solid #f4f6fa;
}
.rq-linea:last-child { border-bottom: 0; }
.rq-linea:hover { background: #fafbfe; }
.rq-linea .nom { flex: 1; min-width: 0; font-size: 13px; color: #2a3140; }
.rq-linea .nom small { display: block; color: #8a93a8; font-size: 11px; margin-top: 1px; }
.rq-linea .cant {
    font-weight: 800; color: #22366F; font-size: 14.5px;
    white-space: nowrap; text-align: right;
}
.rq-linea .cant span { font-weight: 500; color: #5b6478; font-size: 11.5px; }
.rq-acciones { display: flex; gap: 5px; flex-shrink: 0; }
.rq-btn-min {
    border: 1px solid #e0e4ee; background: #fff; border-radius: 7px;
    width: 30px; height: 30px; cursor: pointer; color: #5b6478;
    display: flex; align-items: center; justify-content: center; font-size: 13px;
}
.rq-btn-min:hover { background: #f2f5fc; color: #22366F; }
.rq-btn-min.borrar:hover { background: #fdf0f0; color: #A61C1C; border-color: #A61C1C44; }

.rq-vacio {
    background: #fff; border: 2px dashed #dde2ee; border-radius: 12px;
    padding: 34px 20px; text-align: center; color: #767c94;
}
.rq-vacio i { font-size: 34px; color: #c3cade; display: block; margin-bottom: 9px; }

#rq-aviso { font-size: 12.5px; margin-top: 9px; min-height: 18px; }

/* Otras requisiciones del mismo edificio */
.rq-hermanas {
    background: #fff; border: 1px solid #e6e9f2; border-radius: 11px;
    padding: 11px 15px; margin-bottom: 15px; font-size: 12.5px;
}
.rq-hermana {
    display: inline-flex; align-items: center; gap: 6px;
    border: 1px solid #e6e9f2; border-radius: 20px;
    padding: 3px 11px; margin: 3px 4px 3px 0;
    text-decoration: none; color: #45506b; font-weight: 600; font-size: 11.5px;
}
.rq-hermana.actual { background: #22366F; color: #fff; border-color: #22366F; }

@media (max-width: 700px) {
    .rq-campo, .rq-campo-chico { flex: 1 1 100%; }
    .rq-linea { flex-wrap: wrap; }
    .rq-linea .cant { text-align: left; }
    .rq-doc-datos { gap: 14px; }
}
</style>

<div class="rq-wrap">

    <!-- ============ El documento ============ -->
    <div class="rq-doc">
        <div class="rq-doc-top">
            <div>
                <div class="rq-num"><?= e($req['numero']) ?></div>
                <div class="rq-edif">
                    <i class="bi bi-building"></i>
                    <?= e($req['nombre_edificio'] ?: 'Edificación') ?>
                    <?= !empty($req['codigo']) ? ' · ' . e($req['codigo']) : '' ?>
                    <?= !empty($req['parroquia']) ? ' · ' . e($req['parroquia']) : '' ?>
                </div>
            </div>
            <span class="rq-sello"
                  style="background:<?= e($estadoInfo['fondo']) ?>;color:<?= e($estadoInfo['color']) ?>;">
                <i class="bi <?= e($estadoInfo['icono']) ?>"></i>
                <?= e($estadoInfo['nombre']) ?>
            </span>
        </div>

        <div class="rq-doc-datos">
            <?php $sol = reqSolicitante($req); ?>
            <div class="rq-dato">
                <div class="et">Solicitado por</div>
                <div class="va"><?= e($sol['nombre'] ?: '—') ?></div>
                <?php if (!empty($sol['profesion'])): ?>
                <div style="font-size:11px;color:#8a93a8;"><?= e($sol['profesion']) ?></div>
                <?php endif; ?>
            </div>
            <div class="rq-dato">
                <div class="et">Fecha de creación</div>
                <div class="va">
                    <?= !empty($req['creado_en']) ? date('d/m/Y', strtotime($req['creado_en'])) : '—' ?>
                </div>
            </div>
            <?php if (!empty($req['emitida_en'])): ?>
            <div class="rq-dato">
                <div class="et">Emitida</div>
                <div class="va" style="color:#2E7D32;">
                    <?= date('d/m/Y H:i', strtotime($req['emitida_en'])) ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="rq-dato">
                <div class="et">Materiales</div>
                <div class="va"><?= (int)$totalRen ?> renglón<?= $totalRen === 1 ? '' : 'es' ?></div>
            </div>
        </div>

        <div class="rq-doc-pie">
            <a href="<?= APP_URL_BASE ?>seguimiento/requisiciones.php" class="btn btn-outline btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
            <a href="<?= APP_URL_BASE ?>seguimiento/pdf_requisicion.php?id=<?= (int)$reqId ?>"
               target="_blank" class="btn btn-outline btn-sm"
               style="border-color:#A61C1C55;color:#A61C1C;">
                <i class="bi bi-printer"></i> Imprimir
            </a>

            <?php if ($puedeEditar): ?>
                <?php if ($editable): ?>
                <button type="button" class="btn btn-primary btn-sm"
                        style="margin-left:auto;background:#2E7D32;border-color:#2E7D32;"
                        onclick="rqEmitir(this)">
                    <i class="bi bi-send-check-fill"></i> Emitir requisición
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline btn-sm" style="margin-left:auto;"
                        onclick="rqReabrir(this)">
                    <i class="bi bi-unlock"></i> Reabrir para corregir
                </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Aviso del estado -->
    <div class="rq-aviso-estado"
         style="background:<?= e($estadoInfo['fondo']) ?>;color:<?= e($estadoInfo['color']) ?>;">
        <i class="bi <?= e($estadoInfo['icono']) ?>" style="font-size:16px;"></i>
        <div>
            <strong><?= e($estadoInfo['nombre']) ?>.</strong>
            <?= e($estadoInfo['ayuda']) ?>
            <?php if (!empty($req['reabierta_en'])): ?>
            <br><span style="font-size:11.5px;opacity:.85;">
                Esta requisición fue reabierta el
                <?= date('d/m/Y H:i', strtotime($req['reabierta_en'])) ?>.
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($hermanas) > 1): ?>
    <div class="rq-hermanas">
        <strong style="color:#45506b;">Otras requisiciones de esta edificación:</strong><br>
        <?php foreach ($hermanas as $h):
            $hi = reqEstadoInfo($h['estado']); ?>
        <a class="rq-hermana <?= (int)$h['id'] === $reqId ? 'actual' : '' ?>"
           href="<?= APP_URL_BASE ?>seguimiento/requisicion.php?id=<?= (int)$h['id'] ?>">
            <i class="bi <?= e($hi['icono']) ?>"></i>
            <?= e($h['numero']) ?>
            <span style="opacity:.7;">(<?= (int)$h['n_renglones'] ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($editable): ?>
    <!-- ============ Agregar material ============ -->
    <div class="rq-form">
        <h3><i class="bi bi-plus-circle-fill"></i> Agregar material a la requisición</h3>

        <div class="rq-fila">
            <div class="rq-campo">
                <label>Rubro</label>
                <select id="rq-rubro" class="form-control" onchange="rqCambiarRubro()">
                    <option value="">— Elija el rubro —</option>
                    <?php foreach ($rubros as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= e($r['nombre']) ?></option>
                    <?php endforeach; ?>
                    <option value="__nuevo__">+ Agregar un rubro nuevo…</option>
                </select>
            </div>
            <div class="rq-campo">
                <label>Material</label>
                <select id="rq-item" class="form-control" onchange="rqCambiarItem()" disabled>
                    <option value="">— Elija primero el rubro —</option>
                </select>
            </div>
            <div class="rq-campo rq-campo-chico">
                <label>Cantidad</label>
                <input type="text" id="rq-cantidad" class="form-control"
                       inputmode="decimal" placeholder="0">
            </div>
            <div class="rq-campo rq-campo-chico">
                <label>Unidad</label>
                <select id="rq-unidad" class="form-control">
                    <?php foreach ($unidades as $u): ?>
                    <option value="<?= e($u) ?>"><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="rq-libre-caja" style="display:none;margin-top:10px;">
            <label style="display:block;font-size:11.5px;font-weight:600;color:#45506b;margin-bottom:4px;">
                Nombre del material
            </label>
            <input type="text" id="rq-libre" class="form-control" maxlength="160"
                   placeholder="Escriba el material que necesita">
            <label style="display:flex;align-items:center;gap:6px;margin-top:7px;
                          font-size:12px;color:#45506b;cursor:pointer;">
                <input type="checkbox" id="rq-guardar-catalogo" checked>
                Guardarlo en el catálogo para poder reutilizarlo
            </label>
        </div>

        <div style="margin-top:10px;">
            <label style="display:block;font-size:11.5px;font-weight:600;color:#45506b;margin-bottom:4px;">
                Nota <span style="font-weight:400;color:#8a93a8;">(opcional)</span>
            </label>
            <input type="text" id="rq-nota" class="form-control" maxlength="300"
                   placeholder="Por ejemplo: para el tablero del piso 3">
        </div>

        <div style="margin-top:12px;display:flex;gap:9px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-primary" id="rq-btn-guardar"
                    onclick="rqGuardar(this)">
                <i class="bi bi-check2-circle"></i> Agregar
            </button>
            <input type="hidden" id="rq-renglon-id" value="">
            <button type="button" class="btn btn-outline" id="rq-btn-cancelar"
                    style="display:none;" onclick="rqCancelarEdicion()">
                Cancelar
            </button>

            <?php if ($otrasReq): ?>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                <select id="rq-copiar" class="form-control" style="font-size:12px;max-width:250px;">
                    <option value="">— Copiar de otra requisición —</option>
                    <?php foreach ($otrasReq as $o): ?>
                    <option value="<?= (int)$o['id'] ?>">
                        <?= e($o['numero']) ?> · <?= e($o['nombre_edificio'] ?: $o['codigo']) ?> (<?= (int)$o['n'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline btn-sm" onclick="rqCopiar(this)">
                    <i class="bi bi-clipboard-plus"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div id="rq-aviso"></div>
    </div>
    <?php endif; ?>

    <!-- ============ Renglones ============ -->
    <?php if (!$renglones): ?>
    <div class="rq-vacio">
        <i class="bi bi-inboxes"></i>
        <div style="font-weight:600;color:#5b6478;margin-bottom:4px;">
            Esta requisición todavía no tiene materiales
        </div>
        <div style="font-size:12.5px;">
            <?= $editable
                ? 'Use el formulario de arriba para agregarlos.'
                : 'No se cargó ningún material.' ?>
        </div>
    </div>
    <?php else: ?>
        <?php foreach ($renglones as $rid => $grupo): ?>
        <div class="rq-rubro">
            <div class="rq-rubro-cab" onclick="rqPlegar(this)">
                <div class="tit" style="color:<?= e($grupo['rubro']['color']) ?>;">
                    <i class="bi <?= e($grupo['rubro']['icono']) ?>"></i>
                    <?= e($grupo['rubro']['nombre']) ?>
                </div>
                <div class="cuenta">
                    <?= count($grupo['lineas']) ?> material<?= count($grupo['lineas']) === 1 ? '' : 'es' ?>
                </div>
            </div>
            <div class="rq-rubro-cuerpo">
                <?php foreach ($grupo['lineas'] as $l): ?>
                <div class="rq-linea" data-renglon="<?= (int)$l['id'] ?>">
                    <div class="nom">
                        <?= e($l['material'] ?: 'Sin nombre') ?>
                        <?php if (!empty($l['nota'])): ?>
                        <small><i class="bi bi-sticky"></i> <?= e($l['nota']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="cant">
                        <?= reqFormatoCantidad((float)$l['cantidad'], (string)$l['unidad']) ?>
                        <span><?= e($l['unidad']) ?></span>
                    </div>
                    <?php if ($editable): ?>
                    <div class="rq-acciones">
                        <button type="button" class="rq-btn-min" title="Modificar"
                                onclick="rqEditar(<?= (int)$l['id'] ?>, <?= (int)$l['rubro_id'] ?>,
                                         <?= (int)($l['item_id'] ?? 0) ?>,
                                         '<?= e(addslashes((string)($l['nombre_libre'] ?? ''))) ?>',
                                         '<?= e($l['unidad']) ?>',
                                         '<?= e(str_replace('.', ',', (string)(float)$l['cantidad'])) ?>',
                                         '<?= e(addslashes((string)($l['nota'] ?? ''))) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="rq-btn-min borrar" title="Quitar"
                                onclick="rqBorrar(<?= (int)$l['id'] ?>,
                                         '<?= e(addslashes((string)($l['material'] ?? ''))) ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($editable && $totalRen > 0): ?>
    <div style="text-align:center;margin:18px 0 8px;">
        <button type="button" class="btn btn-primary"
                style="background:#2E7D32;border-color:#2E7D32;padding:11px 24px;"
                onclick="rqEmitir(this)">
            <i class="bi bi-send-check-fill"></i> Emitir requisición
        </button>
        <div style="font-size:11.5px;color:#8a93a8;margin-top:7px;">
            Al emitirla queda cerrada como constancia de lo solicitado.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($editable): ?>
    <div style="text-align:center;margin-top:20px;">
        <button type="button" class="btn btn-outline btn-sm"
                style="border-color:#A61C1C33;color:#A61C1C;"
                onclick="rqEliminar(this)">
            <i class="bi bi-trash3"></i> Eliminar esta requisición
        </button>
    </div>
    <?php endif; ?>
</div>

<script>
const RQ_URL   = '<?= APP_URL_BASE ?>seguimiento/';
const RQ_ID    = <?= (int)$reqId ?>;
const RQ_EDITA = <?= $editable ? 'true' : 'false' ?>;

// Catálogo por rubro, para llenar el segundo desplegable sin ir al servidor.
const RQ_ITEMS = <?= json_encode(array_map(
    fn($lista) => array_map(fn($i) => [
        'id'     => (int)$i['id'],
        'nombre' => $i['nombre'],
        'unidad' => $i['unidad'],
    ], $lista),
    $itemsPorRub
), JSON_UNESCAPED_UNICODE) ?>;

function rqAviso(txt, color) {
    const a = document.getElementById('rq-aviso');
    if (!a) return;
    a.innerHTML = txt;
    a.style.color = color || '#5b6478';
}

async function rqPost(datos) {
    datos.requisicion_id = RQ_ID;
    const res = await fetch(RQ_URL + 'guardar_requisicion.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos),
        credentials: 'same-origin'
    });
    const txt = await res.text();
    try { return JSON.parse(txt); }
    catch (e) {
        console.error('Respuesta del servidor:', txt);
        return { ok: false, mensaje: 'El servidor devolvió una respuesta inesperada.' };
    }
}

function rqCambiarRubro() {
    const selR = document.getElementById('rq-rubro');
    const selI = document.getElementById('rq-item');

    if (selR.value === '__nuevo__') {
        const nom = prompt('Nombre del rubro nuevo:\n(por ejemplo: Aire acondicionado)');
        selR.value = '';
        if (nom && nom.trim()) rqNuevoRubro(nom.trim());
        return;
    }

    selI.innerHTML = '';
    if (!selR.value) {
        selI.disabled = true;
        selI.innerHTML = '<option value="">— Elija primero el rubro —</option>';
        rqMostrarLibre(false);
        return;
    }
    selI.disabled = false;
    const lista = RQ_ITEMS[selR.value] || [];
    let html = '<option value="">— Elija el material —</option>';
    lista.forEach(it => {
        html += '<option value="' + it.id + '" data-unidad="' + it.unidad + '">'
              + it.nombre + '</option>';
    });
    html += '<option value="__otro__">+ Escribir otro material…</option>';
    selI.innerHTML = html;
    rqMostrarLibre(false);
}

function rqMostrarLibre(ver) {
    const caja = document.getElementById('rq-libre-caja');
    if (caja) caja.style.display = ver ? 'block' : 'none';
}

function rqCambiarItem() {
    const selI = document.getElementById('rq-item');
    if (selI.value === '__otro__') {
        rqMostrarLibre(true);
        const l = document.getElementById('rq-libre');
        if (l) l.focus();
        return;
    }
    rqMostrarLibre(false);
    const op = selI.options[selI.selectedIndex];
    const uni = op ? op.getAttribute('data-unidad') : null;
    if (uni) {
        const selU = document.getElementById('rq-unidad');
        for (let i = 0; i < selU.options.length; i++) {
            if (selU.options[i].value === uni) { selU.selectedIndex = i; break; }
        }
    }
}

async function rqNuevoRubro(nombre) {
    try {
        const d = await rqPost({ accion: 'nuevo_rubro', nombre: nombre });
        if (!d.ok) { rqAviso(d.mensaje, '#A61C1C'); return; }
        const selR = document.getElementById('rq-rubro');
        const op = document.createElement('option');
        op.value = d.rubro_id;
        op.textContent = d.nombre;
        selR.insertBefore(op, selR.options[selR.options.length - 1]);
        selR.value = d.rubro_id;
        RQ_ITEMS[d.rubro_id] = [];
        rqCambiarRubro();
        rqAviso('<i class="bi bi-check-circle-fill"></i> ' + d.mensaje, '#2E7D32');
    } catch (e) {
        rqAviso('No se pudo agregar el rubro.', '#A61C1C');
    }
}

async function rqGuardar(btn) {
    if (!RQ_EDITA) return;

    const rubro  = document.getElementById('rq-rubro').value;
    const selI   = document.getElementById('rq-item');
    const item   = selI.value;
    const libre  = (document.getElementById('rq-libre').value || '').trim();
    const cant   = (document.getElementById('rq-cantidad').value || '').trim();
    const unidad = document.getElementById('rq-unidad').value;
    const nota   = (document.getElementById('rq-nota').value || '').trim();
    const renId  = document.getElementById('rq-renglon-id').value;

    if (!rubro) { rqAviso('Elija el rubro.', '#A61C1C');
                  document.getElementById('rq-rubro').focus(); return; }
    if (!item)  { rqAviso('Elija el material.', '#A61C1C'); selI.focus(); return; }
    if (item === '__otro__' && !libre) {
        rqAviso('Escriba el nombre del material.', '#A61C1C');
        document.getElementById('rq-libre').focus(); return;
    }
    if (!cant || parseFloat(cant.replace(',', '.')) <= 0) {
        rqAviso('Indique una cantidad mayor que cero.', '#A61C1C');
        document.getElementById('rq-cantidad').focus(); return;
    }

    btn.disabled = true;
    const prev = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Guardando…';

    try {
        let itemId = 0;
        if (item === '__otro__') {
            if (document.getElementById('rq-guardar-catalogo').checked) {
                const dn = await rqPost({
                    accion: 'nuevo_item', rubro_id: rubro,
                    nombre: libre, unidad: unidad
                });
                if (dn.ok) {
                    itemId = dn.item_id;
                    if (!RQ_ITEMS[rubro]) RQ_ITEMS[rubro] = [];
                    RQ_ITEMS[rubro].push({ id: dn.item_id, nombre: dn.nombre, unidad: dn.unidad });
                }
            }
        } else {
            itemId = parseInt(item, 10) || 0;
        }

        const d = await rqPost({
            accion: 'guardar',
            rubro_id: rubro,
            item_id: itemId,
            nombre_libre: itemId ? '' : libre,
            unidad: unidad,
            cantidad: cant,
            nota: nota,
            renglon_id: renId || 0
        });

        if (!d.ok) { rqAviso(d.mensaje, '#A61C1C'); return; }
        rqAviso('<i class="bi bi-check-circle-fill"></i> ' + d.mensaje, '#2E7D32');
        setTimeout(() => location.reload(), 450);

    } catch (e) {
        rqAviso('No se pudo guardar. Revise su conexión.', '#A61C1C');
    } finally {
        btn.disabled = false;
        btn.innerHTML = prev;
    }
}

function rqEditar(id, rubroId, itemId, libre, unidad, cantidad, nota) {
    document.getElementById('rq-renglon-id').value = id;
    document.getElementById('rq-rubro').value = rubroId;
    rqCambiarRubro();

    const selI = document.getElementById('rq-item');
    if (itemId) {
        selI.value = itemId;
        rqMostrarLibre(false);
    } else {
        selI.value = '__otro__';
        rqMostrarLibre(true);
        document.getElementById('rq-libre').value = libre;
        document.getElementById('rq-guardar-catalogo').checked = false;
    }
    document.getElementById('rq-unidad').value = unidad;
    document.getElementById('rq-cantidad').value = cantidad;
    document.getElementById('rq-nota').value = nota;

    document.getElementById('rq-btn-guardar').innerHTML =
        '<i class="bi bi-check2-circle"></i> Guardar cambios';
    document.getElementById('rq-btn-cancelar').style.display = '';
    rqAviso('Modificando un material de la requisición.', '#8a6d1a');
    document.querySelector('.rq-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function rqCancelarEdicion() {
    document.getElementById('rq-renglon-id').value = '';
    document.getElementById('rq-rubro').value = '';
    rqCambiarRubro();
    document.getElementById('rq-cantidad').value = '';
    document.getElementById('rq-nota').value = '';
    document.getElementById('rq-libre').value = '';
    document.getElementById('rq-btn-guardar').innerHTML =
        '<i class="bi bi-check2-circle"></i> Agregar';
    document.getElementById('rq-btn-cancelar').style.display = 'none';
    rqAviso('');
}

async function rqBorrar(id, nombre) {
    if (!RQ_EDITA) return;
    if (!confirm('¿Quitar "' + nombre + '" de la requisición?')) return;
    try {
        const d = await rqPost({ accion: 'borrar', renglon_id: id });
        if (!d.ok) { alert(d.mensaje); return; }
        location.reload();
    } catch (e) { alert('No se pudo quitar el material.'); }
}

async function rqCopiar(btn) {
    const sel = document.getElementById('rq-copiar');
    if (!sel || !sel.value) { rqAviso('Elija la requisición de origen.', '#A61C1C'); return; }
    const txt = sel.options[sel.selectedIndex].textContent.trim();
    if (!confirm('¿Copiar los materiales de "' + txt + '" a esta requisición?\n\n'
               + 'Lo que ya esté aquí se conserva; si un material se repite, '
               + 'se actualiza la cantidad.')) return;
    btn.disabled = true;
    try {
        const d = await rqPost({ accion: 'copiar', origen_id: sel.value });
        if (!d.ok) { rqAviso(d.mensaje, '#A61C1C'); return; }
        rqAviso('<i class="bi bi-check-circle-fill"></i> ' + d.mensaje, '#2E7D32');
        setTimeout(() => location.reload(), 600);
    } catch (e) {
        rqAviso('No se pudo copiar.', '#A61C1C');
    } finally { btn.disabled = false; }
}

/** Emitir: es el paso formal, así que se confirma. */
async function rqEmitir(btn) {
    if (!confirm('¿Emitir esta requisición?\n\n'
               + 'Quedará cerrada como constancia de lo solicitado.\n'
               + 'Si después hace falta corregirla, se puede reabrir.')) return;
    btn.disabled = true;
    try {
        const d = await rqPost({ accion: 'emitir' });
        if (!d.ok) { alert(d.mensaje); btn.disabled = false; return; }
        location.reload();
    } catch (e) {
        alert('No se pudo emitir.');
        btn.disabled = false;
    }
}

async function rqReabrir(btn) {
    if (!confirm('¿Reabrir esta requisición para corregirla?\n\n'
               + 'Quedará registrado que se reabrió.')) return;
    btn.disabled = true;
    try {
        const d = await rqPost({ accion: 'reabrir' });
        if (!d.ok) { alert(d.mensaje); btn.disabled = false; return; }
        location.reload();
    } catch (e) {
        alert('No se pudo reabrir.');
        btn.disabled = false;
    }
}

async function rqEliminar(btn) {
    if (!confirm('¿Eliminar esta requisición completa?\n\n'
               + 'Se perderán todos sus materiales. Esta acción no se puede deshacer.')) return;
    btn.disabled = true;
    try {
        const d = await rqPost({ accion: 'eliminar' });
        if (!d.ok) { alert(d.mensaje); btn.disabled = false; return; }
        location.href = '<?= APP_URL_BASE ?>seguimiento/requisiciones.php';
    } catch (e) {
        alert('No se pudo eliminar.');
        btn.disabled = false;
    }
}

function rqPlegar(cab) {
    const cuerpo = cab.parentElement.querySelector('.rq-rubro-cuerpo');
    if (!cuerpo) return;
    cuerpo.style.display = (cuerpo.style.display === 'none') ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
