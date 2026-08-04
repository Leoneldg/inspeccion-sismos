<?php
/**
 * EN RECONSTRUCCIÓN — buscador rápido.
 *
 * Las edificaciones con levantamiento cerrado y avance menor a 100%.
 * Ordenadas por fecha de entrega: primero las que vencen antes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

// Solo un administrador puede borrar levantamientos.
$rolAct = mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8');
$puedeBorrar = usuarioEsMaster()
            || str_contains($rolAct, 'administrador')
            || str_contains($rolAct, 'superadmin');

// Ingenieros activos, para poder asignarlos desde la lista.
recAsegurarIngeniero();
$ingenieros = recIngenierosActivos();

// Filtro por parroquia y texto.
$parrF = trim($_GET['parroquia'] ?? '');
$textoF = trim($_GET['q'] ?? '');

// Para el sistematizador de campo: por defecto ve SOLO sus levantamientos.
// Pero si busca (por parroquia o texto), puede ver los de otros, para
// hacerle seguimiento a otro edificio cuando haga falta.
$esSistemCampo = !usuarioEsMaster()
              && !str_contains($rolAct, 'administrador')
              && function_exists('esSistematizador') && esSistematizador();
$estaBuscando = ($parrF !== '' || $textoF !== '');

$filtros = [];
if ($parrF !== '')  $filtros['parroquia'] = $parrF;
if ($textoF !== '') $filtros['texto']     = $textoF;
if ($esSistemCampo && !$estaBuscando) {
    // Sin búsqueda activa: solo los suyos.
    $filtros['creado_por'] = (int)($_SESSION['user_id'] ?? 0);
}
$lista = segEnReconstruccion($filtros);

// Parroquias que tienen levantamientos, para el selector.
$parroquiasDisp = [];
try {
    $cond = []; $par = [];
    aplicarScopeEstado($cond, $par, 'i');
    aplicarScopeParroquia($cond, $par, 'i');
    $cond[] = "i.parroquia IS NOT NULL AND i.parroquia <> ''";
    $stPD = db()->prepare('SELECT DISTINCT i.parroquia
                             FROM inspecciones i
                             JOIN rec_edificio re ON re.inspeccion_id = i.id
                            WHERE ' . implode(' AND ', $cond) . '
                            ORDER BY i.parroquia');
    $stPD->execute($par);
    $parroquiasDisp = $stPD->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}
$cat   = catalogoDecisionFinal();

// Números de piso disponibles, para el filtro de materiales por piso.
$pisosDisponibles = recNumerosDePisoDisponibles($parrF !== '' ? ['parroquia' => $parrF] : []);

// Contar por estado.
$cuenta = ['proceso' => 0, 'incompleto' => 0, 'completo' => 0];
foreach ($lista as $e) {
    $est = $e['lev_estado'] ?? 'proceso';
    if (isset($cuenta[$est])) $cuenta[$est]++;
}

// Agrupar por día: lo más reciente primero, que es lo que se revisa.
$porDia = [];
foreach ($lista as $e) {
    $f = !empty($e['creado_en']) ? substr($e['creado_en'], 0, 10) : 'sin-fecha';
    $porDia[$f][] = $e;
}
krsort($porDia);

/** Escribe la fecha de forma legible: Hoy, Ayer o la fecha. */
function diaLegible(string $f): string
{
    if ($f === 'sin-fecha') return 'Sin fecha';
    $hoy = date('Y-m-d');
    $ayer = date('Y-m-d', strtotime('-1 day'));
    if ($f === $hoy)  return 'Hoy';
    if ($f === $ayer) return 'Ayer';

    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($f);
    return (int)date('d', $t) . ' de ' . $meses[(int)date('n', $t)]
         . ' de ' . date('Y', $t);
}

$pageTitle    = 'En reconstrucción';
$pageSubtitle = count($lista) . ' edificaciones en obra';
$activeModule = 'reconstruccion';
include __DIR__ . '/../includes/header.php';
?>
<style>
.rc-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.rc-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.rc-kpis { display:flex; gap:9px; flex-wrap:wrap; }
.rc-k { flex:1; min-width:110px; text-align:center; padding:13px 8px; border-radius:10px;
        border:2px solid; cursor:pointer; background:#fff; transition:all .15s; }
.rc-k:hover { transform:translateY(-1px); }
.rc-k.activo { box-shadow:0 0 0 3px #22366F22; }
.rc-k .n { font-size:26px; font-weight:800; line-height:1; }
.rc-k .l { font-size:10.5px; text-transform:uppercase; color:#55617f; margin-top:4px; }
.rc-fila { display:flex; align-items:center; gap:12px; padding:13px 8px;
           border-bottom:1px solid #f0f2f7; }
.rc-fila:last-child { border-bottom:0; }
.rc-fila:hover { background:#fafbfe; }
.clas-letra { display:inline-flex; align-items:center; justify-content:center;
              width:26px; height:26px; border:2px solid; border-radius:6px;
              font-weight:800; font-size:13px; flex-shrink:0; }
.rc-barra { flex:0 0 100px; background:#eef0f6; border-radius:20px; height:14px; overflow:hidden; }
@media (max-width: 700px) {
    .rc-fila { flex-wrap:wrap; }
    .rc-fila > div:nth-child(2) { flex:1 1 100%; }
    .rc-barra { flex:1 1 auto; }
}
</style>

<?php if (!$lista): ?>
<div class="rc-card" style="text-align:center;padding:40px 20px;">
    <div style="font-size:44px;color:#c4c9d6;"><i class="bi bi-hammer"></i></div>
    <h3 style="color:#22366F;margin:10px 0 5px;">No hay levantamientos iniciados</h3>
    <p class="text-muted" style="margin:0;">
        Aquí aparecerán las edificaciones apenas alguien empiece su levantamiento técnico.
    </p>
</div>

<?php else: ?>

<!-- Resumen -->
<div class="rc-card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="rc-k activo" data-f="todos" onclick="filtrarEstado('todos', this)"
                style="border-color:#22366F;">
            <div class="n" style="color:#22366F;"><?= count($lista) ?></div>
            <div class="l">Todas</div>
        </button>
        <button class="rc-k" data-f="proceso" onclick="filtrarEstado('proceso', this)"
                style="border-color:#C9A22755;">
            <div class="n" style="color:#a8871f;"><?= $cuenta['proceso'] ?></div>
            <div class="l">En proceso</div>
        </button>
        <button class="rc-k" data-f="incompleto" onclick="filtrarEstado('incompleto', this)"
                style="border-color:#A61C1C55;">
            <div class="n" style="color:#A61C1C;"><?= $cuenta['incompleto'] ?></div>
            <div class="l">Levantamiento incompleto</div>
        </button>
        <button class="rc-k" data-f="completo" onclick="filtrarEstado('completo', this)"
                style="border-color:#2E7D3255;">
            <div class="n" style="color:#2E7D32;"><?= $cuenta['completo'] ?></div>
            <div class="l">Levantamiento completo</div>
        </button>
    </div>

    <?php if ($cuenta['incompleto'] > 0): ?>
    <div style="background:#fdf0f0;border:1px solid #A61C1C33;border-radius:9px;
                padding:10px 13px;margin-top:12px;font-size:12.5px;color:#A61C1C;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong><?= $cuenta['incompleto'] ?></strong> levantamiento(s) se cerraron
        con datos faltantes: ambientes sin foto, sin metros o sin tipo de trabajo.
    </div>
    <?php endif; ?>
</div>

<?php if ($esSistemCampo): ?>
<div style="background:<?= $estaBuscando ? '#FDF7E7' : '#E9EEF9' ?>;border:1px solid <?= $estaBuscando ? '#C9A22755' : '#22366F33' ?>;
            border-radius:9px;padding:10px 13px;margin-bottom:12px;font-size:12.5px;
            color:<?= $estaBuscando ? '#A66A00' : '#22366F' ?>;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-<?= $estaBuscando ? 'search' : 'person-check-fill' ?>"></i>
    <?php if ($estaBuscando): ?>
        Mostrando resultados de la búsqueda<?= $parrF ? ' en <strong>'.e($parrF).'</strong>' : '' ?>.
        <a href="?" style="color:#22366F;font-weight:600;margin-left:auto;">Ver solo los míos</a>
    <?php else: ?>
        Estás viendo <strong>tus levantamientos</strong>. Para hacerle seguimiento a otro, usa el buscador o filtra por parroquia.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Listado por día -->
<div class="rc-card">
    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div style="font-weight:700;color:#22366F;">
            <i class="bi bi-calendar3"></i> Por día
            <span id="rc-cont" style="background:#eef2fb;color:#22366F;border-radius:12px;
                  padding:2px 9px;font-size:12px;font-weight:700;"><?= count($lista) ?></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if (count($parroquiasDisp) > 1): ?>
            <select class="form-control" style="width:180px;"
                    onchange="location.href='?parroquia=' + encodeURIComponent(this.value)">
                <option value="">Todas las parroquias</option>
                <?php foreach ($parroquiasDisp as $pd): ?>
                <option value="<?= e($pd) ?>" <?= $parrF === $pd ? 'selected' : '' ?>>
                    <?= e($pd) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" id="rc-buscar" class="form-control" style="width:200px;"
                   value="<?= e($textoF) ?>"
                   placeholder="Buscar edificación…"
                   oninput="filtrarLista()"
                   onkeydown="if(event.key==='Enter'){location.href='?q='+encodeURIComponent(this.value)<?= $parrF ? "+'&parroquia='+encodeURIComponent(".json_encode($parrF).")" : '' ?>;}">
            <a href="<?= APP_URL_BASE ?>seguimiento/pdf_ejecutivo.php"
               target="_blank" class="btn btn-primary btn-sm"
               title="Resumen global: materiales, edificaciones y parroquias">
                <i class="bi bi-file-earmark-bar-graph-fill"></i> Resumen global
            </a>
            <a href="<?= APP_URL_BASE ?>seguimiento/pdf_reconstruccion.php<?= $parrF ? '?parroquia=' . urlencode($parrF) : '' ?>"
               target="_blank" class="btn btn-outline btn-sm"
               style="border-color:#A61C1C55;color:#A61C1C;"
               title="Listado detallado de los levantamientos">
                <i class="bi bi-file-earmark-pdf-fill"></i> Listado
            </a>
            <button type="button" class="btn btn-outline btn-sm"
                    onclick="abrirPlanilla()"
                    title="Planilla en blanco para llenar a mano en la calle">
                <i class="bi bi-printer"></i> Planilla para imprimir
            </button>
            <a href="<?= APP_URL_BASE ?>seguimiento/materiales_multi.php<?= $parrF ? '?parroquia=' . urlencode($parrF) : '' ?>"
               class="btn btn-outline btn-sm"
               style="border-color:#2E7D3255;color:#2E7D32;"
               title="Elegir varios edificios y sacar el material por piso">
                <i class="bi bi-clipboard-check-fill"></i> Materiales de varios edificios
            </a>
            <?php
            $rolAct = mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8');
            if (usuarioEsMaster() || str_contains($rolAct, 'administrador')): ?>
            <a href="<?= APP_URL_BASE ?>seguimiento/limpiar_pruebas.php?estado=proceso"
               class="btn btn-outline btn-sm"
               style="border-color:#A61C1C55;color:#A61C1C;"
               title="Borrar levantamientos de prueba">
                <i class="bi bi-trash3"></i> Limpiar
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Materiales por piso (global): suma de todos los edificios -->
    <div id="mat-piso-panel" style="background:#f7f9fd;border:1px solid #dde3f0;
                border-radius:10px;padding:14px 16px;margin:12px 0 4px;">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="field" style="margin:0;">
                <label class="text-sm" style="font-weight:700;color:#22366F;">
                    <i class="bi bi-boxes"></i> Materiales por piso (todos los edificios<?= $parrF ? ' de ' . e($parrF) : '' ?>)
                </label>
                <select id="mat-piso-sel" class="form-control" style="width:220px;"
                        onchange="cargarMaterialesPiso()">
                    <option value="">— Elija un piso —</option>
                    <?php foreach ($pisosDisponibles as $np): ?>
                    <option value="<?= (int)$np ?>">
                        <?= (int)$np === 0 ? 'Planta baja' : 'Piso ' . (int)$np ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="font-size:12px;color:#5b6478;flex:1;min-width:200px;">
                Suma cuánto material se necesita para ese mismo piso en todas
                las edificaciones<?= $parrF ? '' : ' de tu alcance' ?>. Útil para
                comprar por lote (p. ej. todas las plantas bajas juntas).
            </div>
        </div>
        <div id="mat-piso-resultado" style="margin-top:12px;display:none;"></div>
    </div>

    <div id="rc-lista">
    <?php foreach ($porDia as $dia => $items): ?>
        <div class="rc-dia" data-dia="<?= e($dia) ?>">
            <div style="background:#22366F;color:#fff;padding:8px 14px;border-radius:8px;
                        font-weight:700;font-size:13px;margin:14px 0 7px;">
                <i class="bi bi-calendar-event"></i> <?= e(diaLegible($dia)) ?>
                <span style="float:right;font-weight:600;font-size:11.5px;opacity:.9;">
                    <?= count($items) ?>
                </span>
            </div>

            <?php foreach ($items as $e):
                $est = $e['lev_estado'] ?? 'proceso';
                $pctLev = (int)($e['lev_pct'] ?? 0);
                $sim = recSimboloDecision($e['decision_final'] ?? null);

                $meta = [
                    'proceso'    => ['#C9A227', 'hourglass-split',  'En proceso'],
                    'incompleto' => ['#A61C1C', 'exclamation-triangle-fill',
                                     'Levantamiento incompleto'],
                    'completo'   => ['#2E7D32', 'check-circle-fill',
                                     'Levantamiento completo'],
                ][$est];
            ?>
            <div class="rc-fila" data-estado="<?= $est ?>"
                 data-txt="<?= e(mb_strtolower(($e['nombre_edificio'] ?? '') . ' ' .
                           ($e['codigo'] ?? '') . ' ' . ($e['parroquia'] ?? '') . ' ' .
                           ($e['cerrado_por_nombre'] ?? '') . ' ' .
                           ($e['creado_por_nombre'] ?? ''), 'UTF-8')) ?>">

                <span class="clas-letra" style="color:<?= $sim['color'] ?>;
                      border-color:<?= $sim['color'] ?>;" title="<?= e($sim['texto']) ?>">
                    <?= $sim['letra'] ?>
                </span>

                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#2a3140;font-size:14px;">
                        <?= e($e['nombre_edificio'] ?: 'Sin nombre') ?>
                    </div>
                    <div style="font-size:11.5px;color:#5b6478;">
                        <?= e($e['codigo']) ?> · <?= e($e['parroquia'] ?: '—') ?>
                    </div>
                    <div style="font-size:11px;color:#767c94;">
                        <?= (int)$e['n_pisos'] ?> piso<?= (int)$e['n_pisos'] === 1 ? '' : 's' ?>
                        · <?= (int)$e['aptos_hechos'] ?> de <?= (int)$e['n_aptos'] ?> apartamentos
                    </div>
                    <?php
                    // Quién lo hizo: se muestra quien lo cerró, y si no,
                    // quien lo empezó.
                    $quien = $e['cerrado_por_nombre'] ?: ($e['creado_por_nombre'] ?? '');
                    ?>
                    <?php if ($quien): ?>
                    <div style="font-size:11.5px;color:#2d4488;font-weight:600;margin-top:2px;">
                        <i class="bi bi-person-fill"></i> <?= e($quien) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="min-width:170px;">
                    <div style="font-size:12px;font-weight:700;color:<?= $meta[0] ?>;
                                margin-bottom:3px;">
                        <i class="bi bi-<?= $meta[1] ?>"></i> <?= $meta[2] ?>
                    </div>

                    <?php if ($est === 'proceso'): ?>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="flex:1;background:#eef0f6;border-radius:20px;height:9px;
                              overflow:hidden;min-width:60px;">
                            <span style="display:block;width:<?= $pctLev ?>%;height:100%;
                                  background:<?= $meta[0] ?>;"></span>
                        </span>
                        <span style="font-size:11px;color:#767c94;"><?= $pctLev ?>%</span>
                    </div>

                    <?php elseif ($est === 'incompleto'): ?>
                    <div style="font-size:11px;color:#A61C1C;">
                        <?= (int)$e['lev_fallas'] ?> dato(s) sin completar
                    </div>
                    <?php if (!empty($e['lev_detalle'])): ?>
                    <details style="margin-top:3px;">
                        <summary style="font-size:11px;color:#767c94;cursor:pointer;">
                            Ver qué falta
                        </summary>
                        <div style="font-size:10.5px;color:#5b6478;margin-top:4px;
                                    max-width:230px;">
                            <?php foreach ($e['lev_detalle'] as $d): ?>
                            <div style="padding:2px 0;">
                                · <?= e($d['que']) ?><br>
                                <span style="color:#97a0b8;"><?= e($d['donde']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php
                // Ingeniero asignado, para mostrarlo o pedirlo.
                $ingAsig = null;
                if (!empty($e['edificio_id'])) {
                    $ingAsig = recIngenieroDe((int)$e['edificio_id']);
                }
                ?>
                <div style="min-width:150px;">
                    <?php if ($ingAsig): ?>
                    <div style="font-size:11px;color:#2d4488;font-weight:600;">
                        <i class="bi bi-person-vcard"></i> <?= e($ingAsig['nombre']) ?>
                    </div>
                    <?php elseif ($ingenieros && !empty($e['edificio_id'])): ?>
                    <select class="form-control" style="font-size:11px;padding:4px 6px;"
                            onchange="asignarIngeniero(<?= (int)$e['edificio_id'] ?>, this)"
                            title="Asignar ingeniero responsable">
                        <option value="">— Sin ingeniero —</option>
                        <?php foreach ($ingenieros as $ig): ?>
                        <option value="<?= (int)$ig['id'] ?>"><?= e($ig['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:6px;">
                    <?php // El PDF sale siempre: aunque esté en proceso, sirve
                          // para ver lo que lleva registrado hasta ahora. ?>
                    <a href="<?= APP_URL_BASE ?>seguimiento/pdf_levantamiento.php?inspeccion=<?= (int)$e['id'] ?>"
                       target="_blank" class="btn btn-outline btn-sm"
                       style="border-color:#A61C1C55;color:#A61C1C;"
                       title="Descargar el levantamiento en PDF">
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                    </a>
                    <a href="<?= APP_URL_BASE ?>seguimiento/requisicion.php?inspeccion=<?= (int)$e['id'] ?>"
                       class="btn btn-outline btn-sm"
                       style="border-color:#22366F55;color:#22366F;"
                       title="Requisición de material: electricidad, plomería, cal...">
                        <i class="bi bi-file-earmark-text"></i>
                    </a>
                    <a href="<?= APP_URL_BASE ?>seguimiento/campo.php?inspeccion=<?= (int)$e['id'] ?>"
                       class="btn btn-primary btn-sm" title="Modo campo: reportar avance con fotos">
                        <i class="bi bi-clipboard-check"></i>
                    </a>
                    <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= (int)$e['id'] ?>"
                       class="btn btn-outline btn-sm" title="Abrir la ficha">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                    <?php if ($puedeBorrar): ?>
                    <button type="button" class="btn btn-outline btn-sm"
                            style="border-color:#A61C1C33;color:#A61C1C;"
                            title="Borrar este levantamiento"
                            onclick="borrarLev(<?= (int)$e['id'] ?>,
                                     '<?= e(addslashes($e['nombre_edificio'] ?: 'Sin nombre')) ?>',
                                     <?= (int)$e['n_aptos'] ?>, this)">
                        <i class="bi bi-trash3"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <p id="rc-vacio" class="text-muted" style="display:none;margin:12px 0 0;">
        Ninguna coincide con el filtro.
    </p>
</div>

<script>
// Abre la planilla en blanco para imprimir, preguntando cuántas hojas.
function abrirPlanilla() {
    const aptos = prompt('¿Cuántas hojas de apartamento/local quiere imprimir?\n'
        + '(una por cada apartamento o local a levantar)', '10');
    if (aptos === null) return;
    const n = Math.max(1, Math.min(40, parseInt(aptos) || 6));
    const amb = prompt('¿Cuántas filas de ambientes por apartamento?\n'
        + '(habitaciones, baños, sala, cocina…)', '6');
    if (amb === null) return;
    const m = Math.max(1, Math.min(12, parseInt(amb) || 5));
    const url = '<?= APP_URL_BASE ?>seguimiento/planilla_levantamiento.php?aptos='
        + n + '&ambientes=' + m;
    window.open(url, '_blank');
}

// ---- Materiales por piso (global) ----
async function cargarMaterialesPiso() {
    const sel = document.getElementById('mat-piso-sel');
    const cont = document.getElementById('mat-piso-resultado');
    if (!sel || !cont) return;
    const piso = sel.value;
    if (piso === '') { cont.style.display = 'none'; cont.innerHTML = ''; return; }

    cont.style.display = 'block';
    cont.innerHTML = '<div style="color:#5b6478;font-size:13px;">'
        + '<i class="bi bi-arrow-repeat"></i> Calculando…</div>';

    const parr = <?= json_encode($parrF) ?>;
    let url = '<?= APP_URL_BASE ?>seguimiento/materiales_por_piso.php?piso='
        + encodeURIComponent(piso);
    if (parr) url += '&parroquia=' + encodeURIComponent(parr);

    try {
        const res = await fetch(url, { credentials: 'same-origin' });
        const d = await res.json();
        if (!d.ok) {
            cont.innerHTML = '<div style="color:#A61C1C;font-size:13px;">'
                + (d.mensaje || 'No se pudo calcular.') + '</div>';
            return;
        }
        pintarMaterialesPiso(cont, d);
    } catch (e) {
        cont.innerHTML = '<div style="color:#A61C1C;font-size:13px;">'
            + 'Error de conexión. Intente de nuevo.</div>';
    }
}

function pintarMaterialesPiso(cont, d) {
    const total = d.total || {};
    const claves = Object.keys(total);
    if (!claves.length) {
        cont.innerHTML = '<div style="color:#5b6478;font-size:13px;padding:6px 0;">'
            + 'No hay materiales registrados para ' + esc((d.etiqueta||'').toLowerCase())
            + ' todavía.</div>';
        return;
    }

    // --- Total general (para comprar por lote) ---
    const parr = <?= json_encode($parrF) ?>;
    let pdfUrl = '<?= APP_URL_BASE ?>seguimiento/pdf_materiales_por_piso.php?piso='
        + encodeURIComponent(d.numero_piso);
    if (parr) pdfUrl += '&parroquia=' + encodeURIComponent(parr);

    let html = '<div style="display:flex;justify-content:space-between;align-items:center;'
        + 'margin-bottom:8px;gap:10px;flex-wrap:wrap;">'
        + '<div style="font-weight:700;color:#22366F;">'
        + '<i class="bi bi-clipboard-check"></i> ' + esc(d.etiqueta)
        + ' · TOTAL de todos los edificios</div>'
        + '<a href="' + pdfUrl + '" target="_blank" class="btn btn-outline btn-sm" '
        + 'style="border-color:#A61C1C55;color:#A61C1C;white-space:nowrap;">'
        + '<i class="bi bi-file-earmark-pdf-fill"></i> Exportar PDF</a>'
        + '</div>';
    html += tablaMateriales(total, '#22366F');

    // --- Desglose por edificio ---
    const porEd = d.por_edificio || [];
    if (porEd.length) {
        html += '<div style="font-weight:700;color:#22366F;margin:16px 0 8px;">'
            + '<i class="bi bi-buildings"></i> Qué necesita cada edificio</div>';
        porEd.forEach(ed => {
            html += '<div style="margin-bottom:12px;border:1px solid #dde3f0;'
                + 'border-radius:8px;overflow:hidden;">'
                + '<div style="background:#eef2fb;padding:7px 12px;font-weight:600;'
                + 'color:#22366F;font-size:13px;">'
                + '<i class="bi bi-building"></i> ' + esc(ed.nombre) + '</div>'
                + tablaMateriales(ed.materiales, '#5b6478', true)
                + '</div>';
        });
    }

    cont.innerHTML = html;
}

// Construye una tabla de materiales. Si compacta=true, sin cabecera de color.
function tablaMateriales(mats, colorCabecera, compacta) {
    const claves = Object.keys(mats);
    let html = '<div style="overflow-x:auto;"><table style="width:100%;'
        + 'border-collapse:collapse;font-size:13px;">';
    if (!compacta) {
        html += '<thead><tr style="background:' + colorCabecera + ';color:#fff;">'
            + '<th style="text-align:left;padding:7px 10px;">Material</th>'
            + '<th style="text-align:right;padding:7px 10px;">Cantidad</th></tr></thead>';
    }
    html += '<tbody>';
    claves.forEach((k, i) => {
        const num = (Math.round(mats[k] * 100) / 100).toString().replace('.', ',');
        html += '<tr style="border-bottom:1px solid #eceef4;'
            + (i % 2 ? 'background:#fff;' : 'background:#fafbfe;') + '">'
            + '<td style="padding:6px 10px;">' + esc(k) + '</td>'
            + '<td style="padding:6px 10px;text-align:right;font-weight:600;">' + num + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Borra el levantamiento de una edificación.
 * Pide doble confirmación porque no se puede deshacer: se pierden
 * los apartamentos, los metros y las fotos.
 */
async function borrarLev(id, nombre, nAptos, btn) {
    if (!confirm('¿Borrar el levantamiento de "' + nombre + '"?\n\n'
        + 'Se eliminan ' + nAptos + ' apartamento(s) con sus ambientes, '
        + 'metros cuadrados y FOTOS.\n\n'
        + 'La edificación se conserva y podrá levantarse de nuevo.')) return;

    // Segunda confirmación: hay que escribir para estar seguro.
    const conf = prompt('Esta acción NO se puede deshacer.\n\n'
        + 'Escriba BORRAR para confirmar:');
    if (conf === null) return;
    if (conf.trim().toUpperCase() !== 'BORRAR') {
        alert('No se borró nada.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    try {
        const res = await fetch('<?= APP_URL_BASE ?>seguimiento/borrar_levantamiento.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ inspeccion_id: id }),
            credentials: 'same-origin'
        });
        const d = await res.json();

        if (d.sesion_expirada) { alert(d.mensaje); location.reload(); return; }
        if (!d.ok) {
            alert(d.mensaje || 'No se pudo borrar.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-trash3"></i>';
            return;
        }

        // Quitar la fila y actualizar los conteos.
        const fila = btn.closest('.rc-fila');
        if (fila) fila.remove();

        const det = d.detalle || {};
        alert('Levantamiento eliminado.\n\n'
            + (det.pisos || 0) + ' piso(s) · '
            + (det.apartamentos || 0) + ' apartamento(s) · '
            + (det.fotos || 0) + ' foto(s)');

        filtrarLista();

    } catch (e) {
        alert('Sin conexión. Intente de nuevo.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash3"></i>';
    }
}

/**
 * Asigna el ingeniero responsable a un levantamiento ya cargado.
 * Sirve para completar los que se hicieron antes de que existiera
 * este campo.
 */
async function asignarIngeniero(edificioId, sel) {
    const valor = sel.value;
    const antes = sel.style.borderColor;
    sel.disabled = true;

    try {
        const res = await fetch('<?= APP_URL_BASE ?>seguimiento/guardar_rec_edificio.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'ingeniero',
                edificio_id: edificioId,
                ingeniero_id: valor || null,
            }),
            credentials: 'same-origin'
        });

        const texto = await res.text();
        let d;
        try {
            d = JSON.parse(texto);
        } catch (err) {
            // Respuesta que no es JSON: casi siempre un error de PHP.
            console.error('Respuesta del servidor:', texto);
            alert('El servidor devolvió una respuesta inesperada.\n\n'
                + texto.substring(0, 200));
            sel.disabled = false;
            return;
        }

        if (!d.ok) {
            alert(d.mensaje || 'No se pudo asignar el ingeniero.');
            sel.disabled = false;
            sel.style.borderColor = '#A61C1C';
            return;
        }

        if (valor) {
            // Se reemplaza el selector por el nombre, ya asignado.
            const nom = sel.options[sel.selectedIndex].text;
            sel.parentNode.innerHTML =
                '<div style="font-size:11px;color:#2d4488;font-weight:600;">'
                + '<i class="bi bi-person-vcard"></i> ' + nom + '</div>';
        } else {
            sel.disabled = false;
            sel.style.borderColor = antes;
        }

    } catch (e) {
        console.error('Asignar ingeniero:', e);
        alert('Sin conexión. Intente de nuevo.');
        sel.disabled = false;
    }
}

let _filtroEstado = 'todos';

function filtrarEstado(f, btn) {
    _filtroEstado = f;
    document.querySelectorAll('.rc-k').forEach(b => b.classList.remove('activo'));
    if (btn) btn.classList.add('activo');
    filtrarLista();
}

function filtrarLista() {
    const t = (document.getElementById('rc-buscar').value || '').toLowerCase().trim();
    let n = 0;

    document.querySelectorAll('.rc-fila').forEach(f => {
        const okEstado = _filtroEstado === 'todos' || f.dataset.estado === _filtroEstado;
        const okTxt = !t || (f.dataset.txt || '').includes(t);
        const ver = okEstado && okTxt;
        f.style.display = ver ? '' : 'none';
        if (ver) n++;
    });

    // Ocultar el día si no le queda ninguna visible.
    document.querySelectorAll('.rc-dia').forEach(g => {
        const vis = Array.from(g.querySelectorAll('.rc-fila'))
                         .filter(x => x.style.display !== 'none').length;
        g.style.display = vis ? '' : 'none';
    });

    document.getElementById('rc-cont').textContent = n;
    document.getElementById('rc-vacio').style.display = n ? 'none' : '';

    // Recalcular los conteos de los botones: cambian al borrar.
    const cuenta = { todos: 0, proceso: 0, incompleto: 0, completo: 0 };
    document.querySelectorAll('.rc-fila').forEach(f => {
        cuenta.todos++;
        const e = f.dataset.estado;
        if (cuenta[e] !== undefined) cuenta[e]++;
    });
    document.querySelectorAll('.rc-k').forEach(b => {
        const num = b.querySelector('.n');
        const f = b.dataset.f;
        if (num && cuenta[f] !== undefined) num.textContent = cuenta[f];
    });
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
