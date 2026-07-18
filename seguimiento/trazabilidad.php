<?php
/**
 * HISTORIAL / TRAZABILIDAD de una inspección.
 * Muestra quién realizó el levantamiento técnico, quién lo cerró y la
 * bitácora completa de acciones (con usuario, fecha e IP).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
$insp = $inspeccionId ? segInspeccion($inspeccionId) : null;
if (!$insp) {
    flash('error', 'Inspección no encontrada.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php');
    exit;
}

$ed = recEdificio($inspeccionId);
$edificioId = (int)($ed['id'] ?? 0);
$resp = $edificioId ? recResponsableLevantamiento($edificioId) : [];
$historial = recHistorial($inspeccionId, 300);

$pageTitle    = 'Trazabilidad: ' . $insp['nombre_edificio'];
$pageSubtitle = 'Código ' . $insp['codigo'];
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';

$etiquetas = [
    'levantamiento_paso1'    => ['Datos del edificio', 'bi-building', '#2d4488'],
    'apartamento_registrado' => ['Apartamento registrado', 'bi-door-open', '#2d4488'],
    'avance_actualizado'     => ['Avance actualizado', 'bi-graph-up-arrow', '#C9A227'],
    'foto_subida'            => ['Foto cargada', 'bi-camera', '#767c94'],
    'levantamiento_cerrado'  => ['Levantamiento cerrado', 'bi-check2-circle', '#2E7D32'],
    'ente_asignado'          => ['Ente asignado', 'bi-building-gear', '#2d4488'],
];
?>
<style>
.tz-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07); padding:18px 20px; margin-bottom:16px; }
.tz-resp { display:flex; gap:14px; flex-wrap:wrap; }
.tz-box { flex:1; min-width:220px; background:#f7f9fd; border-radius:10px; padding:14px 16px; }
.tz-box .lbl { font-size:11px; text-transform:uppercase; color:#767c94; letter-spacing:.4px; }
.tz-box .val { font-size:15px; font-weight:700; color:#22366F; margin-top:3px; }
.tz-box .sub { font-size:12px; color:#55617f; margin-top:2px; }
.tz-fila { display:flex; gap:12px; align-items:flex-start; padding:11px 4px; border-bottom:1px solid #f0f2f7; }
.tz-fila:last-child { border-bottom:0; }
.tz-ico { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:#fff; }

@media (max-width: 640px) {
    .tz-card { padding:14px 15px; }
    .tz-box { min-width:100% !important; }
    .tz-fila { flex-wrap:wrap; gap:8px; }
    .tz-fila > div:last-child { flex:1 1 100%; text-align:left; font-size:11px; }
    .tz-fila > div:last-child br { display:none; }
    .tz-fila > div:last-child span::before { content:' · '; }
}
</style>

<div class="tz-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div style="font-weight:700;color:#22366F;"><i class="bi bi-shield-check"></i> Responsables del levantamiento técnico</div>
        <div style="display:flex;gap:8px;">
            <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $inspeccionId ?>" class="btn btn-outline btn-sm">
                <i class="bi bi-clipboard-data"></i> Ficha de seguimiento
            </a>
            <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
    <div class="tz-resp">
        <div class="tz-box">
            <div class="lbl">Inició el levantamiento</div>
            <div class="val"><?= e($resp['creado_por_nombre'] ?? 'Sin registro') ?></div>
            <?php if (!empty($resp['creado_en'])): ?>
            <div class="sub"><?= date('d/m/Y H:i', strtotime($resp['creado_en'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="tz-box">
            <div class="lbl">Cerró el levantamiento</div>
            <div class="val"><?= e($resp['completado_por_nombre'] ?? 'Pendiente') ?></div>
            <?php if (!empty($resp['completado_en'])): ?>
            <div class="sub"><?= date('d/m/Y H:i', strtotime($resp['completado_en'])) ?></div>
            <?php else: ?>
            <div class="sub" style="color:#C9A227;">Levantamiento aún no cerrado</div>
            <?php endif; ?>
        </div>
        <div class="tz-box">
            <div class="lbl">Estado</div>
            <div class="val" style="color:<?= !empty($resp['completado']) ? '#2E7D32' : '#C9A227' ?>;">
                <?= !empty($resp['completado']) ? 'Completado' : 'En proceso' ?>
            </div>
            <div class="sub"><?= count($historial) ?> acción(es) registrada(s)</div>
        </div>
    </div>
</div>

<div class="tz-card">
    <div style="font-weight:700;color:#22366F;margin-bottom:6px;"><i class="bi bi-journal-text"></i> Bitácora de acciones</div>
    <p class="text-sm text-muted" style="margin:0 0 12px;">Registro de quién hizo cada cambio, con fecha y dirección IP.</p>

    <?php if (!$historial): ?>
        <p class="text-muted" style="margin:0;">
            Aún no hay acciones registradas para esta inspección.
            <br><span class="text-sm">La bitácora comienza a registrar desde que se instala esta versión.</span>
        </p>
    <?php else: ?>
        <?php foreach ($historial as $h):
            $meta = $etiquetas[$h['accion']] ?? [$h['accion'], 'bi-dot', '#767c94'];
        ?>
        <div class="tz-fila">
            <div class="tz-ico" style="background:<?= $meta[2] ?>;"><i class="bi <?= $meta[1] ?>"></i></div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:13px;"><?= e($meta[0]) ?></div>
                <?php if (!empty($h['detalle'])): ?>
                <div style="font-size:12px;color:#55617f;"><?= e($h['detalle']) ?></div>
                <?php endif; ?>
                <div style="font-size:11px;color:#97a0b8;margin-top:2px;">
                    <i class="bi bi-person"></i> <?= e($h['usuario_nombre'] ?: 'Usuario desconocido') ?>
                    <?php if (!empty($h['ip'])): ?> · IP <?= e($h['ip']) ?><?php endif; ?>
                </div>
            </div>
            <div style="font-size:11px;color:#767c94;white-space:nowrap;">
                <?= date('d/m/Y', strtotime($h['creado_en'])) ?><br>
                <span style="color:#97a0b8;"><?= date('H:i', strtotime($h['creado_en'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
