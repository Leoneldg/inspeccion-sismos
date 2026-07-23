<?php
/**
 * REQUISICIÓN IMPRIMIBLE.
 *
 * Dos modos:
 *   ?id=12           una requisición (el documento formal)
 *   ?consolidado=1   el total solicitado (opcional &parroquia= &incluir_borradores=1)
 *
 * Es HTML preparado para imprimir: se abre y se manda a la impresora o se
 * guarda como PDF desde el navegador, igual que los demás listados.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/requisiciones.php';

requierePermiso('seguimiento', 'ver');
reqAsegurarTablas();

function escR($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$esConsolidado = !empty($_GET['consolidado']);
$reqId         = (int)($_GET['id'] ?? 0);
$parrF         = trim($_GET['parroquia'] ?? '');
$incBorr       = !empty($_GET['incluir_borradores']);

$req    = null;
$grupos = [];
$totLin = 0;

if ($esConsolidado) {
    $filtros = ['incluir_borradores' => $incBorr];
    if ($parrF !== '') $filtros['parroquia'] = $parrF;
    $grupos = reqConsolidado($filtros);
} else {
    if ($reqId <= 0) exit('Indique la requisición: ?id=NUMERO');
    $req = reqObtener($reqId);
    if (!$req) exit('La requisición no existe.');
    $grupos = reqRenglones($reqId);
}

foreach ($grupos as $g) { $totLin += count($g['lineas']); }

$fecha = date('d/m/Y');
$hora  = date('H:i');
$quien = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'usuario del sistema');
$estadoInfo = $req ? reqEstadoInfo($req['estado']) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= $esConsolidado ? 'Consolidado de requisiciones' : escR($req['numero']) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Arial, sans-serif; margin: 0;
        padding: 22px 26px; color: #222; font-size: 12px;
    }
    .cab {
        border-bottom: 2px solid #22366F; padding-bottom: 12px; margin-bottom: 14px;
        display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
    }
    .cab h1 { margin: 0; font-size: 20px; color: #22366F; letter-spacing: .5px; }
    .cab .tipo {
        font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px;
        color: #767c94; font-weight: 700; margin-bottom: 2px;
    }
    .cab .sub { font-size: 12.5px; color: #444; margin-top: 4px; }
    .cab .meta { font-size: 10px; color: #767c94; text-align: right; white-space: nowrap; }
    .sello {
        display: inline-block; border: 2px solid; border-radius: 6px;
        padding: 4px 12px; font-size: 12px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 1px; margin-top: 6px;
    }

    /* Datos del documento */
    .datos {
        display: flex; gap: 26px; flex-wrap: wrap;
        background: #f7f9fd; border: 1px solid #e6e9f2;
        padding: 9px 13px; margin-bottom: 14px; border-radius: 5px;
    }
    .datos .d .et {
        font-size: 9px; text-transform: uppercase; letter-spacing: .4px;
        color: #8a93a8; font-weight: 700;
    }
    .datos .d .va { font-size: 11.5px; color: #2a3140; font-weight: 600; }

    .aclara {
        background: #fbfcfe; border-left: 3px solid #22366F;
        padding: 8px 11px; font-size: 10.5px; color: #45506b;
        margin-bottom: 14px; line-height: 1.5;
    }
    .obs {
        border: 1px solid #e6e9f2; border-radius: 5px;
        padding: 8px 11px; font-size: 11px; color: #45506b; margin-bottom: 14px;
    }

    .rubro { margin-bottom: 14px; page-break-inside: avoid; }
    .rubro h2 {
        margin: 0 0 5px; font-size: 12.5px; padding: 6px 10px;
        background: #f2f5fc; border-left: 3px solid #22366F; color: #22366F;
    }
    table { width: 100%; border-collapse: collapse; }
    th {
        text-align: left; font-size: 9.5px; text-transform: uppercase;
        color: #767c94; border-bottom: 1px solid #d8dce6;
        padding: 5px 8px; letter-spacing: .3px;
    }
    td { padding: 6px 8px; border-bottom: 1px solid #f0f2f7; font-size: 11.5px; }
    tr:nth-child(even) td { background: #fafbfe; }
    .num { text-align: right; font-weight: 700; color: #22366F; white-space: nowrap; }
    .uni { color: #5b6478; font-weight: 400; font-size: 10.5px; }
    .nota { color: #8a93a8; font-size: 10px; font-style: italic; }

    .vacio {
        border: 1px dashed #c9d0e0; padding: 22px; text-align: center;
        color: #767c94; font-size: 12px;
    }

    /* Solicitante: cierra el documento */
    .solic {
        margin-top: 26px; border: 1px solid #d8dce6; border-radius: 5px;
        padding: 11px 14px; page-break-inside: avoid; max-width: 340px;
    }
    .solic .et {
        font-size: 9px; text-transform: uppercase; letter-spacing: .5px;
        color: #8a93a8; font-weight: 700; margin-bottom: 3px;
    }
    .solic .nom { font-size: 13px; font-weight: 700; color: #22366F; }
    .solic .sub { font-size: 10.5px; color: #5b6478; margin-top: 2px; }

    .pie {
        margin-top: 22px; border-top: 1px solid #e0e4ee; padding-top: 8px;
        font-size: 9.5px; color: #8a93a8; display: flex; justify-content: space-between;
    }
    .btn-imp {
        position: fixed; top: 14px; right: 16px; background: #22366F; color: #fff;
        border: 0; border-radius: 7px; padding: 9px 15px; cursor: pointer; font-size: 12.5px;
    }
    @media print {
        .btn-imp { display: none; }
        body { padding: 0; }
    }
</style>
</head>
<body>

<button class="btn-imp" onclick="window.print()">Imprimir / Guardar PDF</button>

<div class="cab">
    <div>
        <div class="tipo">
            <?= $esConsolidado ? 'Consolidado de requisiciones' : 'Requisición de material' ?>
        </div>
        <h1><?= $esConsolidado ? 'Total solicitado' : escR($req['numero']) ?></h1>
        <div class="sub">
            <?php if ($esConsolidado): ?>
                <?= $parrF !== '' ? 'Parroquia: ' . escR($parrF) : 'Todas las edificaciones' ?>
            <?php else: ?>
                <?= escR($req['nombre_edificio'] ?: 'Edificación') ?>
                <?= !empty($req['codigo']) ? ' · ' . escR($req['codigo']) : '' ?>
                <?= !empty($req['parroquia']) ? ' · ' . escR($req['parroquia']) : '' ?>
            <?php endif; ?>
        </div>
        <?php if (!$esConsolidado && $estadoInfo): ?>
        <div class="sello" style="color:<?= escR($estadoInfo['color']) ?>;
                    border-color:<?= escR($estadoInfo['color']) ?>;">
            <?= escR($estadoInfo['nombre']) ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="meta">
        Impreso: <?= escR($fecha) ?> · <?= escR($hora) ?><br>
        Por: <?= escR($quien) ?><br>
        <?= (int)$totLin ?> renglón<?= $totLin === 1 ? '' : 'es' ?>
    </div>
</div>

<?php if (!$esConsolidado): ?>
<div class="datos">
    <div class="d">
        <div class="et">Solicitado por</div>
        <div class="va"><?= escR(reqSolicitante($req)['nombre'] ?: '—') ?></div>
    </div>
    <div class="d">
        <div class="et">Fecha de creación</div>
        <div class="va">
            <?= !empty($req['creado_en']) ? date('d/m/Y', strtotime($req['creado_en'])) : '—' ?>
        </div>
    </div>
    <?php if (!empty($req['emitida_en'])): ?>
    <div class="d">
        <div class="et">Fecha de emisión</div>
        <div class="va"><?= date('d/m/Y H:i', strtotime($req['emitida_en'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($req['emitida_por_nombre'])): ?>
    <div class="d">
        <div class="et">Emitida por</div>
        <div class="va"><?= escR($req['emitida_por_nombre']) ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($req['observaciones'])): ?>
<div class="obs">
    <strong>Observaciones:</strong> <?= escR($req['observaciones']) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="aclara">
    <strong>Nota.</strong> Esta requisición es complementaria al levantamiento
    técnico. El levantamiento calcula lo estructural (bloques, cemento, arena,
    friso, pintura) a partir de los metros medidos. Aquí se solicita lo demás
    que la reconstrucción necesita: electricidad, plomería, cal, herramientas
    y otros materiales.
    <?php if ($esConsolidado): ?>
        <br><strong><?= $incBorr
            ? 'Incluye borradores y requisiciones emitidas.'
            : 'Solo incluye requisiciones emitidas.' ?></strong>
    <?php endif; ?>
</div>

<?php if (!$grupos): ?>
    <div class="vacio">
        <?= $esConsolidado
            ? 'Todavía no se ha solicitado ningún material.'
            : 'Esta requisición no tiene materiales cargados.' ?>
    </div>
<?php else: ?>
    <?php foreach ($grupos as $g): ?>
    <div class="rubro">
        <h2><?= escR($g['rubro']['nombre']) ?></h2>
        <table>
            <thead>
                <tr>
                    <th style="width:50%;">Material</th>
                    <th style="width:18%;" class="num">Cantidad</th>
                    <th style="width:32%;"><?= $esConsolidado ? 'Origen' : 'Observación' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($g['lineas'] as $l):
                $cant = $esConsolidado ? (float)$l['total'] : (float)$l['cantidad']; ?>
                <tr>
                    <td><?= escR($l['material'] ?: 'Sin nombre') ?></td>
                    <td class="num">
                        <?= reqFormatoCantidad($cant, (string)$l['unidad']) ?>
                        <span class="uni"><?= escR($l['unidad']) ?></span>
                    </td>
                    <td>
                        <?php if ($esConsolidado): ?>
                            <?= (int)$l['n_requisiciones'] ?>
                            requisición<?= (int)$l['n_requisiciones'] === 1 ? '' : 'es' ?>,
                            <?= (int)$l['n_edificios'] ?>
                            edificación<?= (int)$l['n_edificios'] === 1 ? '' : 'es' ?>
                        <?php else: ?>
                            <span class="nota"><?= escR($l['nota'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
<?php endif; ?>


<?php if (!$esConsolidado): $sol = reqSolicitante($req); ?>
<div class="solic">
    <div class="et">Solicitado por</div>
    <div class="nom"><?= escR($sol['nombre'] ?: '—') ?></div>
    <?php
    // Cedula y profesion solo salen cuando el responsable esta registrado
    // en el levantamiento; si no, el nombre va solo.
    $detSol = [];
    if (!empty($sol['profesion'])) $detSol[] = $sol['profesion'];
    if (!empty($sol['cedula']))    $detSol[] = 'C.I. ' . $sol['cedula'];
    ?>
    <?php if ($detSol): ?>
    <div class="sub"><?= escR(implode(' · ', $detSol)) ?></div>
    <?php endif; ?>
    <?php if (!empty($req['emitida_en'])): ?>
    <div class="sub">Emitida el <?= date('d/m/Y', strtotime($req['emitida_en'])) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="pie">
    <span><?= escR(APP_NAME ?? 'Sistema de inspección') ?></span>
    <span>Generado el <?= escR($fecha) ?> a las <?= escR($hora) ?></span>
</div>

</body>
</html>
