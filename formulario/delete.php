<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('formulario', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare('DELETE FROM inspecciones WHERE id = :id');
    $stmt->execute(['id' => $id]);
    registrarLog($_SESSION['user_id'], 'inspeccion_eliminada', "ID: $id");

    // Limpia la carpeta de fotos en disco (los registros ya se eliminaron por CASCADE)
    $dirFotos = rtrim(UPLOAD_DIR, '/') . '/' . $id;
    if (is_dir($dirFotos)) {
        array_map('unlink', glob($dirFotos . '/*.*') ?: []);
        @rmdir($dirFotos);
    }

    flash('success', 'Inspección eliminada.');
}

header('Location: ' . APP_URL_BASE . 'formulario/index.php');
exit;
