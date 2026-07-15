<?php
/**
 * Borrado múltiple de inspecciones (limpieza de datos de prueba).
 * ------------------------------------------------------------------
 * Acción DESTRUCTIVA. Protecciones:
 *   - Solo superadministrador (master) con permiso 'eliminar'.
 *   - Requiere POST + token CSRF válido.
 *   - Requiere escribir la palabra de confirmación.
 *   - Registra en el log de actividad qué se borró y quién lo hizo.
 *
 * Las tablas dependientes (inspeccion_fotos, envios_formulario,
 * seguimiento_obras y las suyas) se borran solas por ON DELETE CASCADE.
 * Aquí, además, se eliminan del disco las carpetas de fotos.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requireLogin();

$volver = APP_URL_BASE . 'dashboard/limpiar_inspecciones.php';

// --- Validaciones de seguridad ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.');
    header('Location: ' . $volver); exit;
}
if (!usuarioEsMaster() || !puede('import_export', 'eliminar')) {
    flash('error', 'Solo el superadministrador puede borrar inspecciones.');
    header('Location: ' . $volver); exit;
}
if (strtoupper(trim($_POST['confirmacion'] ?? '')) !== 'BORRAR') {
    flash('error', 'Debe escribir BORRAR para confirmar la eliminación.');
    header('Location: ' . $volver); exit;
}

// --- IDs a borrar ---
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));

if (!$ids) {
    flash('error', 'No seleccionó ninguna inspección.');
    header('Location: ' . $volver); exit;
}

$pdo = db();

// Traer datos mínimos (para el log) antes de borrar.
$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, codigo, nombre_edificio, fecha_inspeccion FROM inspecciones WHERE id IN ($in)");
$stmt->execute($ids);
$aBorrar = $stmt->fetchAll();

if (!$aBorrar) {
    flash('error', 'Las inspecciones seleccionadas ya no existen.');
    header('Location: ' . $volver); exit;
}

$idsReales = array_column($aBorrar, 'id');
$borradas  = 0;

try {
    $pdo->beginTransaction();

    // Borrar los registros. Las tablas dependientes caen por ON DELETE CASCADE.
    $inReal = implode(',', array_fill(0, count($idsReales), '?'));
    $del = $pdo->prepare("DELETE FROM inspecciones WHERE id IN ($inReal)");
    $del->execute($idsReales);
    $borradas = $del->rowCount();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Error al borrar: ' . (APP_DEBUG ? $e->getMessage() : 'Intente de nuevo.'));
    header('Location: ' . $volver); exit;
}

// --- Borrar del disco las carpetas de fotos de cada inspección borrada ---
// (la base de datos no borra archivos físicos). Se hace después del commit.
foreach ($idsReales as $idInsp) {
    $dir = rtrim(UPLOAD_DIR, '/') . '/' . (int)$idInsp;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $archivo) {
            if (is_file($archivo)) @unlink($archivo);
        }
        @rmdir($dir);
    }
}

// --- Auditoría ---
$resumen = array_map(fn($r) => "#{$r['id']} {$r['codigo']} ({$r['fecha_inspeccion']})", $aBorrar);
registrarLog(
    $_SESSION['user_id'] ?? null,
    'inspecciones_borradas_masivo',
    $borradas . ' inspecciones eliminadas: ' . implode(', ', array_slice($resumen, 0, 60))
    . (count($resumen) > 60 ? ' …' : '')
);

flash('success', "Se eliminaron $borradas inspección(es) correctamente.");
header('Location: ' . $volver); exit;
