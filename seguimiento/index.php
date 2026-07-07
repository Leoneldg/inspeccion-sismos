<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$pageTitle    = 'Seguimiento y Control';
$pageSubtitle = 'Reconstrucción y recuperación de edificaciones inspeccionadas';
$activeModule = 'seguimiento';

$filtros = [
    'q'           => trim($_GET['q'] ?? ''),
    'estado'      => trim($_GET['estado'] ?? ''),
    'estado_obra' => trim($_GET['estado_obra'] ?? ''),
    'ente_id'     => trim($_GET['ente_id'] ?? ''),
    'solo_mias'   => !empty($_GET['solo_mias']),
];

$kpis        = segKpis();
$edificios   = segListaEdificios($filtros);
$entes       = segEntes(usuarioEsMaster() ? null : estadoDelUsuario());
$decisiones  = catalogoDecisionFinal();
$estadosObra = segEstadosObra();

include __DIR__ . '/../includes/header.php';
?>

<!-- KPIs del módulo -->
<div class="seg-kpi-grid">
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-buildings-fill"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['total_edificios'] ?></div><div class="seg-kpi-lbl">Edificaciones</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#fff4e0;color:#C9A227;"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['en_ejecucion'] ?></div><div class="seg-kpi-lbl">En ejecución</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#e5f7ee;color:#2E7D32;"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['culminadas'] ?></div><div class="seg-kpi-lbl">Culminadas</div></div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-ico" style="background:#f1f2f6;color:#767c94;"><i class="bi bi-clipboard-x"></i></div>
        <div><div class="seg-kpi-num"><?= (int)$kpis['sin_seguimiento'] ?></div><div class="seg-kpi-lbl">Sin seguimiento</div></div>
    </div>
    <div class="seg-kpi seg-kpi-wide">
        <div class="seg-kpi-ico" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-graph-up-arrow"></i></div>
        <div style="flex:1;">
            <div class="seg-kpi-lbl">Avance promedio</div>
            <div class="seg-progress" style="margin-top:6px;">
                <div class="seg-progress-bar" style="width:<?= round((float)$kpis['avance_promedio']) ?>%;"></div>
                <span class="seg-progress-txt"><?= round((float)$kpis['avance_promedio']) ?>%</span>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-body">
        <form method="get" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="margin:0;">
                <label class="text-sm">Buscar</label>
                <input type="text" name="q" class="form-control" style="width:230px;" placeholder="Edificio o código…" value="<?= e($filtros['q']) ?>">
            </div>
            <?php if (usuarioEsMaster()): ?>
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado</label>
                <select name="estado" class="form-control" style="width:170px;">
                    <option value="">Todos</option>
                    <?php foreach (catalogoEstados() as $est): ?>
                        <option value="<?= e($est) ?>" <?= $filtros['estado'] === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field" style="margin:0;">
                <label class="text-sm">Estado de obra</label>
                <select name="estado_obra" class="form-control" style="width:160px;">
                    <option value="">Todas</option>
                    <option value="__sin__" <?= $filtros['estado_obra'] === '__sin__' ? 'selected' : '' ?>>Sin seguimiento</option>
                    <?php foreach (array_keys($estadosObra) as $eo): ?>
                        <option value="<?= e($eo) ?>" <?= $filtros['estado_obra'] === $eo ? 'selected' : '' ?>><?= e($eo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;">
                <label class="text-sm">Ente</label>
                <select name="ente_id" class="form-control" style="width:180px;">
                    <option value="">Todos</option>
                    <?php foreach ($entes as $ente): ?>
                        <option value="<?= (int)$ente['id'] ?>" <?= (string)$filtros['ente_id'] === (string)$ente['id'] ? 'selected' : '' ?>><?= e($ente['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="check-row" style="margin:0 4px 6px;">
                <input type="checkbox" name="solo_mias" value="1" <?= $filtros['solo_mias'] ? 'checked' : '' ?>>
                <span class="text-sm">Solo asignadas a mí</span>
            </label>
            <button class="btn btn-outline"><i class="bi bi-funnel"></i> Filtrar</button>
            <a href="<?= APP_URL_BASE ?>seguimiento/entes.php" class="btn btn-outline"><i class="bi bi-building-gear"></i> Entes</a>
        </form>
    </div>
</div>

<!-- Tabla de edificaciones -->
<div class="card">
    <div class="card-header"><h2><i class="bi bi-list-check"></i> Edificaciones (<?= count($edificios) ?>)</h2></div>
    <?php if (!$edificios): ?>
        <div class="empty-state"><i class="bi bi-clipboard2-x"></i> No hay edificaciones con esos filtros.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table seg-table">
            <thead>
                <tr>
                    <th>Edificación</th>
                    <?php if (usuarioEsMaster()): ?><th>Estado</th><?php endif; ?>
                    <th>Ubicación</th>
                    <th>Ente asignado</th>
                    <th>Estado de obra</th>
                    <th style="min-width:130px;">Avance</th>
                    <th>Tiempo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($edificios as $ed): ?>
                <?php
                    $estadoObra = $ed['estado_obra'] ?? null;
                    $colorObra  = $estadoObra ? ($estadosObra[$estadoObra] ?? '#767c94') : '#c2c7d6';
                    $avance     = (float)($ed['avance_pct'] ?? 0);
                    $tiempo     = segTiempoRestante($ed['fecha_fin_estimada'] ?? null, $estadoObra ?? 'Sin iniciar');
                    $fichaUrl   = APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . (int)$ed['inspeccion_id'];
                ?>
                <tr>
                    <td>
                        <strong><?= e($ed['nombre_edificio']) ?></strong><br>
                        <span class="text-sm text-muted" style="font-family:var(--font-mono);"><?= e($ed['codigo']) ?></span>
                    </td>
                    <?php if (usuarioEsMaster()): ?><td><span class="badge badge-gris"><?= e($ed['estado'] ?? '—') ?></span></td><?php endif; ?>
                    <td class="text-sm">
                        <?= e($ed['parroquia']) ?>
                        <?php if (!empty($ed['municipio']) && ($ed['estado'] ?? '') !== 'Distrito Capital'): ?><br><span class="text-muted"><?= e($ed['municipio']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-sm">
                        <?php if ($ed['ente_nombre']): ?>
                            <span class="badge" style="background:#eaf0ff;color:#2d4488;"><i class="bi bi-building"></i> <?= e($ed['ente_nombre']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($estadoObra): ?>
                            <span class="badge" style="background:<?= $colorObra ?>22;color:<?= $colorObra ?>;"><?= e($estadoObra) ?></span>
                        <?php else: ?>
                            <span class="badge badge-gris">Sin seguimiento</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="seg-progress seg-progress-sm">
                            <div class="seg-progress-bar" style="width:<?= round($avance) ?>%;background:<?= $colorObra ?>;"></div>
                            <span class="seg-progress-txt"><?= round($avance) ?>%</span>
                        </div>
                    </td>
                    <td class="text-sm">
                        <?php
                            $badges = [
                                'a_tiempo'  => ['#2E7D32', $tiempo['dias'] . ' días'],
                                'proximo'   => ['#C9A227', $tiempo['dias'] . ' días'],
                                'vencido'   => ['#A61C1C', abs($tiempo['dias']) . ' días vencido'],
                                'culminada' => ['#2E7D32', 'Culminada'],
                                'sin_fecha' => ['#767c94', '—'],
                            ];
                            [$c, $txt] = $badges[$tiempo['estado']] ?? ['#767c94', '—'];
                        ?>
                        <span style="color:<?= $c ?>;font-weight:600;"><?= e($txt) ?></span>
                    </td>
                    <td>
                        <a href="<?= $fichaUrl ?>" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-data"></i> Ficha</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
