<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');

$pageTitle    = 'Entes ejecutores';
$pageSubtitle = 'Gobernaciones, alcaldías, ministerios y empresas responsables de la recuperación';
$activeModule = 'seguimiento';

$pdo = db();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editEnte = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM entes WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editEnte = $stmt->fetch();
}

// Alcance: el estadal solo ve entes de su estado o nacionales.
$conds = ['1=1']; $params = [];
if (!usuarioEsMaster()) {
    $conds[] = '(estado = :e OR estado IS NULL)';
    $params['e'] = estadoDelUsuario();
}
$stmt = $pdo->prepare('SELECT * FROM entes WHERE ' . implode(' AND ', $conds) . ' ORDER BY nombre');
$stmt->execute($params);
$entes = $stmt->fetchAll();

$tipos = ['Gobernación', 'Alcaldía', 'Ministerio', 'Empresa Pública', 'Empresa Privada', 'ONG', 'Comunidad Organizada', 'Otro'];
$puedeGestionar = puede('seguimiento', 'crear') || puede('seguimiento', 'editar');

include __DIR__ . '/../includes/header.php';
?>

<a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline btn-sm" style="margin-bottom:12px;"><i class="bi bi-arrow-left"></i> Volver</a>

<div class="split-grid cols-sidebar align-start">
<?php if ($puedeGestionar): ?>
<div class="card">
    <div class="card-header"><h2><i class="bi bi-building-add"></i> <?= $editEnte ? 'Editar ente' : 'Nuevo ente' ?></h2></div>
    <div class="card-body">
        <form method="post" action="<?= APP_URL_BASE ?>seguimiento/guardar_ente.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <?php if ($editEnte): ?><input type="hidden" name="id" value="<?= (int)$editEnte['id'] ?>"><?php endif; ?>
            <div class="field"><label class="req">Nombre del ente</label>
                <input required name="nombre" class="form-control" value="<?= e($editEnte['nombre'] ?? '') ?>"></div>
            <div class="field"><label>Tipo</label>
                <select name="tipo" class="form-control">
                    <?php foreach ($tipos as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($editEnte['tipo'] ?? 'Otro') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Estado de operación</label>
                <select name="estado" class="form-control">
                    <option value="">Nacional (todos los estados)</option>
                    <?php foreach (catalogoEstados() as $est): ?>
                        <?php if (!usuarioEsMaster() && $est !== estadoDelUsuario()) continue; ?>
                        <option value="<?= e($est) ?>" <?= ($editEnte['estado'] ?? '') === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid cols-2">
                <div class="field"><label>Contacto</label><input name="contacto_nombre" class="form-control" value="<?= e($editEnte['contacto_nombre'] ?? '') ?>"></div>
                <div class="field"><label>Teléfono</label><input name="contacto_telefono" class="form-control" value="<?= e($editEnte['contacto_telefono'] ?? '') ?>"></div>
            </div>
            <div class="field"><label>Correo</label><input type="email" name="contacto_email" class="form-control" value="<?= e($editEnte['contacto_email'] ?? '') ?>"></div>
            <div class="check-row" style="margin-bottom:12px;">
                <input type="checkbox" name="activo" id="ente-activo" value="1" <?= ($editEnte['activo'] ?? 1) ? 'checked' : '' ?>>
                <label for="ente-activo">Activo</label>
            </div>
            <div class="flex gap-8">
                <button class="btn btn-primary w-full" style="justify-content:center;"><i class="bi bi-save-fill"></i> <?= $editEnte ? 'Actualizar' : 'Registrar' ?></button>
                <?php if ($editEnte): ?><a href="<?= APP_URL_BASE ?>seguimiento/entes.php" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="bi bi-buildings"></i> Entes registrados (<?= count($entes) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Nombre</th><th>Tipo</th><th>Ámbito</th><th>Contacto</th><th>Estado</th><?php if ($puedeGestionar): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!$entes): ?>
                <tr><td colspan="6" class="text-muted text-sm">Aún no hay entes registrados.</td></tr>
            <?php else: foreach ($entes as $en): ?>
                <tr>
                    <td><strong><?= e($en['nombre']) ?></strong></td>
                    <td class="text-sm"><?= e($en['tipo']) ?></td>
                    <td class="text-sm"><?= $en['estado'] ? e($en['estado']) : '<span class="badge badge-verde">Nacional</span>' ?></td>
                    <td class="text-sm">
                        <?= e($en['contacto_nombre'] ?? '—') ?>
                        <?php if ($en['contacto_telefono']): ?><br><span class="text-muted"><?= e($en['contacto_telefono']) ?></span><?php endif; ?>
                    </td>
                    <td><?= $en['activo'] ? '<span class="badge badge-verde">Activo</span>' : '<span class="badge badge-rojo">Inactivo</span>' ?></td>
                    <?php if ($puedeGestionar): ?>
                    <td><a href="?id=<?= (int)$en['id'] ?>" class="btn btn-outline btn-sm"><i class="bi bi-pencil"></i></a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
