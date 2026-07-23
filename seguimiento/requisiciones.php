<?php
/**
 * REQUISICIONES — pantalla principal del módulo.
 *
 * Tres vistas:
 *   1. Requisiciones: todos los documentos emitidos y en borrador.
 *   2. Edificaciones: a cuál todavía no se le ha solicitado nada.
 *   3. Consolidado: cuánto se ha pedido en total de cada material.
 *
 * Solo lee y escribe en las tablas req_*.
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

$vistasOk = ['requisiciones', 'edificaciones', 'consolidado'];
$vista = in_array($_GET['vista'] ?? '', $vistasOk, true)
       ? $_GET['vista'] : 'requisiciones';

$parrF   = trim($_GET['parroquia'] ?? '');
$texto   = trim($_GET['q'] ?? '');
$estadoF = trim($_GET['estado'] ?? '');
$incBorr = !empty($_GET['incluir_borradores']);

$filtros = [];
if ($parrF !== '')   $filtros['parroquia'] = $parrF;
if ($texto !== '')   $filtros['texto'] = $texto;
if ($estadoF !== '') $filtros['estado'] = $estadoF;

$requisiciones = ($vista === 'requisiciones') ? reqListado($filtros) : [];
$edificaciones = ($vista === 'edificaciones') ? reqEdificaciones($filtros) : [];
$consolidado   = ($vista === 'consolidado')
               ? reqConsolidado(array_merge($filtros, ['incluir_borradores' => $incBorr]))
               : [];

// Resumen para las tarjetas de arriba.
$totBorr = 0; $totEmit = 0;
try {
    $q = db()->query("SELECT estado, COUNT(*) n FROM req_requisicion GROUP BY estado");
    foreach ($q->fetchAll() as $f) {
        if ($f['estado'] === 'emitida') $totEmit = (int)$f['n'];
        else $totBorr = (int)$f['n'];
    }
} catch (Throwable $e) {}

$parroquias = [];
try {
    $cond = []; $par = [];
    aplicarScopeEstado($cond, $par, 'i');
    aplicarScopeParroquia($cond, $par, 'i');
    $cond[] = "i.parroquia IS NOT NULL AND i.parroquia <> ''";
    $stP = db()->prepare('SELECT DISTINCT i.parroquia
                            FROM inspecciones i
                            JOIN rec_edificio re ON re.inspeccion_id = i.id
                           WHERE ' . implode(' AND ', $cond) . '
                        ORDER BY i.parroquia');
    $stP->execute($par);
    $parroquias = $stP->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) { $parroquias = []; }

$pageTitle    = 'Requisiciones de material';
$pageSubtitle = 'Solicitud de lo que hace falta para reconstruir';
$activeModule = 'requisiciones';
include __DIR__ . '/../includes/header.php';
?>

<style>
.rl-wrap { max-width: 1080px; margin: 0 auto; }

.rl-nota {
    background: #f7f9fd; border-left: 3px solid #22366F;
    border-radius: 7px; padding: 11px 14px; margin-bottom: 15px;
    font-size: 12.5px; color: #45506b; line-height: 1.55;
}

.rl-tarjetas { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; }
.rl-tarjeta {
    flex: 1 1 150px; background: #fff; border: 1px solid #e6e9f2;
    border-radius: 11px; padding: 13px 15px;
}
.rl-tarjeta .n { font-size: 23px; font-weight: 800; color: #22366F; line-height: 1; }
.rl-tarjeta .t { font-size: 11.5px; color: #5b6478; margin-top: 4px; }

.rl-tabs { display: flex; gap: 7px; margin-bottom: 15px; flex-wrap: wrap; }
.rl-tab {
    padding: 8px 15px; border-radius: 9px; background: #f2f5fc;
    border: 1px solid #e6e9f2; color: #5b6478; font-size: 13px;
    cursor: pointer; text-decoration: none; font-weight: 600;
}
.rl-tab.activa { background: #22366F; border-color: #22366F; color: #fff; }

.rl-filtros {
    background: #fff; border: 1px solid #e6e9f2; border-radius: 11px;
    padding: 12px 14px; margin-bottom: 15px;
    display: flex; gap: 9px; flex-wrap: wrap; align-items: flex-end;
}

/* Fila de requisición */
.rl-req {
    background: #fff; border: 1px solid #e6e9f2; border-radius: 10px;
    padding: 12px 15px; margin-bottom: 8px;
    display: flex; align-items: center; gap: 13px; flex-wrap: wrap;
    text-decoration: none; color: inherit;
}
.rl-req:hover { border-color: #22366F55; background: #fafbfe; }
.rl-req .num {
    font-weight: 800; color: #22366F; font-size: 14px;
    letter-spacing: .3px; white-space: nowrap;
}
.rl-req .info { flex: 1; min-width: 170px; }
.rl-req .edif { font-size: 13px; color: #2a3140; font-weight: 600; }
.rl-req .det { font-size: 11.5px; color: #8a93a8; margin-top: 2px; }
.rl-sello {
    border-radius: 20px; padding: 3px 11px; font-size: 11.5px;
    font-weight: 700; white-space: nowrap;
}

.rl-badge {
    border-radius: 20px; padding: 3px 11px;
    font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.rl-badge.si { background: #e5f7ee; color: #2E7D32; }
.rl-badge.no { background: #fdf3e7; color: #A66A00; }

/* Consolidado */
.rl-rubro {
    background: #fff; border: 1px solid #e6e9f2;
    border-radius: 11px; margin-bottom: 12px; overflow: hidden;
}
.rl-rubro-cab {
    padding: 11px 16px; background: #f7f9fd;
    border-bottom: 1px solid #eef0f5;
    display: flex; align-items: center; gap: 9px;
    font-weight: 700; font-size: 13.5px;
}
.rl-item {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 16px; border-bottom: 1px solid #f4f6fa;
}
.rl-item:last-child { border-bottom: 0; }
.rl-item .nom { flex: 1; font-size: 13px; color: #2a3140; }
.rl-item .nom small { color: #8a93a8; font-size: 11px; }
.rl-item .tot {
    font-weight: 800; color: #22366F; font-size: 15px; white-space: nowrap;
}
.rl-item .tot span { font-weight: 500; color: #5b6478; font-size: 11.5px; }

.rl-vacio {
    background: #fff; border: 2px dashed #dde2ee; border-radius: 12px;
    padding: 34px 20px; text-align: center; color: #767c94;
}
.rl-vacio i { font-size: 34px; color: #c3cade; display: block; margin-bottom: 9px; }

@media (max-width: 640px) {
    .rl-req { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="rl-wrap">

    <div class="rl-nota">
        <strong>¿Qué es una requisición?</strong>
        Es la solicitud formal del material que hace falta para reconstruir una
        edificación. El levantamiento técnico ya calcula lo estructural —bloques,
        cemento, arena, friso, pintura— a partir de los metros medidos. La
        requisición pide <em>todo lo demás</em>: electricidad, plomería, cal,
        herramientas y cualquier otro material.
        <br>
        Cada requisición tiene su número, se prepara como borrador y se emite
        cuando está lista. Una vez emitida queda cerrada como constancia.
    </div>

    <div class="rl-tarjetas">
        <div class="rl-tarjeta">
            <div class="n" style="color:#A66A00;"><?= $totBorr ?></div>
            <div class="t">En borrador (preparándose)</div>
        </div>
        <div class="rl-tarjeta">
            <div class="n" style="color:#2E7D32;"><?= $totEmit ?></div>
            <div class="t">Emitidas</div>
        </div>
        <div class="rl-tarjeta">
            <div class="n"><?= $totBorr + $totEmit ?></div>
            <div class="t">Total de requisiciones</div>
        </div>
    </div>

    <div class="rl-tabs">
        <a class="rl-tab <?= $vista === 'requisiciones' ? 'activa' : '' ?>"
           href="?vista=requisiciones<?= $parrF ? '&parroquia=' . urlencode($parrF) : '' ?>">
            <i class="bi bi-file-earmark-text"></i> Requisiciones
        </a>
        <a class="rl-tab <?= $vista === 'edificaciones' ? 'activa' : '' ?>"
           href="?vista=edificaciones<?= $parrF ? '&parroquia=' . urlencode($parrF) : '' ?>">
            <i class="bi bi-buildings"></i> Por edificación
        </a>
        <a class="rl-tab <?= $vista === 'consolidado' ? 'activa' : '' ?>"
           href="?vista=consolidado<?= $parrF ? '&parroquia=' . urlencode($parrF) : '' ?>">
            <i class="bi bi-clipboard-data"></i> Total consolidado
        </a>
        <?php if ($vista === 'consolidado'): ?>
        <a class="rl-tab" target="_blank"
           href="<?= APP_URL_BASE ?>seguimiento/pdf_requisicion.php?consolidado=1<?= $parrF ? '&parroquia=' . urlencode($parrF) : '' ?><?= $incBorr ? '&incluir_borradores=1' : '' ?>"
           style="border-color:#A61C1C55;color:#A61C1C;">
            <i class="bi bi-printer"></i> Imprimir
        </a>
        <?php endif; ?>
    </div>

    <!-- Filtros -->
    <form method="get" class="rl-filtros">
        <input type="hidden" name="vista" value="<?= e($vista) ?>">
        <div style="flex:1 1 190px;">
            <label style="display:block;font-size:11.5px;font-weight:600;color:#45506b;margin-bottom:4px;">
                Buscar
            </label>
            <input type="text" name="q" class="form-control" value="<?= e($texto) ?>"
                   placeholder="<?= $vista === 'requisiciones' ? 'Número, edificación o código' : 'Nombre o código' ?>">
        </div>
        <div style="flex:1 1 160px;">
            <label style="display:block;font-size:11.5px;font-weight:600;color:#45506b;margin-bottom:4px;">
                Parroquia
            </label>
            <select name="parroquia" class="form-control">
                <option value="">Todas</option>
                <?php foreach ($parroquias as $p): ?>
                <option value="<?= e($p) ?>" <?= $parrF === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($vista === 'requisiciones'): ?>
        <div style="flex:0 1 150px;">
            <label style="display:block;font-size:11.5px;font-weight:600;color:#45506b;margin-bottom:4px;">
                Estado
            </label>
            <select name="estado" class="form-control">
                <option value="">Todos</option>
                <?php foreach (reqEstados() as $k => $inf): ?>
                <option value="<?= e($k) ?>" <?= $estadoF === $k ? 'selected' : '' ?>>
                    <?= e($inf['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($vista === 'consolidado'): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;
                      color:#45506b;cursor:pointer;padding-bottom:8px;">
            <input type="checkbox" name="incluir_borradores" value="1" <?= $incBorr ? 'checked' : '' ?>>
            Incluir borradores
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-search"></i> Filtrar
        </button>
        <?php if ($parrF !== '' || $texto !== '' || $estadoF !== ''): ?>
        <a href="?vista=<?= e($vista) ?>" class="btn btn-outline">Limpiar</a>
        <?php endif; ?>
    </form>

    <?php if ($vista === 'requisiciones'): ?>
        <!-- ============ Lista de requisiciones ============ -->
        <?php if (!$requisiciones): ?>
        <div class="rl-vacio">
            <i class="bi bi-file-earmark-text"></i>
            <div style="font-weight:600;color:#5b6478;">Todavía no hay requisiciones</div>
            <div style="font-size:12.5px;margin-top:4px;">
                Vaya a <strong>Por edificación</strong> y cree la primera.
            </div>
        </div>
        <?php else: ?>
            <?php foreach ($requisiciones as $r):
                $inf = reqEstadoInfo($r['estado']); ?>
            <a class="rl-req" href="<?= APP_URL_BASE ?>seguimiento/requisicion.php?id=<?= (int)$r['id'] ?>">
                <div class="num"><?= e($r['numero']) ?></div>
                <div class="info">
                    <div class="edif"><?= e($r['nombre_edificio'] ?: 'Sin nombre') ?></div>
                    <div class="det">
                        <?= e($r['codigo'] ?? '') ?>
                        <?= !empty($r['parroquia']) ? ' · ' . e($r['parroquia']) : '' ?>
                        · <?= (int)$r['n_renglones'] ?> material<?= (int)$r['n_renglones'] === 1 ? '' : 'es' ?>
                        <?php $solL = reqSolicitante($r); ?>
                        <?php if (!empty($solL['nombre'])): ?>
                        · <?= e($solL['nombre']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="rl-sello"
                      style="background:<?= e($inf['fondo']) ?>;color:<?= e($inf['color']) ?>;">
                    <i class="bi <?= e($inf['icono']) ?>"></i> <?= e($inf['nombre']) ?>
                </span>
                <span style="font-size:11.5px;color:#8a93a8;white-space:nowrap;">
                    <?= !empty($r['emitida_en'])
                        ? date('d/m/Y', strtotime($r['emitida_en']))
                        : (!empty($r['creado_en']) ? date('d/m/Y', strtotime($r['creado_en'])) : '') ?>
                </span>
                <i class="bi bi-chevron-right" style="color:#c3cade;"></i>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ($vista === 'edificaciones'): ?>
        <!-- ============ Edificaciones ============ -->
        <?php if (!$edificaciones): ?>
        <div class="rl-vacio">
            <i class="bi bi-buildings"></i>
            <div style="font-weight:600;color:#5b6478;">No hay edificaciones con levantamiento</div>
            <div style="font-size:12.5px;margin-top:4px;">
                Primero hay que hacer el levantamiento técnico.
            </div>
        </div>
        <?php else: ?>
            <?php foreach ($edificaciones as $ed):
                $n = (int)$ed['n_req']; ?>
            <div class="rl-req" style="cursor:default;">
                <div class="info">
                    <div class="edif"><?= e($ed['nombre_edificio'] ?: 'Sin nombre') ?></div>
                    <div class="det">
                        <?= e($ed['codigo'] ?? '') ?>
                        <?= !empty($ed['parroquia']) ? ' · ' . e($ed['parroquia']) : '' ?>
                        <?= !empty($ed['completado']) ? ' · Levantamiento cerrado' : ' · En proceso' ?>
                    </div>
                </div>
                <?php if ($n > 0): ?>
                <span class="rl-badge si">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= $n ?> requisición<?= $n === 1 ? '' : 'es' ?>
                    <?php if ((int)$ed['n_emitidas'] > 0): ?>
                    · <?= (int)$ed['n_emitidas'] ?> emitida<?= (int)$ed['n_emitidas'] === 1 ? '' : 's' ?>
                    <?php endif; ?>
                </span>
                <?php else: ?>
                <span class="rl-badge no">
                    <i class="bi bi-exclamation-circle"></i> Sin solicitar
                </span>
                <?php endif; ?>

                <a href="<?= APP_URL_BASE ?>seguimiento/requisicion.php?inspeccion=<?= (int)$ed['inspeccion_id'] ?>"
                   class="btn <?= $n > 0 ? 'btn-outline' : 'btn-primary' ?> btn-sm">
                    <i class="bi bi-<?= $n > 0 ? 'folder2-open' : 'plus-circle' ?>"></i>
                    <?= $n > 0 ? 'Ver' : 'Crear requisición' ?>
                </a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- ============ Consolidado ============ -->
        <?php if (!$consolidado): ?>
        <div class="rl-vacio">
            <i class="bi bi-clipboard-data"></i>
            <div style="font-weight:600;color:#5b6478;">Todavía no hay nada solicitado</div>
            <div style="font-size:12.5px;margin-top:4px;">
                <?= $incBorr
                    ? 'Cree una requisición y agréguele materiales.'
                    : 'Solo se cuentan las requisiciones emitidas. Marque «Incluir borradores» para ver también las que se están preparando.' ?>
            </div>
        </div>
        <?php else: ?>
            <div style="font-size:12.5px;color:#5b6478;margin-bottom:11px;">
                <?= $incBorr
                    ? 'Suma de <strong>todas</strong> las requisiciones, incluidos los borradores.'
                    : 'Suma de las requisiciones <strong>emitidas</strong>. Los borradores no se cuentan porque todavía se están preparando.' ?>
                <?= $parrF ? ' Parroquia: ' . e($parrF) . '.' : '' ?>
            </div>
            <?php foreach ($consolidado as $grupo): ?>
            <div class="rl-rubro">
                <div class="rl-rubro-cab" style="color:<?= e($grupo['rubro']['color']) ?>;">
                    <i class="bi <?= e($grupo['rubro']['icono']) ?>"></i>
                    <?= e($grupo['rubro']['nombre']) ?>
                    <span style="margin-left:auto;font-size:11.5px;color:#5b6478;font-weight:600;">
                        <?= count($grupo['lineas']) ?> material<?= count($grupo['lineas']) === 1 ? '' : 'es' ?>
                    </span>
                </div>
                <?php foreach ($grupo['lineas'] as $l): ?>
                <div class="rl-item">
                    <div class="nom">
                        <?= e($l['material'] ?: 'Sin nombre') ?>
                        <small>· <?= (int)$l['n_requisiciones'] ?>
                            requisición<?= (int)$l['n_requisiciones'] === 1 ? '' : 'es' ?>,
                            <?= (int)$l['n_edificios'] ?>
                            edificación<?= (int)$l['n_edificios'] === 1 ? '' : 'es' ?></small>
                    </div>
                    <div class="tot">
                        <?= reqFormatoCantidad((float)$l['total'], (string)$l['unidad']) ?>
                        <span><?= e($l['unidad']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
