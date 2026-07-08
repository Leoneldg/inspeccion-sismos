<?php
// Elimina la ficha de seguimiento de una inspección.
// Solo el superadministrador (es_master) con permiso eliminar puede hacerlo.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}
// Solo el superadministrador (master) con permiso eliminar puede borrar fichas.
if (!usuarioEsMaster() || !puede('seguimiento', 'eliminar')) {
    flash('error', 'Solo el superadministrador puede eliminar fichas de seguimiento.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$inspeccionId = (int)($_POST['inspeccion_id'] ?? 0);
$motivo = trim($_POST['motivo'] ?? '');
if (!$inspeccionId) { flash('error', 'Inspección no válida.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit; }
if ($motivo === '') { flash('error', 'Debe indicar un motivo para la eliminación.'); header('Location: ' . APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . $inspeccionId); exit; }

$insp = segInspeccion($inspeccionId);
if (!$insp) { flash('error', 'Inspección no encontrada.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit; }

$pdo = db();
try {
    // Obtener la obra (si existe).
    $stOb = $pdo->prepare('SELECT id FROM seguimiento_obras WHERE inspeccion_id = :id');
    $stOb->execute(['id' => $inspeccionId]);
    $obra = $stOb->fetch();
    if ($obra) {
        $obraId = (int)$obra['id'];
        // Eliminar en cascada: inventario_reportes, materiales, recursos, fotos, bitácora, obra.
        // Las FK con ON DELETE CASCADE se encargan de inventario_reportes y materiales.
        $pdo->prepare('DELETE FROM seguimiento_fotos WHERE obra_id = ?')->execute([$obraId]);
        $pdo->prepare('DELETE FROM seguimiento_recursos WHERE obra_id = ?')->execute([$obraId]);
        $pdo->prepare('DELETE FROM seguimiento_bitacora WHERE obra_id = ?')->execute([$obraId]);
        $pdo->prepare('DELETE FROM seguimiento_obras WHERE id = ?')->execute([$obraId]);
    }
    registrarLog($_SESSION['user_id'], 'seguimiento_ficha_eliminada',
        "Inspección #{$inspeccionId} · {$insp['nombre_edificio']} · Motivo: $motivo");
    flash('success', 'Ficha de seguimiento eliminada correctamente.');
} catch (Throwable $e) {
    flash('error', 'Error al eliminar: ' . (APP_DEBUG ? $e->getMessage() : 'Intente de nuevo.'));
    header('Location: ' . APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . $inspeccionId); exit;
}
header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
