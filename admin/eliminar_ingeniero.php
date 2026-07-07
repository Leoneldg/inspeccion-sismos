<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('ingenieros', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if ($id) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT nombre_completo, cedula FROM ingenieros WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $ing = $stmt->fetch();

    // Las inspecciones que ya tenían este ingeniero asignado conservan su
    // nombre/cédula/teléfono tal como quedaron guardados (son columnas de
    // texto aparte); solo se pierde la referencia (ing1_id/ing2_id vuelven
    // a NULL automáticamente por el ON DELETE SET NULL de la foreign key).
    if (!usuarioEsMaster()) {
        $chk = $pdo->prepare('SELECT estado FROM ingenieros WHERE id = :id');
        $chk->execute(['id' => $id]);
        $obj = $chk->fetch();
        if ($obj && ($obj['estado'] ?? null) !== null && ($obj['estado'] ?? null) !== estadoDelUsuario()) {
            flash('error', 'No puede eliminar profesionales de otro estado.');
            header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
            exit;
        }
    }
    $pdo->prepare('DELETE FROM ingenieros WHERE id = :id')->execute(['id' => $id]);
    registrarLog($_SESSION['user_id'], 'ingeniero_eliminado', $ing ? "{$ing['nombre_completo']} ({$ing['cedula']})" : "ID: $id");
    flash('success', 'Profesional eliminado.');
}

header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
exit;
