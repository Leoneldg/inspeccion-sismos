<?php
/**
 * LEVANTAMIENTOS SIN ETIQUETA.
 *
 * La etiqueta es la prueba de que la edificación fue inspeccionada y
 * clasificada. Esta pantalla muestra los levantamientos cerrados que
 * no tienen su foto, separando los que declararon un motivo de los
 * que simplemente la omitieron.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$datos = segSinEtiqueta();
$conMotivo = $datos['con_motivo'];
$sinMotivo = $datos['sin_motivo'];

// Motivos legibles.
$MOTIVOS = [
    'No fue colocada'        => 'Nunca fue colocada',
    'Se desprendió'          => 'Se desprendió o se perdió',
    'Ilegible'               => 'Está pero ilegible o borrada',
    'Fachada inaccesible'    => 'No se pudo acceder a la fachada',
    'Edificación derrumbada' => 'La edificación está derrumbada',
    'Otro'                   => 'Otro motivo',
];

// Agrupar los sin motivo por parroquia, que es como se reparte el trabajo.
$porParroquia = [];
foreach ($sinMotivo as $e) {
    $porParroquia[$e['parroquia']][] = $e;
}
ksort($porParroquia);

$pageTitle    = 'Levantamientos sin etiqueta';
$pageSubtitle = $datos['total'] . ' edificaciones';
$activeModule = 'sin_etiqueta';
include __DIR__ . '/../includes/header.php';
?>
<style>
.et-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:18px 20px; margin-bottom:16px; }
.et-fila { display:flex; align-items:center; gap:12px; padding:11px 8px;
           border-bottom:1px solid #f0f2f7; flex-wrap:wrap; }
.et-fila:last-child { border-bottom:0; }
.et-fila:hover { background:#fafbfe; }
.et-parr { background:#22366F; color:#fff; padding:8px 14px; border-radius:8px 8px 0 0;
           font-weight:700; font-size:13.5px; }
.clas-letra { display:inline-flex; align-items:center; justify-content:center;
              width:26px; height:26px; border:2px solid; border-radius:6px;
              font-weight:800; font-size:13px; flex-shrink:0; }
@media (max-width: 640px) {
    .et-fila > div:nth-child(2) { flex:1 1 100%; }
}
</style>

<!-- Resumen -->
<div class="et-card">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="flex:1;min-width:140px;text-align:center;padding:15px 10px;
                    border-radius:11px;border:2px solid <?= count($sinMotivo) > 0 ? '#A61C1C55' : '#2E7D3233' ?>;
                    background:<?= count($sinMotivo) > 0 ? '#A61C1C0a' : '#2E7D320a' ?>;">
            <div style="font-size:30px;font-weight:800;
                        color:<?= count($sinMotivo) > 0 ? '#A61C1C' : '#2E7D32' ?>;line-height:1;">
                <?= count($sinMotivo) ?>
            </div>
            <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">
                Sin motivo indicado
            </div>
        </div>
        <div style="flex:1;min-width:140px;text-align:center;padding:15px 10px;
                    border-radius:11px;border:1px solid #C9A22755;background:#C9A2270a;">
            <div style="font-size:30px;font-weight:800;color:#a8871f;line-height:1;">
                <?= count($conMotivo) ?>
            </div>
            <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">
                Con motivo indicado
            </div>
        </div>
        <div style="flex:1;min-width:140px;text-align:center;padding:15px 10px;
                    border-radius:11px;border:1px solid #97a0b833;">
            <div style="font-size:30px;font-weight:800;color:#5b6478;line-height:1;">
                <?= $datos['total'] ?>
            </div>
            <div style="font-size:11px;text-transform:uppercase;color:#55617f;margin-top:5px;">
                Total sin etiqueta
            </div>
        </div>
    </div>

    <?php if (count($sinMotivo) > 0): ?>
    <div style="background:#fdf0f0;border:1px solid #A61C1C33;border-radius:9px;
                padding:11px 14px;margin-top:13px;font-size:12.5px;color:#A61C1C;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong><?= count($sinMotivo) ?> edificación(es) marcadas sin etiqueta,
        pero sin indicar el motivo.</strong><br>
        Conviene preguntarle al técnico qué pasó: si nunca se colocó,
        si se desprendió o si está ilegible.
    </div>
    <?php elseif ($datos['total'] === 0): ?>
    <div style="background:#eff8f1;border:1px solid #2E7D3233;border-radius:9px;
                padding:11px 14px;margin-top:13px;font-size:13px;color:#2E7D32;">
        <i class="bi bi-shield-check"></i>
        <strong>Todas las edificaciones levantadas tienen su etiqueta.</strong>
    </div>
    <?php endif; ?>
</div>

<!-- Sin explicación: lo que hay que resolver -->
<?php if ($sinMotivo): ?>
<div class="et-card" style="padding:0;overflow:hidden;">
    <div style="background:#A61C1C;color:#fff;padding:13px 20px;">
        <div style="font-weight:700;font-size:15px;">
            <i class="bi bi-tag"></i> Sin etiqueta y sin motivo indicado
        </div>
        <div style="font-size:12.5px;opacity:.9;">
            El técnico marcó que no hay etiqueta, pero no dijo por qué
        </div>
    </div>

    <?php foreach ($porParroquia as $parr => $lista): ?>
    <div class="et-parr" style="border-radius:0;">
        <?= e(mb_strtoupper($parr, 'UTF-8')) ?>
        <span style="float:right;font-weight:600;font-size:12px;">
            <?= count($lista) ?> edificación<?= count($lista) === 1 ? '' : 'es' ?>
        </span>
    </div>
    <div style="padding:4px 20px 12px;">
        <?php foreach ($lista as $e): ?>
        <div class="et-fila">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:14px;">
                    <?= e($e['nombre']) ?>
                </div>
                <div style="font-size:11.5px;color:#5b6478;">
                    <?= e($e['codigo']) ?>
                    <?php if ($e['ente']): ?> · <?= e($e['ente']) ?><?php endif; ?>
                </div>
                <?php if ($e['quien']): ?>
                <div style="font-size:11px;color:#767c94;">
                    <i class="bi bi-person"></i> Levantamiento de <?= e($e['quien']) ?>
                    <?php if ($e['cuando']): ?> · <?= e($e['cuando']) ?><?php endif; ?>
                    <?php if (empty($e['cerrado'])): ?>
                        · <span style="color:#a8871f;">sin cerrar</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <a href="<?= APP_URL_BASE ?>seguimiento/levantamiento.php?inspeccion=<?= $e['id'] ?>"
               class="btn btn-outline btn-sm" title="Abrir el levantamiento para indicar el motivo">
                <i class="bi bi-pencil"></i> Indicar motivo
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Con motivo: justificados -->
<?php if ($conMotivo): ?>
<div class="et-card" style="padding:0;overflow:hidden;">
    <div style="background:#C9A227;color:#22366F;padding:13px 20px;">
        <div style="font-weight:700;font-size:15px;">
            <i class="bi bi-info-circle"></i> Sin etiqueta, con motivo indicado
        </div>
        <div style="font-size:12.5px;opacity:.85;">
            El técnico explicó por qué no está la etiqueta
        </div>
    </div>
    <div style="padding:6px 20px 14px;">
        <?php foreach ($conMotivo as $e): ?>
        <div class="et-fila">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:14px;">
                    <?= e($e['nombre']) ?>
                </div>
                <div style="font-size:11.5px;color:#5b6478;">
                    <?= e($e['codigo']) ?> · <?= e($e['parroquia']) ?>
                </div>
                <div style="font-size:12px;color:#a8871f;font-weight:600;margin-top:3px;">
                    <i class="bi bi-tag"></i>
                    <?= e($MOTIVOS[$e['motivo']] ?? $e['motivo']) ?>
                </div>
                <?php if ($e['observacion']): ?>
                <div style="font-size:11.5px;color:#767c94;font-style:italic;">
                    <?= e($e['observacion']) ?>
                </div>
                <?php endif; ?>
            </div>
            <a href="<?= APP_URL_BASE ?>seguimiento/remodelacion.php?inspeccion=<?= $e['id'] ?>"
               class="btn btn-outline btn-sm">
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
