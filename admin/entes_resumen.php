<?php
// Panel exclusivo del administrador MASTER: muestra un resumen tipo "bases de
// datos por ente" — cuántos usuarios, inspectores e inspecciones tiene cada
// ente — más el total global del sistema.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('usuarios', 'ver');

// Solo el master ve este panel.
if (!usuarioEsMaster()) {
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-error"><i class="bi bi-shield-lock"></i><div>Este panel es exclusivo del administrador con alcance nacional (master).</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle    = 'Bases de datos por ente';
$pageSubtitle = 'Resumen nacional: cuántos usuarios, inspectores e inspecciones tiene cada ente';
$activeModule = 'entes_resumen';

$pdo = db();
$hayEntes = tablaEntesExiste();
$colInspEnte = columnaInspeccionExiste('ente_id');
$colIngEnte  = false;
try { $pdo->query('SELECT ente_id FROM ingenieros LIMIT 1'); $colIngEnte = true; } catch (Throwable $e) { $colIngEnte = false; }

// ----- Totales globales -----
$totUsuarios    = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$totInspectores = (int)$pdo->query('SELECT COUNT(*) FROM ingenieros')->fetchColumn();
$totInspecciones= (int)$pdo->query('SELECT COUNT(*) FROM inspecciones')->fetchColumn();
$totEntes       = $hayEntes ? (int)$pdo->query('SELECT COUNT(*) FROM entes')->fetchColumn() : 0;

// ----- Detalle por ente -----
$filas = [];
if ($hayEntes) {
    $entes = $pdo->query('SELECT id, nombre, tipo, estado FROM entes ORDER BY nombre')->fetchAll();
    foreach ($entes as $en) {
        $eid = (int)$en['id'];
        $nUsuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE ente_id = ' . $eid)->fetchColumn();
        $nInsp = $colIngEnte ? (int)$pdo->query('SELECT COUNT(*) FROM ingenieros WHERE ente_id = ' . $eid)->fetchColumn() : 0;
        $nInspecciones = $colInspEnte ? (int)$pdo->query('SELECT COUNT(*) FROM inspecciones WHERE ente_id = ' . $eid)->fetchColumn() : 0;
        $filas[] = [
            'nombre' => $en['nombre'], 'tipo' => $en['tipo'], 'estado' => $en['estado'],
            'usuarios' => $nUsuarios, 'inspectores' => $nInsp, 'inspecciones' => $nInspecciones,
        ];
    }
    // "Sin ente" (datos no asignados a ningún ente).
    $sinU = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE ente_id IS NULL')->fetchColumn();
    $sinI = $colIngEnte ? (int)$pdo->query('SELECT COUNT(*) FROM ingenieros WHERE ente_id IS NULL')->fetchColumn() : $totInspectores;
    $sinInsp = $colInspEnte ? (int)$pdo->query('SELECT COUNT(*) FROM inspecciones WHERE ente_id IS NULL')->fetchColumn() : $totInspecciones;
    if ($sinU || $sinI || $sinInsp) {
        $filas[] = ['nombre' => 'Sin ente asignado', 'tipo' => '—', 'estado' => null,
                    'usuarios' => $sinU, 'inspectores' => $sinI, 'inspecciones' => $sinInsp, 'sin_ente' => true];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Totales globales -->
<div class="cards-kpi" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px;">
    <div class="card" style="border-top:4px solid #22366f;"><div class="card-body">
        <div class="text-sm text-muted">Entes</div>
        <div style="font-size:30px;font-weight:800;color:#22366f;"><?= $totEntes ?></div>
    </div></div>
    <div class="card" style="border-top:4px solid #2d4488;"><div class="card-body">
        <div class="text-sm text-muted">Usuarios</div>
        <div style="font-size:30px;font-weight:800;color:#22366f;"><?= $totUsuarios ?></div>
    </div></div>
    <div class="card" style="border-top:4px solid #1c6b3d;"><div class="card-body">
        <div class="text-sm text-muted">Inspectores</div>
        <div style="font-size:30px;font-weight:800;color:#22366f;"><?= $totInspectores ?></div>
    </div></div>
    <div class="card" style="border-top:4px solid #e07a1a;"><div class="card-body">
        <div class="text-sm text-muted">Inspecciones</div>
        <div style="font-size:30px;font-weight:800;color:#22366f;"><?= $totInspecciones ?></div>
    </div></div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="bi bi-hdd-stack-fill"></i> Detalle por ente</h2></div>
    <?php if (!$hayEntes): ?>
        <div class="card-body text-muted">El módulo de entes no está disponible en esta instalación.</div>
    <?php elseif (!$filas): ?>
        <div class="card-body text-muted">Todavía no hay entes registrados.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Ente</th><th>Tipo</th><th>Estado</th>
                <th style="text-align:center;">Usuarios</th>
                <th style="text-align:center;">Inspectores</th>
                <th style="text-align:center;">Inspecciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
                <tr <?= !empty($f['sin_ente']) ? 'style="background:#f8fafc;"' : '' ?>>
                    <td><strong><?= e($f['nombre']) ?></strong></td>
                    <td><span class="text-sm text-muted"><?= e($f['tipo'] ?: '—') ?></span></td>
                    <td><span class="text-sm"><?= e($f['estado'] ?: 'Nacional') ?></span></td>
                    <td style="text-align:center;font-weight:700;"><?= $f['usuarios'] ?></td>
                    <td style="text-align:center;font-weight:700;"><?= $f['inspectores'] ?></td>
                    <td style="text-align:center;font-weight:700;"><?= $f['inspecciones'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #22366f;font-weight:800;">
                    <td colspan="3" style="text-align:right;">TOTAL GLOBAL</td>
                    <td style="text-align:center;"><?= $totUsuarios ?></td>
                    <td style="text-align:center;"><?= $totInspectores ?></td>
                    <td style="text-align:center;"><?= $totInspecciones ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
