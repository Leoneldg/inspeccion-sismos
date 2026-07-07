<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('ingenieros', 'ver');

$pageTitle    = 'Ingenieros / Inspectores';
$pageSubtitle = 'Directorio de profesionales que pueden asignarse como responsables de una inspección';
$activeModule = 'ingenieros';

$pdo = db();

if (!tablaIngenierosExiste()) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>Este módulo requiere el esquema actualizado. Cargue <code>database/schema.sql</code>.</div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editIng = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM ingenieros WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editIng = $stmt->fetch();
}

$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(nombre_completo LIKE :q1 OR cedula LIKE :q2)';
    $params['q1'] = "%$q%";
    $params['q2'] = "%$q%";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM ingenieros $whereSql ORDER BY activo DESC, nombre_completo ASC");
$stmt->execute($params);
$ingenieros = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="split-grid cols-sidebar align-start">

<?php if (puede('ingenieros', $editIng ? 'editar' : 'crear')): ?>
<div class="card">
    <div class="card-header"><h2><i class="bi bi-person-vcard-fill"></i> <?= $editIng ? 'Editar profesional' : 'Nuevo profesional' ?></h2></div>
    <div class="card-body">
        <form method="post" action="<?= APP_URL_BASE ?>admin/guardar_ingeniero.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <?php if ($editIng): ?><input type="hidden" name="id" value="<?= (int)$editIng['id'] ?>"><?php endif; ?>

            <div class="field" style="margin-bottom:14px;text-align:center;">
                <?php if (!empty($editIng['foto'])): ?>
                    <img src="<?= APP_URL_BASE . e($editIng['foto']) ?>" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:50%;border:1px solid var(--gris-200);margin-bottom:8px;">
                <?php endif; ?>
                <label>Foto</label>
                <input type="file" name="foto" accept="image/*" class="form-control">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label class="req">Nombre completo</label>
                <input required name="nombre_completo" class="form-control" value="<?= e($editIng['nombre_completo'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label class="req">Cédula</label>
                <input required name="cedula" class="form-control" value="<?= e($editIng['cedula'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label>Teléfono</label>
                <input name="telefono" class="form-control" value="<?= e($editIng['telefono'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label>Profesión</label>
                <input name="profesion" class="form-control" placeholder="Ej: Ingeniero Civil, Arquitecto…" value="<?= e($editIng['profesion'] ?? '') ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label>N° de inscripción en el colegio de ingenieros (opcional)</label>
                <input name="colegio_inscripcion" class="form-control" value="<?= e($editIng['colegio_inscripcion'] ?? '') ?>">
            </div>
            <div class="check-row" style="margin-bottom:16px;">
                <input type="checkbox" name="activo" id="activo" value="1" <?= ($editIng['activo'] ?? 1) ? 'checked' : '' ?>>
                <label for="activo">Profesional activo (disponible en el formulario)</label>
            </div>

            <div class="flex gap-8">
                <button class="btn btn-primary w-full" style="justify-content:center;"><i class="bi bi-save-fill"></i> <?= $editIng ? 'Actualizar' : 'Guardar profesional' ?></button>
                <?php if ($editIng): ?><a href="<?= APP_URL_BASE ?>admin/ingenieros.php" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body text-muted text-sm">No tiene permisos para crear o editar profesionales.</div></div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:10px;">
        <h2><i class="bi bi-people-fill"></i> Profesionales registrados (<?= count($ingenieros) ?>)</h2>
        <form method="get" class="flex gap-8">
            <input type="text" name="q" class="form-control" style="width:220px;" placeholder="Buscar por nombre o cédula…" value="<?= e($q) ?>">
            <button class="btn btn-outline btn-sm"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th></th><th>Nombre</th><th>Cédula</th><th>Teléfono</th><th>Profesión</th><th>Colegio</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ingenieros as $i): ?>
                <tr>
                    <td>
                        <?php if (!empty($i['foto'])): ?>
                            <img src="<?= APP_URL_BASE . e($i['foto']) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <div style="width:36px;height:36px;border-radius:50%;background:var(--azul-100);color:var(--azul-700);display:flex;align-items:center;justify-content:center;"><i class="bi bi-person-fill"></i></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($i['nombre_completo']) ?></strong></td>
                    <td><?= e($i['cedula']) ?></td>
                    <td class="text-sm text-muted"><?= e($i['telefono'] ?: '—') ?></td>
                    <td class="text-sm text-muted"><?= e($i['profesion'] ?: '—') ?></td>
                    <td class="text-sm text-muted"><?= e($i['colegio_inscripcion'] ?: '—') ?></td>
                    <td><?= $i['activo'] ? '<span class="badge badge-verde">Activo</span>' : '<span class="badge badge-rojo">Inactivo</span>' ?></td>
                    <td>
                        <div class="flex gap-8">
                            <?php if (puede('ingenieros', 'editar')): ?>
                            <a href="?id=<?= (int)$i['id'] ?>" class="btn btn-outline btn-sm"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (puede('ingenieros', 'eliminar')): ?>
                            <form method="post" action="<?= APP_URL_BASE ?>admin/eliminar_ingeniero.php" onsubmit="return confirm('¿Eliminar este profesional? Las inspecciones que ya lo tengan asignado conservarán su nombre, cédula y demás datos tal como quedaron guardados.');">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ingenieros): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:24px;">Ningún profesional registrado todavía.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
