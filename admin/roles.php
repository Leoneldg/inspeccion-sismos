<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('usuarios', 'ver');

$pageTitle    = 'Roles y Permisos';
$pageSubtitle = 'Defina qué puede hacer cada rol en cada módulo del sistema';
$activeModule = 'roles';

$pdo = db();
$modulos = $pdo->query('SELECT * FROM modulos ORDER BY orden')->fetchAll();
$roles   = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();

$permisosRaw = $pdo->query('SELECT * FROM rol_modulo_permisos')->fetchAll();
$matriz = []; // [rol_id][modulo_id] = ['ver'=>,'crear'=>,...]
foreach ($permisosRaw as $p) {
    $matriz[$p['rol_id']][$p['modulo_id']] = $p;
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (puede('usuarios', 'crear')): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2><i class="bi bi-plus-circle-fill"></i> Crear nuevo rol</h2></div>
    <div class="card-body">
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_rol.php" class="flex gap-8" style="align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="crear_rol">
            <div class="field" style="flex:1;min-width:200px;">
                <label class="req">Nombre del rol</label>
                <input required name="nombre" class="form-control" placeholder="Ej: Analista de Datos">
            </div>
            <div class="field" style="flex:2;min-width:260px;">
                <label>Descripción</label>
                <input name="descripcion" class="form-control" placeholder="Breve descripción del rol">
            </div>
            <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Crear rol</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php foreach ($roles as $rol): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <h2><i class="bi bi-shield-lock-fill"></i> <?= e($rol['nombre']) ?>
            <?php if ($rol['es_sistema']): ?><span class="badge badge-gris" style="margin-left:8px;">Rol base</span><?php endif; ?>
        </h2>
        <?php if (!$rol['es_sistema'] && puede('usuarios', 'eliminar')): ?>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_rol.php" onsubmit="return confirm('¿Eliminar este rol? Los usuarios asignados quedarán sin acceso.');">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="eliminar_rol">
            <input type="hidden" name="rol_id" value="<?= (int)$rol['id'] ?>">
            <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Eliminar rol</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($rol['descripcion']): ?><p class="text-muted text-sm" style="margin:0 0 12px;"><?= e($rol['descripcion']) ?></p><?php endif; ?>

        <?php if (puede('usuarios', 'editar')): ?>
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_rol.php">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="accion" value="guardar_permisos">
        <input type="hidden" name="rol_id" value="<?= (int)$rol['id'] ?>">
        <?php endif; ?>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th style="text-align:center;">Ver</th>
                        <th style="text-align:center;">Crear</th>
                        <th style="text-align:center;">Editar</th>
                        <th style="text-align:center;">Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($modulos as $mod):
                    $p = $matriz[$rol['id']][$mod['id']] ?? ['ver'=>0,'crear'=>0,'editar'=>0,'eliminar'=>0];
                    $disabled = puede('usuarios', 'editar') ? '' : 'disabled';
                    // El módulo de seguimiento solo usa ver/crear/eliminar (editar no aplica).
                    $esSeguimiento = ($mod['nombre'] === 'Seguimiento y Control');
                ?>
                    <tr>
                        <td><i class="bi <?= e($mod['icono']) ?>"></i> <?= e($mod['nombre']) ?></td>
                        <?php foreach (['ver','crear','editar','eliminar'] as $accion): ?>
                        <td style="text-align:center;">
                            <?php if ($accion === 'editar' && $esSeguimiento): ?>
                                <span class="text-muted" title="No aplica en seguimiento" style="font-size:11px;">—</span>
                                <input type="hidden" name="permisos[<?= (int)$mod['id'] ?>][editar]" value="0">
                            <?php else: ?>
                            <input type="checkbox" <?= $disabled ?>
                                name="permisos[<?= (int)$mod['id'] ?>][<?= $accion ?>]" value="1"
                                <?= $p[$accion] ? 'checked' : '' ?>
                                style="width:17px;height:17px;accent-color:var(--azul-700);">
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (puede('usuarios', 'editar')): ?>
        <button class="btn btn-primary btn-sm" style="margin-top:14px;"><i class="bi bi-save-fill"></i> Guardar permisos de <?= e($rol['nombre']) ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
