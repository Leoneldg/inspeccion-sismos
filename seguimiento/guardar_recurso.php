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

$accion = $_POST['accion'] ?? '';

// Permisos por acción:
//  - consumo          → reportar (permiso 'ver'): sistematizador/ente/gobernante
//  - agregar/eliminar → gestionar (permiso 'crear')
$puedeGestionar = puede('seguimiento', 'crear');
$puedeReportar  = puede('seguimiento', 'ver') || $puedeGestionar;
if ($accion === 'consumo') {
    if (!$puedeReportar) { flash('error', 'No tiene permisos para reportar consumo.'); header('Location: ' . $volver); exit; }
} else {
    if (!$puedeGestionar) { flash('error', 'Solo un usuario con permiso de gestión puede modificar los recursos.'); header('Location: ' . $volver); exit; }
}

$insp = segInspeccion($inspeccionId);
if (!$insp || (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario())) {
    flash('error', 'No autorizado.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$obra = segObtenerOCrearObra($inspeccionId);
$obraId = (int)$obra['id'];

// Un usuario que pertenece a un ente solo puede tocar obras de su ente.
$miEnte = enteDelUsuario();
if ($miEnte !== null && !usuarioEsMaster() && $obra['ente_id'] !== null && (int)$obra['ente_id'] !== (int)$miEnte) {
    flash('error', 'No autorizado para este ente.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

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
        segRecalcularAvance($obraId);
        flash('success', 'Recurso agregado.');
    } elseif ($accion === 'eliminar') {
        $recursoId = (int)($_POST['recurso_id'] ?? 0);
        db()->prepare('DELETE FROM seguimiento_recursos WHERE id = :id AND obra_id = :o')
            ->execute(['id' => $recursoId, 'o' => $obraId]);
        segBitacora($obraId, 'Recurso eliminado', null);
        segRecalcularAvance($obraId);
        flash('success', 'Recurso eliminado.');
    } elseif ($accion === 'consumo') {
        // Reporte de consumo (estilo inventario): actualiza cuánto se ha
        // utilizado de un recurso. Dispara el recálculo del avance.
        $recursoId = (int)($_POST['recurso_id'] ?? 0);
        $usado = ($_POST['cantidad_utilizada'] ?? '') !== '' ? max(0, (float)$_POST['cantidad_utilizada']) : 0.0;
        $stmt = db()->prepare('SELECT recurso, unidad FROM seguimiento_recursos WHERE id = :id AND obra_id = :o');
        $stmt->execute(['id' => $recursoId, 'o' => $obraId]);
        $rec = $stmt->fetch();
        if (!$rec) { flash('error', 'Recurso no encontrado.'); header('Location: ' . $volver); exit; }
        db()->prepare('UPDATE seguimiento_recursos SET cantidad_utilizada = :u WHERE id = :id AND obra_id = :o')
            ->execute(['u' => $usado, 'id' => $recursoId, 'o' => $obraId]);
        $ud = $rec['unidad'] ? (' ' . $rec['unidad']) : '';
        segBitacora($obraId, 'Consumo reportado', $rec['recurso'] . ': ' . rtrim(rtrim(number_format($usado, 2), '0'), '.') . $ud);
        segRecalcularAvance($obraId);
        flash('success', 'Consumo actualizado. El avance se recalculó automáticamente.');
    }
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'No se pudo procesar el recurso.');
}

header('Location: ' . $volver);
exit;
