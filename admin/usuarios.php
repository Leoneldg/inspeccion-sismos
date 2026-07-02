<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('usuarios', 'ver');

$pageTitle    = 'Usuarios';
$pageSubtitle = 'Gestión de cuentas y asignación de roles';
$activeModule = 'usuarios';

$pdo = db();
$roles = $pdo->query('SELECT id, nombre FROM roles ORDER BY nombre')->fetchAll();

$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editUser = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editUser = $stmt->fetch();
}

$usuarios = $pdo->query(
    'SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.creado_en DESC'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="split-grid cols-sidebar align-start">

<?php if (puede('usuarios', $editUser ? 'editar' : 'crear')): ?>
<div class="card">
    <div class="card-header"><h2><i class="bi bi-person-plus-fill"></i> <?= $editUser ? 'Editar usuario' : 'Nuevo usuario' ?></h2></div>
    <div class="card-body">
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_usuario.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <?php if ($editUser): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>

            <div class="field" style="margin-bottom:14px;">
                <label class="req">Nombre completo</label>
                <input required name="nombre_completo" class="form-control" value="<?= e($editUser['nombre_completo'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label class="req">Usuario</label>
                <input required name="usuario" class="form-control" value="<?= e($editUser['usuario'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label class="req">Correo electrónico</label>
                <input required type="email" name="email" class="form-control" value="<?= e($editUser['email'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label class="req">Rol</label>
                <select required name="rol_id" class="form-control">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= ($editUser['rol_id'] ?? null) == $r['id'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label <?= $editUser ? '' : 'class=req' ?>>Contraseña <?= $editUser ? '(dejar en blanco para no cambiar)' : '' ?></label>
                <input <?= $editUser ? '' : 'required' ?> type="password" name="password" class="form-control" minlength="8" placeholder="Mínimo 8 caracteres">
            </div>
            <div class="check-row" style="margin-bottom:16px;">
                <input type="checkbox" name="activo" id="activo" value="1" <?= ($editUser['activo'] ?? 1) ? 'checked' : '' ?>>
                <label for="activo">Usuario activo</label>
            </div>

            <div class="flex gap-8">
                <button class="btn btn-primary w-full" style="justify-content:center;"><i class="bi bi-save-fill"></i> <?= $editUser ? 'Actualizar' : 'Crear usuario' ?></button>
                <?php if ($editUser): ?><a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body text-muted text-sm">No tiene permisos para crear o editar usuarios.</div></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="bi bi-people-fill"></i> Usuarios registrados (<?= count($usuarios) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><strong><?= e($u['nombre_completo']) ?></strong><br><span class="text-sm text-muted"><?= e($u['email']) ?></span></td>
                    <td><?= e($u['usuario']) ?></td>
                    <td><span class="badge badge-gris"><?= e($u['rol_nombre']) ?></span></td>
                    <td><?= $u['activo'] ? '<span class="badge badge-verde">Activo</span>' : '<span class="badge badge-rojo">Inactivo</span>' ?></td>
                    <td class="text-sm text-muted"><?= e($u['ultimo_acceso'] ?? 'Nunca') ?></td>
                    <td>
                        <div class="flex gap-8">
                            <?php if (puede('usuarios', 'editar')): ?>
                            <a href="?id=<?= (int)$u['id'] ?>" class="btn btn-outline btn-sm"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (puede('usuarios', 'eliminar') && $u['id'] != $_SESSION['user_id']): ?>
                            <form method="post" action="<?= APP_URL_BASE ?>admin/eliminar_usuario.php" onsubmit="return confirm('¿Eliminar este usuario?');">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
