<?php
/**
 * SISTEMATIZADORES — designa qué usuarios pueden cargar fotos del "durante"
 * y registrar el avance de la reconstrucción.
 *
 * Un usuario es sistematizador si tiene el rol "Sistematizador" o si se le
 * marca aquí explícitamente (útil para dar el permiso sin cambiarle el rol).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('usuarios', 'editar');

$pdo = db();
$mensaje = null;

// Asegurar la tabla.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rec_sistematizador (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id), UNIQUE KEY uq_sist_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// --- Guardar cambios ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $marcados = array_map('intval', (array)($_POST['sist'] ?? []));
    try {
        $pdo->exec("UPDATE rec_sistematizador SET activo = 0");
        if ($marcados) {
            $ins = $pdo->prepare(
                'INSERT INTO rec_sistematizador (user_id, activo) VALUES (:u, 1)
                 ON DUPLICATE KEY UPDATE activo = 1'
            );
            foreach ($marcados as $uid) {
                if ($uid > 0) $ins->execute(['u' => $uid]);
            }
        }
        $mensaje = count($marcados) . ' usuario(s) marcado(s) como sistematizador.';
        registrarLog($_SESSION['user_id'] ?? null, 'sistematizadores_actualizados', implode(',', $marcados));
    } catch (Throwable $e) {
        $mensaje = APP_DEBUG ? $e->getMessage() : 'No se pudieron guardar los cambios.';
    }
}

// --- Listar usuarios ---
$usuarios = $pdo->query("
    SELECT u.id, u.nombre_completo, u.usuario, u.activo,
           r.nombre AS rol_nombre,
           COALESCE(s.activo, 0) AS es_sist
      FROM usuarios u
      LEFT JOIN roles r ON r.id = u.rol_id
      LEFT JOIN rec_sistematizador s ON s.user_id = u.id
     WHERE u.activo = 1
     ORDER BY u.nombre_completo
")->fetchAll();

$pageTitle    = 'Sistematizadores';
$pageSubtitle = 'Quién puede registrar el avance de las obras';
$activeModule = 'usuarios';
include __DIR__ . '/../includes/header.php';
?>
<style>
.ss-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07); padding:18px 20px; }
.ss-fila { display:flex; align-items:center; gap:12px; padding:11px 6px; border-bottom:1px solid #f0f2f7; }
.ss-fila:last-child { border-bottom:0; }
.ss-fila:hover { background:#fafbfe; }
.ss-fila.marcada { background:#eef7f0; }
.ss-chip { font-size:10px; padding:2px 8px; border-radius:10px; background:#eef2fb; color:#2d4488; }
</style>

<?php if ($mensaje): ?>
<div class="alert alert-success" style="margin-bottom:14px;">
    <i class="bi bi-check-circle-fill"></i><div><?= e($mensaje) ?></div>
</div>
<?php endif; ?>

<div class="ss-card">
    <div style="background:#eef2fb;border-radius:9px;padding:12px 14px;margin-bottom:14px;">
        <strong style="color:#22366F;"><i class="bi bi-info-circle-fill"></i> ¿Qué hace un sistematizador?</strong>
        <div style="font-size:12px;color:#55617f;margin-top:4px;">
            Es quien sube las fotos del <em>durante</em> y mueve el porcentaje de avance de cada
            ambiente. Sin esta marca, el usuario ve la ficha pero no puede registrar avance.
            Los usuarios con rol <strong>Sistematizador</strong> lo son automáticamente.
        </div>
    </div>

    <form method="post">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
            <div style="font-weight:700;color:#22366F;">
                <i class="bi bi-person-check"></i> Usuarios activos (<?= count($usuarios) ?>)
            </div>
            <input type="text" id="ss-buscar" class="form-control" style="width:220px;"
                   placeholder="Buscar usuario…" oninput="filtrarUsuarios()">
        </div>

        <div id="ss-lista">
        <?php foreach ($usuarios as $u):
            $porRol = stripos($u['rol_nombre'] ?? '', 'sistematizador') !== false;
            $marcado = $porRol || (int)$u['es_sist'] === 1;
        ?>
        <div class="ss-fila <?= $marcado ? 'marcada' : '' ?>"
             data-nombre="<?= e(mb_strtolower($u['nombre_completo'] . ' ' . $u['usuario'], 'UTF-8')) ?>">
            <input type="checkbox" name="sist[]" value="<?= (int)$u['id'] ?>"
                   <?= $marcado ? 'checked' : '' ?>
                   <?= $porRol ? 'disabled title="Lo es por su rol"' : '' ?>
                   onchange="this.closest('.ss-fila').classList.toggle('marcada', this.checked)">
            <?php if ($porRol): ?>
            <input type="hidden" name="sist[]" value="<?= (int)$u['id'] ?>">
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#2a3140;font-size:14px;"><?= e($u['nombre_completo']) ?></div>
                <div style="font-size:11px;color:#767c94;">@<?= e($u['usuario']) ?></div>
            </div>
            <span class="ss-chip"><?= e($u['rol_nombre'] ?? 'Sin rol') ?></span>
            <?php if ($porRol): ?>
            <span class="ss-chip" style="background:#2E7D3218;color:#2E7D32;">Por su rol</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef0f5;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar cambios</button>
            <a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="btn btn-outline">Volver a usuarios</a>
        </div>
    </form>
</div>

<script>
function filtrarUsuarios() {
    const t = (document.getElementById('ss-buscar').value || '').toLowerCase().trim();
    document.querySelectorAll('.ss-fila').forEach(f => {
        f.style.display = (!t || (f.dataset.nombre || '').includes(t)) ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
