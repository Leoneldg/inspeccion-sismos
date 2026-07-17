<?php
// Registra un reporte de inventario (stock actual de materiales).
// Solo quien tiene permiso VER en seguimiento (el responsable que reporta).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requireLogin();
$inspeccionId = (int)($_POST['inspeccion_id'] ?? 0);
$volver = APP_URL_BASE . 'seguimiento/ficha.php?inspeccion=' . $inspeccionId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.'); header('Location: ' . $volver); exit;
}
// Ver también puede reportar inventario (es la persona responsable en campo).
if (!puede('seguimiento', 'ver') && !puede('seguimiento', 'crear')) {
    flash('error', 'No tiene permisos.'); header('Location: ' . $volver); exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp) { flash('error', 'Edificación no encontrada.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit; }
if (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario()) {
    flash('error', 'No autorizado.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$obra = segObtenerOCrearObra($inspeccionId);
$obraId = (int)$obra['id'];
$pdo = db();

$matIds      = $_POST['inv_mat_id']       ?? [];
$restantes   = $_POST['inv_restante']     ?? [];
$metraje     = $_POST['inv_metraje']      ?? '';
$nota        = trim($_POST['inv_nota']    ?? '');
$metrajeVal  = $metraje !== '' ? (float)$metraje : null;

if (empty($matIds)) { flash('error', 'No hay materiales para reportar.'); header('Location: ' . $volver); exit; }

$insertados = 0;
foreach ($matIds as $k => $matId) {
    $matId = (int)$matId;
    $rest  = $restantes[$k] !== '' ? (float)$restantes[$k] : null;
    if ($rest === null || $matId <= 0) continue;

    // Obtener cantidad asignada para calcular la usada.
    $stMat = $pdo->prepare('SELECT cantidad_asignada FROM seguimiento_materiales WHERE id=:id AND obra_id=:o');
    $stMat->execute(['id'=>$matId,'o'=>$obraId]);
    $mat = $stMat->fetch();
    if (!$mat) continue;

    $usada = max(0, (float)$mat['cantidad_asignada'] - $rest);

    // Insertar el reporte en la bitácora de inventario.
    $pdo->prepare(
        'INSERT INTO seguimiento_inventario_reportes
           (material_id, obra_id, cantidad_restante, cantidad_usada, metraje_avance, nota, reportado_por)
         VALUES (:mid, :o, :rest, :usada, :met, :nota, :uid)'
    )->execute([
        'mid'  => $matId, 'o' => $obraId,
        'rest' => $rest,  'usada' => $usada,
        'met'  => $metrajeVal, 'nota' => $nota ?: null,
        'uid'  => $_SESSION['user_id'],
    ]);

    // Actualizar el stock actual del material.
    $pdo->prepare('UPDATE seguimiento_materiales SET cantidad_actual=:r WHERE id=:id')
        ->execute(['r' => $rest, 'id' => $matId]);

    $insertados++;
}

// Recalcular avance tras el reporte.
segRecalcularAvance($obraId);
$detalle = "Reporte de $insertados material(es)." . ($metrajeVal ? " Metraje completado: $metrajeVal." : '') . ($nota ? " Nota: $nota" : '');
segBitacora($obraId, 'reporte_inventario', $detalle);

flash('success', 'Reporte de inventario registrado. El avance fue recalculado.');
header('Location: ' . $volver); exit;
