<?php
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
if (!puede('seguimiento', 'editar') && !puede('seguimiento', 'crear')) {
    flash('error', 'No tiene permisos.'); header('Location: ' . $volver); exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp || (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario())) {
    flash('error', 'No autorizado.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$obra = segObtenerOCrearObra($inspeccionId);
$obraId = (int)$obra['id'];
$accion = $_POST['accion'] ?? '';

try {
    if ($accion === 'agregar') {
        $recurso  = trim($_POST['recurso'] ?? '');
        $unidad   = nullSiVacio(trim($_POST['unidad'] ?? ''));
        $cantidad = ($_POST['cantidad_estimada'] ?? '') !== '' ? (float)$_POST['cantidad_estimada'] : null;
        if ($recurso === '') { flash('error', 'Indique el recurso.'); header('Location: ' . $volver); exit; }
        db()->prepare(
            'INSERT INTO seguimiento_recursos (obra_id, recurso, unidad, cantidad_estimada, origen)
             VALUES (:o, :r, :u, :c, "Manual")'
        )->execute(['o' => $obraId, 'r' => $recurso, 'u' => $unidad, 'c' => $cantidad]);
        segBitacora($obraId, 'Recurso agregado', $recurso);
        flash('success', 'Recurso agregado.');
    } elseif ($accion === 'eliminar') {
        $recursoId = (int)($_POST['recurso_id'] ?? 0);
        // Solo borra si pertenece a esta obra (evita manipulación de IDs ajenos)
        db()->prepare('DELETE FROM seguimiento_recursos WHERE id = :id AND obra_id = :o')
            ->execute(['id' => $recursoId, 'o' => $obraId]);
        segBitacora($obraId, 'Recurso eliminado', null);
        flash('success', 'Recurso eliminado.');
    }
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'No se pudo procesar el recurso.');
}

header('Location: ' . $volver);
exit;
