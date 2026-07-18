<?php
/**
 * MI PARROQUIA — Panel del Responsable de Parroquia.
 * Muestra de forma directa:
 *   · Avance general de su(s) parroquia(s).
 *   · Las edificaciones EN RECONSTRUCCIÓN con su % arriba.
 *   · Su equipo: responsable + frentes de trabajo.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$misParroquias = parroquiasDelUsuario();
$estadoUsr = estadoDelUsuario() ?: 'Distrito Capital';

// Si no tiene parroquias asignadas, se le manda al mapa general.
if (!$misParroquias) {
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$cat = catalogoDecisionFinal();

// Datos de cada parroquia asignada.
$datos = [];
foreach ($misParroquias as $parr) {
    $panel = recPanelParroquia($estadoUsr, $parr);
    // Resumen de apartamentos y sub-asignaciones (1 consulta cada uno).
    $panel['resumen_aptos'] = recResumenAptosParroquia($estadoUsr, $parr);
    // Frente asignado a cada edificación de la parroquia.
    $panel['frentes_obra'] = [];
    try {
        $stFO = db()->prepare("SELECT a.inspeccion_id, f.numero
                                 FROM asignacion_frente_obra a
                                 JOIN frente f ON f.id = a.frente_id
                                 JOIN inspecciones i ON i.id = a.inspeccion_id
                                WHERE i.parroquia = :p");
        $stFO->execute(['p' => $parr]);
        foreach ($stFO->fetchAll() as $r) {
            $panel['frentes_obra'][(int)$r['inspeccion_id']] = (int)$r['numero'];
        }
    } catch (Throwable $e) {}
    $panel['progreso_frentes'] = progresoFrentesParroquia($estadoUsr, $parr);
    $datos[$parr] = $panel;
}

$pageTitle    = count($misParroquias) === 1 ? 'Parroquia ' . $misParroquias[0] : 'Mis parroquias';
$pageSubtitle = 'Seguimiento de la reconstrucción';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.mp-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07); padding:18px 20px; margin-bottom:16px; }
.mp-tit { font-weight:700; color:#22366F; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.mp-kpis { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.mp-kpi { flex:1; min-width:120px; text-align:center; padding:14px 10px; border-radius:10px; }
.mp-kpi .n { font-size:30px; font-weight:800; line-height:1; }
.mp-kpi .l { font-size:11px; text-transform:uppercase; letter-spacing:.4px; margin-top:4px; color:#55617f; }
.mp-edif { display:flex; align-items:center; gap:12px; padding:12px 10px; border-bottom:1px solid #f0f2f7; }
.mp-edif:last-child { border-bottom:0; }
.mp-edif:hover { background:#f7f9fd; }
.mp-barra { flex:0 0 130px; background:#eef0f6; border-radius:20px; height:16px; overflow:hidden; }
.mp-pct { font-weight:800; min-width:48px; text-align:right; font-size:15px; }
.mp-frente { display:flex; gap:9px; align-items:flex-start; padding:8px 0; border-bottom:1px solid #f4f6fa; }
.mp-frente:last-child { border-bottom:0; }
.mp-tramos { display:flex; gap:8px; flex-wrap:wrap; }
.mp-tramo {
    flex:1; min-width:96px; background:#fff; border:2px solid #e5e8f0; border-radius:10px;
    padding:12px 8px; cursor:pointer; text-align:center; transition:all .15s;
}
.mp-tramo:hover { background:#f7f9fd; transform:translateY(-1px); }
.mp-tramo.activo { background:#eef2fb; box-shadow:0 0 0 3px #22366F22; }
.mp-tramo .n { font-size:26px; font-weight:800; line-height:1; }
.mp-tramo .l { font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#55617f; margin-top:3px; }
.mp-personas { display:flex; gap:10px; flex-wrap:wrap; }
.mp-persona {
    flex:1; min-width:230px; background:#fff; border:2px solid #e5e8f0; border-radius:11px;
    padding:12px 14px; cursor:pointer; text-align:left; transition:all .15s; font-family:inherit;
}
.mp-persona:hover { background:#f7f9fd; border-color:#2d448855; transform:translateY(-1px); }
.mp-persona-cab { display:flex; justify-content:space-between; align-items:center; gap:8px; }
.mp-persona-nom { font-weight:700; color:#22366F; font-size:14.5px; }
.mp-persona-pct { font-weight:800; font-size:20px; }
.mp-persona-barra { background:#eef0f6; border-radius:20px; height:12px; overflow:hidden; margin:8px 0 7px; border:1px solid #dde3ef; }
.mp-persona-barra > div { height:100%; transition:width .3s; }
.mp-persona-det { display:flex; gap:10px; flex-wrap:wrap; font-size:12px; color:#3a4256; }
.mp-mini { font-size:10px; padding:2px 8px; border-radius:10px; background:#eef2fb; color:#55617f;
           display:inline-flex; align-items:center; gap:4px; }
.mp-btn-asig { border:1px dashed #C9A227; background:#fff; color:#8a6d1a; cursor:pointer; }
.mp-btn-asig:hover { background:#C9A22712; }
#mp-modal { position:fixed; inset:0; background:rgba(20,25,40,.5); z-index:1300;
            display:none; align-items:center; justify-content:center; padding:16px; }
#mp-modal .caja { background:#fff; border-radius:12px; max-width:520px; width:100%; max-height:88vh; overflow-y:auto; }
.mp-opt { display:block; width:100%; text-align:left; border:1px solid #e5e8f0; background:#fff;
          border-radius:9px; padding:11px 13px; margin-bottom:7px; cursor:pointer; }
.mp-opt:hover { background:#f4f7fd; border-color:#2d448855; }
@media (max-width: 640px) {
    .mp-edif { flex-wrap:wrap; }
    .mp-edif > div:first-child { flex:1 1 100%; }
    .mp-barra { flex:1 1 auto; }
}
</style>

<?php
// Totales de frentes y brigadas de todas sus parroquias.
$totFrentes = 0; $totBrigadas = 0; $frentesPorParr = [];
try {
    frenteRespAsegurar();
    $marcas = []; $params = [];
    foreach ($misParroquias as $i => $pp) { $marcas[] = ':fp'.$i; $params['fp'.$i] = $pp; }
    if ($marcas) {
        $stF = db()->prepare('SELECT f.parroquia,
                                     COUNT(DISTINCT f.id) AS frentes,
                                     COUNT(DISTINCT b.id) AS brigadas
                                FROM frente f
                                LEFT JOIN brigada b ON b.frente_id = f.id AND b.activa = 1
                               WHERE f.activo = 1 AND f.parroquia IN (' . implode(',', $marcas) . ')
                               GROUP BY f.parroquia');
        $stF->execute($params);
        foreach ($stF->fetchAll() as $r) {
            $frentesPorParr[$r['parroquia']] = [
                'frentes'  => (int)$r['frentes'],
                'brigadas' => (int)$r['brigadas'],
            ];
            $totFrentes  += (int)$r['frentes'];
            $totBrigadas += (int)$r['brigadas'];
        }
    }
} catch (Throwable $e) {}
?>

<!-- Frentes y brigadas del responsable -->
<div class="mp-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div class="mp-tit" style="margin:0;">
            <i class="bi bi-diagram-3-fill"></i> Mis frentes de trabajo
        </div>
        <a href="<?= APP_URL_BASE ?>seguimiento/frentes.php" class="btn btn-outline btn-sm">
            <i class="bi bi-gear"></i> Gestionar
        </a>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="flex:1;min-width:130px;text-align:center;padding:16px 10px;border-radius:11px;
                    border:1px solid #22366F33;background:#22366F0a;">
            <div style="font-size:34px;font-weight:800;color:#22366F;line-height:1;"><?= $totFrentes ?></div>
            <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;
                        letter-spacing:.3px;">Frentes de trabajo</div>
        </div>
        <div style="flex:1;min-width:130px;text-align:center;padding:16px 10px;border-radius:11px;
                    border:1px solid #2d448833;background:#2d44880a;">
            <div style="font-size:34px;font-weight:800;color:#2d4488;line-height:1;"><?= $totBrigadas ?></div>
            <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;
                        letter-spacing:.3px;">Brigadas en total</div>
        </div>
    </div>

    <?php if ($frentesPorParr): ?>
    <div style="margin-top:13px;padding-top:11px;border-top:1px solid #eef0f5;">
        <div style="font-size:11.5px;text-transform:uppercase;color:#55617f;font-weight:700;
                    letter-spacing:.4px;margin-bottom:8px;">Desglose por parroquia</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($misParroquias as $pp):
                $d = $frentesPorParr[$pp] ?? ['frentes' => 0, 'brigadas' => 0]; ?>
            <span style="background:#f7f9fd;border:1px solid #e5e8f0;border-radius:9px;
                         padding:8px 14px;font-size:12.5px;">
                <strong style="color:#22366F;"><?= e($pp) ?></strong><br>
                <span style="color:#5b6478;">
                    <?= $d['frentes'] ?> frente<?= $d['frentes'] === 1 ? '' : 's' ?> ·
                    <?= $d['brigadas'] ?> brigada<?= $d['brigadas'] === 1 ? '' : 's' ?>
                </span>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (count($misParroquias) > 1): ?>
<div class="mp-card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-weight:700;color:#22366F;font-size:16px;">
            <i class="bi bi-collection-fill"></i> Mis <?= count($misParroquias) ?> parroquias
        </div>
        <div class="text-sm text-muted" style="margin-top:2px;">
            <?= e(implode(' · ', $misParroquias)) ?>
        </div>
    </div>
    <a href="<?= APP_URL_BASE ?>seguimiento/pdf_mi_parroquia.php?estado=<?= urlencode($estadoUsr) ?>"
       target="_blank" class="btn btn-primary">
        <i class="bi bi-file-earmark-pdf-fill"></i> Informe general en PDF
    </a>
</div>
<?php endif; ?>

<?php foreach ($datos as $parr => $d):
    $pc = $d['por_color'] ?? [];
    $edifs = $d['edificaciones'] ?? [];
    // Separar por fase para que lo urgente salga primero.
    $enRecon = array_filter($edifs, fn($e) => ($e['avance'] ?? 0) < 100);
    $culminadas = array_filter($edifs, fn($e) => ($e['avance'] ?? 0) >= 100);
    $avgParr = $edifs ? (int)round(array_sum(array_column($edifs, 'avance')) / count($edifs)) : 0;
?>

<!-- Encabezado de la parroquia -->
<div class="mp-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div class="mp-tit" style="margin:0;font-size:19px;">
            <i class="bi bi-geo-alt-fill"></i> <?= e(mb_strtoupper($parr, 'UTF-8')) ?>
        </div>
        <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm">
            <i class="bi bi-map"></i> Ver en el mapa
        </a>
    </div>

    <!-- Avance general de la parroquia, bien visible -->
    <div style="margin:14px 0 6px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#55617f;margin-bottom:4px;">
            <span>Avance general de la parroquia</span>
            <strong style="font-size:16px;color:<?= $avgParr>=100?'#2E7D32':($avgParr>0?'#C9A227':'#97a0b8') ?>;"><?= $avgParr ?>%</strong>
        </div>
        <div style="background:#eef0f6;border-radius:20px;height:22px;overflow:hidden;">
            <div style="width:<?= $avgParr ?>%;height:100%;background:<?= $avgParr>=100?'#2E7D32':'#C9A227' ?>;transition:width .4s;"></div>
        </div>
    </div>

    <div class="mp-kpis" style="margin-top:14px;">
        <div class="mp-kpi" style="background:#A61C1C14;border:1px solid #A61C1C33;">
            <div class="n" style="color:#A61C1C;"><?= (int)($pc['rojo'] ?? 0) ?></div><div class="l">Rojo</div>
        </div>
        <div class="mp-kpi" style="background:#C9A22714;border:1px solid #C9A22733;">
            <div class="n" style="color:#C9A227;"><?= (int)($pc['amarillo'] ?? 0) ?></div><div class="l">Amarillo</div>
        </div>
        <div class="mp-kpi" style="background:#2E7D3214;border:1px solid #2E7D3233;">
            <div class="n" style="color:#2E7D32;"><?= (int)($pc['verde'] ?? 0) ?></div><div class="l">Verde</div>
        </div>
        <div class="mp-kpi" style="background:#2B2B2B10;border:1px solid #2B2B2B33;">
            <div class="n" style="color:#2B2B2B;"><?= (int)($pc['derrumbado'] ?? 0) ?></div><div class="l">Derrumbado</div>
        </div>
    </div>
</div>

<!-- Resumen por tramos de avance: lectura de un vistazo -->
<?php
$tramos = ['sin' => 0, 'inicial' => 0, 'medio' => 0, 'avanzado' => 0, 'listo' => 0];
foreach ($edifs as $e) {
    $a = (int)($e['avance'] ?? 0);
    if ($a >= 100)      $tramos['listo']++;
    elseif ($a >= 75)   $tramos['avanzado']++;
    elseif ($a >= 25)   $tramos['medio']++;
    elseif ($a > 0)     $tramos['inicial']++;
    else                $tramos['sin']++;
}
?>
<div class="mp-card">
    <div class="mp-tit"><i class="bi bi-bar-chart-fill"></i> Estado de las <?= count($edifs) ?> reconstrucciones</div>
    <div class="mp-tramos">
        <button class="mp-tramo" data-filtro="todos" onclick="filtrarTramo('todos')" style="border-color:#22366F;">
            <div class="n" style="color:#22366F;"><?= count($edifs) ?></div><div class="l">Todas</div>
        </button>
        <button class="mp-tramo" data-filtro="sin" onclick="filtrarTramo('sin')" style="border-color:#97a0b855;">
            <div class="n" style="color:#767c94;"><?= $tramos['sin'] ?></div><div class="l">Sin iniciar</div>
        </button>
        <button class="mp-tramo" data-filtro="inicial" onclick="filtrarTramo('inicial')" style="border-color:#C9A22755;">
            <div class="n" style="color:#C9A227;"><?= $tramos['inicial'] ?></div><div class="l">1 a 24%</div>
        </button>
        <button class="mp-tramo" data-filtro="medio" onclick="filtrarTramo('medio')" style="border-color:#C9A22755;">
            <div class="n" style="color:#C9A227;"><?= $tramos['medio'] ?></div><div class="l">25 a 74%</div>
        </button>
        <button class="mp-tramo" data-filtro="avanzado" onclick="filtrarTramo('avanzado')" style="border-color:#2E7D3255;">
            <div class="n" style="color:#5a9e3f;"><?= $tramos['avanzado'] ?></div><div class="l">75 a 99%</div>
        </button>
        <button class="mp-tramo" data-filtro="listo" onclick="filtrarTramo('listo')" style="border-color:#2E7D3255;">
            <div class="n" style="color:#2E7D32;"><?= $tramos['listo'] ?></div><div class="l">Culminadas</div>
        </button>
    </div>
</div>

<!-- Carga de trabajo por integrante del equipo GDC -->
<?php $prog = $d['progreso_frentes'] ?? []; ?>
<?php if ($prog): ?>
<div class="mp-card">
    <div class="mp-tit"><i class="bi bi-diagram-3-fill"></i> Progreso por frente de trabajo</div>
    <p class="text-sm text-muted" style="margin:-4px 0 12px;">
        Toque un frente para ver solo sus edificaciones.
    </p>
    <div class="mp-personas">
        <?php foreach ($prog as $fid => $pr):
            $av = (int)$pr['avance'];
            $col = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#a8871f' : '#5b6478'));
        ?>
        <button type="button" class="mp-persona" onclick="filtrarPorFrente(<?= (int)$pr['numero'] ?>)">
            <div class="mp-persona-cab">
                <span class="mp-persona-nom">
                    <span style="background:#22366F;color:#fff;width:22px;height:22px;border-radius:6px;
                                 display:inline-flex;align-items:center;justify-content:center;
                                 font-size:12px;font-weight:800;margin-right:5px;"><?= (int)$pr['numero'] ?></span>
                    Frente <?= (int)$pr['numero'] ?>
                </span>
                <span class="mp-persona-pct" style="color:<?= $col ?>;"><?= $av ?>%</span>
            </div>
            <div class="mp-persona-barra">
                <div style="width:<?= $av ?>%;background:<?= $col ?>;"></div>
            </div>
            <div class="mp-persona-det">
                <span><strong><?= (int)$pr['total'] ?></strong> obras</span>
                <span style="color:#2E7D32;"><strong><?= (int)$pr['culminadas'] ?></strong> listas</span>
                <span style="color:#a8871f;"><strong><?= (int)$pr['en_proceso'] ?></strong> en obra</span>
                <span style="color:#2d4488;"><strong><?= (int)$pr['brigadas'] ?></strong> brigadas</span>
            </div>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Listado con buscador y orden -->
<div class="mp-card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
        <div class="mp-tit" style="margin:0;flex:1;min-width:180px;">
            <i class="bi bi-hammer"></i> Reconstrucciones
            <span id="mp-contador" style="background:#eef2fb;color:#22366F;font-size:12px;padding:2px 9px;border-radius:12px;font-weight:700;"></span>
        </div>
        <input type="text" id="mp-buscar" class="form-control" style="width:210px;"
               placeholder="Buscar edificación…" oninput="filtrarLista()">
        <select id="mp-asignado" class="form-control" style="width:160px;" onchange="filtrarLista()">
            <option value="">Asignadas y no</option>
            <option value="1">Solo asignadas</option>
            <option value="0">Sin asignar</option>
        </select>
        <select id="mp-orden" class="form-control" style="width:190px;" onchange="filtrarLista()">
            <option value="color">Amarillo, rojo, verde</option>
            <option value="avance_desc">Mayor avance primero</option>
            <option value="avance_asc">Menor avance primero</option>
            <option value="nombre">Por nombre (A-Z)</option>
        </select>
        <a href="<?= APP_URL_BASE ?>seguimiento/pdf_mi_parroquia.php?parroquia=<?= urlencode($parr) ?>&estado=<?= urlencode($estadoUsr) ?>"
           target="_blank" class="btn btn-primary btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> Informe PDF
        </a>
    </div>

    <div style="background:#f4f7fd;border-radius:8px;padding:9px 12px;margin-bottom:10px;font-size:12.5px;color:#3a4256;">
        <strong>Cómo leer la lista:</strong>
        <span class="clas-letra" style="color:#C9A227;border-color:#C9A227;width:20px;height:20px;font-size:11px;">A</span> amarillo, precaución &nbsp;
        <span class="clas-letra" style="color:#A61C1C;border-color:#A61C1C;width:20px;height:20px;font-size:11px;">R</span> rojo, no entrar &nbsp;
        <span class="clas-letra" style="color:#2E7D32;border-color:#2E7D32;width:20px;height:20px;font-size:11px;">V</span> verde, habitable &nbsp;
        <span class="clas-letra" style="color:#2B2B2B;border-color:#2B2B2B;width:20px;height:20px;font-size:11px;">D</span> derrumbada
    </div>

    <div id="mp-lista">
    <?php
    recOrdenarPorColor($edifs);
    foreach ($edifs as $ed):
        $av = (int)($ed['avance'] ?? 0);
        $col = $av >= 100 ? '#2E7D32' : ($av >= 75 ? '#5a9e3f' : ($av > 0 ? '#C9A227' : '#97a0b8'));
        $colorDec = $cat[$ed['decision_final'] ?? '']['color'] ?? '#767c94';
        $sim = recSimboloDecision($ed['decision_final'] ?? null);
        $tramo = $av >= 100 ? 'listo' : ($av >= 75 ? 'avanzado' : ($av >= 25 ? 'medio' : ($av > 0 ? 'inicial' : 'sin')));
    ?>
    <?php
        $iid  = (int)$ed['id'];
        $ra   = $d['resumen_aptos'][$iid] ?? null;
        $numFrente = $d['frentes_obra'][$iid] ?? null;
        $resp = $numFrente ? ('Frente ' . $numFrente) : null;
    ?>
    <div class="mp-edif" data-tramo="<?= $tramo ?>" data-avance="<?= $av ?>"
         data-color="<?= recPrioridadColor($ed['decision_final'] ?? null) ?>"
         data-asignado="<?= $resp ? '1' : '0' ?>"
         data-nombre="<?= e(mb_strtolower(($ed['nombre'] ?? '') . ' ' . ($ed['codigo'] ?? '') . ' ' . ($resp ?? ''), 'UTF-8')) ?>">
        <span class="clas-letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;"
              title="<?= e($sim['texto']) ?>"><?= $sim['letra'] ?></span>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:#2a3140;font-size:15px;"><?= e($ed['nombre'] ?? 'Sin nombre') ?></div>
            <div style="font-size:12.5px;color:#5b6478;">
                <?= e($ed['codigo'] ?? '') ?><?php if (!empty($ed['ente'])): ?> · <?= e($ed['ente']) ?><?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;align-items:center;">
                <?php if ($ra && $ra['total'] > 0): ?>
                <span class="mp-mini" title="Apartamentos culminados de un total">
                    <i class="bi bi-door-open"></i> <?= $ra['culminados'] ?>/<?= $ra['total'] ?> aptos
                </span>
                <?php if ($ra['en_proceso'] > 0): ?>
                <span class="mp-mini" style="background:#C9A22718;color:#8a6d1a;">
                    <?= $ra['en_proceso'] ?> en proceso
                </span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($resp): ?>
                <span class="mp-mini" style="background:#2d448818;color:#2d4488;">
                    <i class="bi bi-person-check-fill"></i> <?= e($resp) ?>
                </span>
                <?php else: ?>
                <button type="button" class="mp-mini mp-btn-asig"
                        onclick="abrirAsignar(<?= $iid ?>, '<?= e(addslashes($ed['nombre'] ?? '')) ?>')">
                    <i class="bi bi-person-plus"></i> Asignar responsable
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="mp-barra"><div style="width:<?= $av ?>%;height:100%;background:<?= $col ?>;"></div></div>
        <span class="mp-pct" style="color:<?= $col ?>;"><?= $av ?>%</span>
        <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $iid ?>"
           class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
    </div>
    <p id="mp-vacio" class="text-muted" style="display:none;margin:10px 0 0;">Ninguna edificación coincide.</p>
</div>

<!-- Equipo desplegado: responsable + frentes -->
<div class="mp-card">
    <div class="mp-tit"><i class="bi bi-people-fill"></i> Equipo desplegado</div>

    <?php $encs = $d['encargados'] ?? []; ?>
    <?php if ($encs): ?>
        <?php foreach ($encs as $r): ?>
        <div style="background:#eef2fb;border-radius:9px;padding:11px 13px;margin-bottom:8px;">
            <div style="font-size:10px;text-transform:uppercase;color:#5a6785;letter-spacing:.4px;">Responsable de parroquia</div>
            <div style="font-weight:700;color:#22366F;font-size:15px;"><?= e($r['nombre']) ?></div>
            <?php if (!empty($r['telefono'])): ?>
            <div style="font-size:12px;color:#55617f;"><i class="bi bi-telephone"></i> <?= e($r['telefono']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted" style="margin:0 0 10px;">Sin responsable registrado.</p>
    <?php endif; ?>

    <?php $frentes = $d['frentes'] ?? []; ?>
    <?php if ($frentes): ?>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#55617f;
                letter-spacing:.4px;margin:15px 0 6px;">
        Frentes de trabajo (<?= count($frentes) ?>)
    </div>
        <?php foreach ($frentes as $f): ?>
        <div style="display:flex;gap:11px;align-items:flex-start;padding:10px 0;
                    border-bottom:1px solid #f4f6fa;">
            <span style="background:#22366F;color:#fff;width:30px;height:30px;border-radius:8px;
                         display:flex;align-items:center;justify-content:center;font-weight:800;
                         font-size:14px;flex-shrink:0;"><?= (int)$f['numero'] ?></span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14px;color:#2a3140;font-weight:700;">
                    Frente de Trabajo <?= (int)$f['numero'] ?>
                </div>
                <div style="margin-top:4px;display:flex;gap:5px;flex-wrap:wrap;">
                    <?php if (!empty($f['brigadas'])): foreach ($f['brigadas'] as $b): ?>
                    <span style="background:#eef2fb;color:#22366F;border-radius:7px;padding:3px 9px;
                                 font-size:11.5px;font-weight:700;">
                        Brigada <?= (int)$b['numero'] ?>
                        <?php if ((int)($b['obras'] ?? 0) > 0): ?>
                        <span style="font-weight:400;color:#5b6478;">· <?= (int)$b['obras'] ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; else: ?>
                    <span style="font-size:11.5px;color:#97a0b8;font-style:italic;">Sin brigadas</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ((int)($f['obras'] ?? 0) > 0): ?>
            <div style="text-align:center;min-width:52px;">
                <div style="font-size:16px;font-weight:800;color:#a8871f;"><?= (int)$f['obras'] ?></div>
                <div style="font-size:9.5px;color:#767c94;text-transform:uppercase;">obras</div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted" style="margin:12px 0 0;font-size:13px;">
            Esta parroquia no tiene frentes de trabajo.
            <a href="<?= APP_URL_BASE ?>seguimiento/frentes.php">Crear uno</a>.
        </p>
    <?php endif; ?>
</div>

</div>

<?php endforeach; ?>

<!-- Modal de asignación a integrante del frente -->
<div id="mp-modal">
    <div class="caja">
        <div style="background:#22366F;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:10px;opacity:.75;text-transform:uppercase;letter-spacing:.4px;">Asignar responsable</div>
                <b id="mp-modal-tit">Edificación</b>
            </div>
            <button onclick="cerrarAsignar()" style="background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="mp-modal-body" style="padding:16px 18px;"></div>
    </div>
</div>

<script>
const MP_PARROQUIA = <?= json_encode($misParroquias[0] ?? '') ?>;
const MP_ESTADO = <?= json_encode($estadoUsr) ?>;
const MP_URL = <?= json_encode(APP_URL_BASE . 'seguimiento/') ?>;
let _inspSel = 0;
let _frentesCache = null;

let _tramoActivo = 'todos';

function filtrarTramo(t) {
    _tramoActivo = t;
    document.querySelectorAll('.mp-tramo').forEach(b =>
        b.classList.toggle('activo', b.dataset.filtro === t));
    filtrarLista();
}

function filtrarLista() {
    const txt = (document.getElementById('mp-buscar').value || '').toLowerCase().trim();
    const orden = document.getElementById('mp-orden').value;
    const asig = document.getElementById('mp-asignado').value;
    const cont = document.getElementById('mp-lista');
    const filas = Array.from(cont.querySelectorAll('.mp-edif'));

    let visibles = 0;
    filas.forEach(f => {
        const okTramo = _tramoActivo === 'todos' || f.dataset.tramo === _tramoActivo;
        const okTexto = !txt || (f.dataset.nombre || '').includes(txt);
        const okAsig = asig === '' || f.dataset.asignado === asig;
        const ver = okTramo && okTexto && okAsig;
        f.style.display = ver ? '' : 'none';
        if (ver) visibles++;
    });

    // Reordenar solo las visibles
    const orden_fn = {
        color: (a,b) => (+a.dataset.color) - (+b.dataset.color)
                     || (+b.dataset.avance) - (+a.dataset.avance),
        avance_desc: (a,b) => (+b.dataset.avance) - (+a.dataset.avance),
        avance_asc:  (a,b) => (+a.dataset.avance) - (+b.dataset.avance),
        nombre:      (a,b) => (a.dataset.nombre||'').localeCompare(b.dataset.nombre||''),
    }[orden];
    filas.sort(orden_fn).forEach(f => cont.appendChild(f));

    document.getElementById('mp-contador').textContent = visibles;
    document.getElementById('mp-vacio').style.display = visibles ? 'none' : '';
}

// Buscar por integrante desde las tarjetas de carga.
function filtrarPorFrente(numero) {
    const b = document.getElementById('mp-buscar');
    if (b) { b.value = 'frente ' + numero; filtrarLista(); b.scrollIntoView({behavior:'smooth', block:'center'}); }
}

function buscarMiembro(nombre) {
    document.getElementById('mp-buscar').value = nombre;
    filtrarLista();
    document.getElementById('mp-lista').scrollIntoView({behavior:'smooth', block:'start'});
}

// --- Asignación a un integrante del frente ---
async function abrirAsignar(inspeccionId, nombreEdif) {
    _inspSel = inspeccionId;
    document.getElementById('mp-modal-tit').textContent = nombreEdif || 'Edificación';
    const body = document.getElementById('mp-modal-body');
    body.innerHTML = '<p class="text-muted">Cargando equipos…</p>';
    document.getElementById('mp-modal').style.display = 'flex';

    if (!_frentesCache) {
        try {
            const res = await fetch(MP_URL + 'asignar_frente.php', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ accion:'integrantes', estado: MP_ESTADO, parroquia: MP_PARROQUIA })
            });
            const d = await res.json();
            if (!d.ok) { body.innerHTML = '<p class="text-muted">' + (d.mensaje || 'No se pudo cargar.') + '</p>'; return; }
            _frentesCache = d;
        } catch (e) {
            body.innerHTML = '<p class="text-muted">Error de red.</p>'; return;
        }
    }
    pintarOpciones(body);
}

function pintarOpciones(body) {
    const tipos = _frentesCache.tipos || {};
    const iconos = { gdc:'bi-people-fill', sistematizador:'bi-clipboard-data',
                     corporacion:'bi-tools', movilizaciones:'bi-megaphone' };
    let html = '';
    (_frentesCache.frentes || []).forEach(f => {
        const sector = f.sector ? ` <span style="background:#C9A22722;color:#8a6d1a;font-size:10px;padding:1px 6px;border-radius:10px;">${f.sector}</span>` : '';
        html += `<div style="margin-bottom:14px;">
            <div style="font-size:10px;text-transform:uppercase;color:#97a0b8;letter-spacing:.3px;margin-bottom:5px;">
                <i class="bi ${iconos[f.tipo]||'bi-dot'}"></i> ${tipos[f.tipo] || f.tipo}${sector}
            </div>`;
        f.integrantes.forEach(m => {
            html += `<button type="button" class="mp-opt" onclick="guardarAsignacion(${f.frente_id}, '${f.tipo}', ${JSON.stringify(m).replace(/'/g,"&#39;")})">
                       <strong style="color:#22366F;">${m}</strong>
                       <div style="font-size:11px;color:#97a0b8;">del equipo ${f.nombre}</div>
                     </button>`;
        });
        html += '</div>';
    });
    body.innerHTML = html || '<p class="text-muted">No hay frentes de trabajo registrados en esta parroquia.</p>';
}

async function guardarAsignacion(frenteId, tipo, miembro) {
    const body = document.getElementById('mp-modal-body');
    body.innerHTML = '<p class="text-muted">Guardando…</p>';
    try {
        const res = await fetch(MP_URL + 'asignar_frente.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ inspeccion_id: _inspSel, frente_id: frenteId, tipo: tipo, miembro: miembro })
        });
        const d = await res.json();
        if (d.sesion_expirada) { alert(d.mensaje); return; }
        if (!d.ok) { body.innerHTML = '<p class="text-muted">' + (d.mensaje || 'Error.') + '</p>'; return; }
        location.reload();
    } catch (e) {
        body.innerHTML = '<p class="text-muted">Error de red.</p>';
    }
}

function cerrarAsignar() {
    document.getElementById('mp-modal').style.display = 'none';
}

filtrarTramo('todos');
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
