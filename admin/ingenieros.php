<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

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
// Aislamiento por ente: cada ente ve solo sus profesionales (Gobernación, los
// de su estado; master, todos). Solo si la columna ente_id existe.
$tieneEnteIng = false;
try { $pdo->query('SELECT ente_id FROM ingenieros LIMIT 1'); $tieneEnteIng = true; } catch (Throwable $e) { $tieneEnteIng = false; }
if ($tieneEnteIng) {
    aplicarScopeEnte($where, $params, 'ente_id', 'estado');
}

// Filtro por profesión (para saber cuántos hay de cada una).
$profesionFiltro = trim($_GET['profesion'] ?? '');
if ($profesionFiltro !== '') {
    $where[] = 'profesion = :prof';
    $params['prof'] = $profesionFiltro;
}

// Catálogo de profesiones para el selector y para el filtro.
$profesiones = catalogoProfesiones();
// Conteo de profesionales por profesión (respetando el mismo alcance de ente).
$condConteo = [];
$paramsConteo = [];
if ($tieneEnteIng) { aplicarScopeEnte($condConteo, $paramsConteo, 'ente_id', 'estado'); }
$whereConteo = $condConteo ? ('WHERE ' . implode(' AND ', $condConteo)) : '';
$conteoPorProfesion = [];
try {
    $stmtCp = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(profesion),''),'(Sin profesión)') AS prof, COUNT(*) AS n FROM ingenieros $whereConteo GROUP BY prof ORDER BY n DESC");
    $stmtCp->execute($paramsConteo);
    $conteoPorProfesion = $stmtCp->fetchAll();
} catch (Throwable $e) { $conteoPorProfesion = []; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM ingenieros $whereSql ORDER BY activo DESC, nombre_completo ASC");
$stmt->execute($params);
$ingenieros = $stmt->fetchAll();

// Catálogo de entes para asignar al profesional (aísla los datos por ente).
$entesIng = [];
if (tablaEntesExiste()) {
    try {
        // El master ve todos los entes; un usuario con ente ve los de su estado.
        if (usuarioEsMaster()) {
            $entesIng = $pdo->query('SELECT id, nombre, tipo, estado FROM entes WHERE activo = 1 ORDER BY nombre')->fetchAll();
        } elseif (!empty($_SESSION['ente_estado'])) {
            $st = $pdo->prepare('SELECT id, nombre, tipo, estado FROM entes WHERE activo = 1 AND estado = :e ORDER BY nombre');
            $st->execute(['e' => $_SESSION['ente_estado']]);
            $entesIng = $st->fetchAll();
        } elseif (!empty($_SESSION['ente_id'])) {
            $st = $pdo->prepare('SELECT id, nombre, tipo, estado FROM entes WHERE id = :id');
            $st->execute(['id' => $_SESSION['ente_id']]);
            $entesIng = $st->fetchAll();
        }
    } catch (Throwable $e) { $entesIng = []; }
}

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
                <?php
                $profActual = $editIng['profesion'] ?? '';
                // Lista fija de profesiones.
                $profesionesFijas = ['Ingeniero', 'Arquitecto', 'Bombero', 'Protección Civil', 'Psicólogo', 'Psiquiatra', 'Antropólogo', 'Politólogo'];
                // ¿La profesión guardada está en la lista fija? Si no, es "Otro".
                $profEsOtro = ($profActual !== '' && !in_array($profActual, $profesionesFijas, true));
                ?>
                <select name="profesion_sel" id="profesion-sel" class="form-control">
                    <option value="">— Seleccione una profesión —</option>
                    <?php foreach ($profesionesFijas as $pf): ?>
                        <option value="<?= e($pf) ?>" <?= ($profActual === $pf) ? 'selected' : '' ?>><?= e($pf) ?></option>
                    <?php endforeach; ?>
                    <option value="__otro__" <?= $profEsOtro ? 'selected' : '' ?>>Otro…</option>
                </select>
                <!-- Campo para escribir la profesión manualmente (aparece al elegir "Otro"). -->
                <input type="text" name="profesion_nueva" id="profesion-nueva" class="form-control"
                       style="margin-top:8px;<?= $profEsOtro ? '' : 'display:none;' ?>"
                       placeholder="Escriba la profesión"
                       value="<?= $profEsOtro ? e($profActual) : '' ?>">
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label>N° de inscripción en el colegio de ingenieros (opcional)</label>
                <input name="colegio_inscripcion" class="form-control" value="<?= e($editIng['colegio_inscripcion'] ?? '') ?>">
            </div>

            <?php if ($entesIng): ?>
            <div class="field" style="margin-bottom:14px;">
                <label><i class="bi bi-building"></i> Ente al que pertenece</label>
                <select name="ente_id" class="form-control">
                    <option value="">— Sin ente (visible solo para el administrador nacional) —</option>
                    <?php foreach ($entesIng as $en): ?>
                        <option value="<?= (int)$en['id'] ?>" <?= (($editIng['ente_id'] ?? '') == $en['id']) ? 'selected' : '' ?>>
                            <?= e($en['nombre']) ?><?= $en['estado'] ? ' — ' . e($en['estado']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="text-sm text-muted" style="margin-top:4px;">Determina qué ente y estado puede ver a este profesional en su directorio.</div>
            </div>
            <?php endif; ?>

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

    <?php if ($conteoPorProfesion): ?>
    <!-- Cuántos profesionales hay por profesión. Cada chip filtra la lista. -->
    <div style="padding:10px 16px;border-bottom:1px solid var(--border,#e5e7eb);display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <span class="text-sm text-muted" style="font-weight:600;">Por profesión:</span>
        <a href="?<?= $q !== '' ? 'q=' . urlencode($q) : '' ?>" class="badge <?= $profesionFiltro === '' ? 'badge-azul' : 'badge-gris' ?>" style="text-decoration:none;">Todas (<?= count($ingenieros) ?>)</a>
        <?php foreach ($conteoPorProfesion as $cp): ?>
            <?php $nombreProf = $cp['prof'] === '(Sin profesión)' ? '' : $cp['prof']; ?>
            <a href="?profesion=<?= urlencode($nombreProf) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
               class="badge <?= ($profesionFiltro === $nombreProf && $nombreProf !== '') ? 'badge-azul' : 'badge-gris' ?>"
               style="text-decoration:none;"><?= e($cp['prof']) ?> (<?= (int)$cp['n'] ?>)</a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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

<script>
(function () {
    const sel = document.getElementById('profesion-sel');
    const inp = document.getElementById('profesion-nueva');
    if (!sel || !inp) return;
    function actualizar() {
        if (sel.value === '__otro__') { inp.style.display = ''; inp.focus(); }
        else { inp.style.display = 'none'; inp.value = ''; }
    }
    sel.addEventListener('change', actualizar);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
