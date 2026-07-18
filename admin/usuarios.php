<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('usuarios', 'ver');

$pageTitle    = 'Usuarios';
$pageSubtitle = 'Gestión de cuentas y asignación de roles';
$activeModule = 'usuarios';

$pdo = db();
$roles = $pdo->query('SELECT id, nombre FROM roles ORDER BY nombre')->fetchAll();

// Catálogo de entes para asignar al usuario.
$entes = [];
if (tablaEntesExiste()) {
    try {
        $entes = $pdo->query('SELECT id, nombre, tipo, estado FROM entes WHERE activo = 1 ORDER BY nombre')->fetchAll();
    } catch (Throwable $e) { $entes = []; }
}

$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editUser = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editUser = $stmt->fetch();
}

// Aislamiento por ente: un usuario de un ente solo ve los usuarios de su
// propio ente (o de su estado, si es Gobernación). El master ve todos.
$condUsuarios = [];
$paramsUsuarios = [];
$columnaEnteUsuarios = false;
try {
    $pdo->query('SELECT ente_id FROM usuarios LIMIT 1');
    $columnaEnteUsuarios = true;
} catch (Throwable $e) { $columnaEnteUsuarios = false; }

if ($columnaEnteUsuarios && !usuarioEsMaster() && enteDelUsuario() !== null) {
    if (usuarioEsGobernacion() && !empty($_SESSION['ente_estado'])) {
        // Gobernación: usuarios cuyos entes son de su estado.
        $condUsuarios[] = 'u.ente_id IN (SELECT id FROM entes WHERE estado = :ue_estado)';
        $paramsUsuarios['ue_estado'] = $_SESSION['ente_estado'];
    } else {
        $condUsuarios[] = 'u.ente_id = :ue_ente';
        $paramsUsuarios['ue_ente'] = enteDelUsuario();
    }
}
$whereUsuarios = $condUsuarios ? ('WHERE ' . implode(' AND ', $condUsuarios)) : '';

// ---- Panel de entes eliminado de este módulo ----
// Los indicadores de bases de datos por ente están disponibles
// en el módulo Sistema → Bases por ente (admin/entes_resumen.php).
$panelEntes    = null;
$globalTotales = null;

$sqlUsuarios = 'SELECT u.*, r.nombre AS rol_nombre, e.nombre AS ente_nombre
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                LEFT JOIN entes e ON e.id = u.ente_id
                ' . $whereUsuarios . '
                ORDER BY u.creado_en DESC';
try {
    $stmtU = $pdo->prepare($sqlUsuarios);
    $stmtU->execute($paramsUsuarios);
    $usuarios = $stmtU->fetchAll();
} catch (Throwable $e) {
    // Instalación sin ente_id todavía: listar sin la columna de ente.
    $usuarios = $pdo->query(
        'SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.creado_en DESC'
    )->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<?php // Panel de bases por ente eliminado — ver admin/entes_resumen.php ?>

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

            <!-- Alcance territorial (nacional) -->
            <div class="field" style="margin:6px 0 14px;padding-top:12px;border-top:1px solid var(--border,#e5e7eb);">
                <label style="font-weight:600;"><i class="bi bi-geo-alt-fill"></i> Alcance territorial</label>
                <div class="check-row" style="margin:8px 0;">
                    <input type="checkbox" name="es_master" id="es_master" value="1"
                           <?= ($editUser['es_master'] ?? 0) ? 'checked' : '' ?>
                           onchange="document.getElementById('campo-estado-asignado').style.display=this.checked?'none':'';document.getElementById('campo-parroquias').style.display=this.checked?'none':'';">
                    <label for="es_master">Usuario <strong>master</strong> (acceso nacional, todos los estados)</label>
                </div>
                <div id="campo-estado-asignado" style="<?= ($editUser['es_master'] ?? 0) ? 'display:none;' : '' ?>">
                    <label>Estado asignado</label>
                    <select name="estado_asignado" class="form-control">
                        <option value="">— Seleccione un estado —</option>
                        <?php foreach (catalogoEstados() as $est): ?>
                            <option value="<?= e($est) ?>" <?= ($editUser['estado_asignado'] ?? '') === $est ? 'selected' : '' ?>><?= e($est) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-sm text-muted" style="margin-top:4px;">El usuario solo verá inspecciones y seguimiento de este estado.</div>
                </div>

                <!-- Parroquias asignadas (rol Responsable de Parroquia) -->
                <div id="campo-parroquias" style="<?= ($editUser['es_master'] ?? 0) ? 'display:none;' : '' ?>margin-top:12px;">
                    <label>Parroquias asignadas <span class="text-sm text-muted">(opcional)</span></label>
                    <?php
                    $parrSel = array_filter(array_map('trim', explode(',', (string)($editUser['parroquias_asignadas'] ?? ''))));
                    $parrDisp = function_exists('parroquiasDeEstadoGeo') ? parroquiasDeEstadoGeo('Distrito Capital') : [];
                    if (!$parrDisp) {
                        $parrDisp = ['Altagracia','Antimano','Candelaria','Caricuao','Catedral','Coche',
                          'El Junquito','El Paraíso','El Recreo','El Valle','La Pastora','La Vega','Macarao',
                          'San Agustín','San Bernardino','San José','San Juan','San Pedro','Santa Rosalía',
                          'Santa Teresa','Sucre','23 de Enero'];
                    }
                    ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;max-height:190px;overflow-y:auto;padding:10px;background:#f7f9fd;border-radius:9px;">
                        <?php foreach ($parrDisp as $pa): ?>
                        <label class="check-row" style="flex:1 1 45%;min-width:150px;display:flex;align-items:center;gap:6px;font-size:13px;">
                            <input type="checkbox" name="parroquias_asignadas[]" value="<?= e($pa) ?>"
                                   <?= in_array($pa, $parrSel, true) ? 'checked' : '' ?>>
                            <span><?= e($pa) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-sm text-muted" style="margin-top:4px;">
                        Si marca una o varias, el usuario <strong>solo</strong> verá esas parroquias.
                        Déjelo vacío para que vea todo el estado.
                    </div>
                </div>
            </div>

            <!-- Ente al que pertenece el usuario -->
            <?php if ($entes !== null): ?>
            <div class="field" style="margin:6px 0 14px;padding-top:12px;border-top:1px solid var(--border,#e5e7eb);">
                <label style="font-weight:600;"><i class="bi bi-building"></i> Ente al que pertenece</label>
                <div class="text-sm text-muted" style="margin:2px 0 8px;">Sus inspecciones, inspectores y usuarios quedarán aislados dentro de este ente.</div>
                <select name="ente_id" id="sel-ente-usuario" class="form-control">
                    <option value="">— Sin ente —</option>
                    <?php foreach ($entes as $en): ?>
                        <option value="<?= (int)$en['id'] ?>" <?= (($editUser['ente_id'] ?? '') == $en['id']) ? 'selected' : '' ?>>
                            <?= e($en['tipo'] ?: $en['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (puede('seguimiento', 'crear') || puede('usuarios', 'crear')): ?>
                <div style="margin-top:8px;">
                    <a href="#" id="link-nuevo-ente" class="text-sm" style="color:var(--azul,#22366f);font-weight:600;text-decoration:none;">+ Crear un ente nuevo</a>
                </div>
                <!-- Mini-formulario para crear un ente sin salir de esta pantalla -->
                <div id="box-nuevo-ente" style="display:none;margin-top:10px;padding:12px;border:1px dashed var(--border,#cbd5e1);border-radius:8px;background:#f8fafc;">
                    <div class="field" style="margin-bottom:8px;">
                        <label class="text-sm">Nombre del ente</label>
                        <input type="text" id="nuevo-ente-nombre" class="form-control" placeholder="Ej: Alcaldía de Baruta">
                    </div>
                    <div class="flex gap-8" style="margin-bottom:8px;">
                        <div class="field" style="flex:1;">
                            <label class="text-sm">Tipo</label>
                            <select id="nuevo-ente-tipo" class="form-control">
                                <option value="Gobernación">Gobernación</option>
                                <option value="Alcaldía">Alcaldía</option>
                                <option value="Ministerio">Ministerio</option>
                                <option value="Empresa">Empresa</option>
                                <option value="Otro" selected>Otro</option>
                            </select>
                        </div>
                        <div class="field" style="flex:1;">
                            <label class="text-sm">Estado</label>
                            <select id="nuevo-ente-estado" class="form-control">
                                <option value="">Nacional</option>
                                <?php foreach (catalogoEstados() as $est): ?>
                                    <option value="<?= e($est) ?>"><?= e($est) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-8">
                        <button type="button" id="btn-guardar-ente" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Guardar ente</button>
                        <button type="button" id="btn-cancelar-ente" class="btn btn-outline btn-sm">Cancelar</button>
                    </div>
                    <div id="nuevo-ente-msg" class="text-sm" style="margin-top:6px;"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

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
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Ente</th><th>Alcance</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><strong><?= e($u['nombre_completo']) ?></strong><br><span class="text-sm text-muted"><?= e($u['email']) ?></span></td>
                    <td><?= e($u['usuario']) ?></td>
                    <td><span class="badge badge-gris"><?= e($u['rol_nombre']) ?></span></td>
                    <td><?= !empty($u['ente_nombre']) ? '<span class="badge badge-azul">' . e($u['ente_nombre']) . '</span>' : '<span class="text-sm text-muted">—</span>' ?></td>
                    <td><?= !empty($u['es_master'])
                            ? '<span class="badge badge-verde"><i class="bi bi-globe-americas"></i> Nacional</span>'
                            : ('<span class="badge badge-gris"><i class="bi bi-geo-alt"></i> ' . e($u['estado_asignado'] ?? 'Sin estado') . '</span>') ?></td>
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

<script>
(function () {
    const link = document.getElementById('link-nuevo-ente');
    if (!link) return;
    const box = document.getElementById('box-nuevo-ente');
    const sel = document.getElementById('sel-ente-usuario');
    const msg = document.getElementById('nuevo-ente-msg');
    const inpNombre = document.getElementById('nuevo-ente-nombre');
    const inpTipo = document.getElementById('nuevo-ente-tipo');
    const inpEstado = document.getElementById('nuevo-ente-estado');
    const CSRF = '<?= e(csrfToken()) ?>';

    link.addEventListener('click', function (e) { e.preventDefault(); box.style.display = box.style.display === 'none' ? '' : 'none'; if (box.style.display === '') inpNombre.focus(); });
    document.getElementById('btn-cancelar-ente').addEventListener('click', function () { box.style.display = 'none'; msg.textContent = ''; });

    document.getElementById('btn-guardar-ente').addEventListener('click', async function () {
        const nombre = inpNombre.value.trim();
        if (!nombre) { msg.style.color = '#a61c1c'; msg.textContent = 'Escriba el nombre del ente.'; return; }
        msg.style.color = 'var(--gris,#6b7280)'; msg.textContent = 'Guardando…';
        try {
            const fd = new FormData();
            fd.append('csrf', CSRF); fd.append('nombre', nombre);
            fd.append('tipo', inpTipo.value); fd.append('estado', inpEstado.value);
            const resp = await fetch('<?= APP_URL_BASE ?>admin/crear_ente_json.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await resp.json();
            if (!data.ok) { msg.style.color = '#a61c1c'; msg.textContent = data.error || 'No se pudo crear.'; return; }
            // Agregar al selector y seleccionarlo.
            const opt = document.createElement('option');
            opt.value = data.id;
            opt.textContent = data.tipo || data.nombre;
            opt.selected = true;
            sel.appendChild(opt);
            msg.style.color = '#1c6b3d';
            msg.textContent = data.existia ? 'Ese ente ya existía; se seleccionó.' : 'Ente creado y seleccionado.';
            inpNombre.value = '';
            setTimeout(() => { box.style.display = 'none'; msg.textContent = ''; }, 1200);
        } catch (err) {
            msg.style.color = '#a61c1c'; msg.textContent = 'Error de conexión.';
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
