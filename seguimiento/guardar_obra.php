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
    flash('error', 'Solicitud inválida.');
    header('Location: ' . $volver); exit;
}
if (!puede('seguimiento', 'editar') && !puede('seguimiento', 'crear')) {
    flash('error', 'No tiene permisos para editar el seguimiento.');
    header('Location: ' . $volver); exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp) { flash('error', 'Edificación no encontrada.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit; }

// Scope: estadal solo su estado
if (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario()) {
    flash('error', 'No autorizado para este estado.');
    header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$obra = segObtenerOCrearObra($inspeccionId);
$obraId = (int)$obra['id'];

$estadoObraAnterior = $obra['estado_obra'];

// Sanitizar entradas
$enteId       = ($_POST['ente_id'] ?? '') !== '' ? (int)$_POST['ente_id'] : null;
$responsableId= ($_POST['responsable_id'] ?? '') !== '' ? (int)$_POST['responsable_id'] : null;
$fechaInicio  = nullSiVacio($_POST['fecha_inicio'] ?? '');
$fechaFinEst  = nullSiVacio($_POST['fecha_fin_estimada'] ?? '');
$fechaFinReal = nullSiVacio($_POST['fecha_fin_real'] ?? '');
$tiempoDias   = ($_POST['tiempo_accion_dias'] ?? '') !== '' ? (int)$_POST['tiempo_accion_dias'] : null;
$estadoObra   = $_POST['estado_obra'] ?? 'Sin iniciar';
$avancePct    = min(100, max(0, (float)($_POST['avance_pct'] ?? 0)));
$presupuesto  = ($_POST['presupuesto_estimado'] ?? '') !== '' ? (float)$_POST['presupuesto_estimado'] : null;
$prioridad    = $_POST['prioridad'] ?? 'Media';
$observaciones= nullSiVacio(trim($_POST['observaciones'] ?? ''));

// Validaciones de dominio
$estadosValidos = array_keys(segEstadosObra());
if (!in_array($estadoObra, $estadosValidos, true)) $estadoObra = 'Sin iniciar';
if (!in_array($prioridad, ['Alta', 'Media', 'Baja'], true)) $prioridad = 'Media';

// Regla: si se marca "Culminada", avance = 100 y se fija fecha fin real si falta.
if ($estadoObra === 'Culminada') {
    $avancePct = 100;
    if (!$fechaFinReal) $fechaFinReal = date('Y-m-d');
}

try {
    db()->prepare(
        'UPDATE seguimiento_obras SET
            ente_id = :ente, responsable_id = :resp,
            fecha_inicio = :fi, fecha_fin_estimada = :ffe, fecha_fin_real = :ffr,
            tiempo_accion_dias = :dias, estado_obra = :eo, avance_pct = :av,
            presupuesto_estimado = :pres, prioridad = :prio, observaciones = :obs,
            actualizado_por = :uid
         WHERE id = :id'
    )->execute([
        'ente' => $enteId, 'resp' => $responsableId,
        'fi' => $fechaInicio, 'ffe' => $fechaFinEst, 'ffr' => $fechaFinReal,
        'dias' => $tiempoDias, 'eo' => $estadoObra, 'av' => $avancePct,
        'pres' => $presupuesto, 'prio' => $prioridad, 'obs' => $observaciones,
        'uid' => $_SESSION['user_id'] ?? null, 'id' => $obraId,
    ]);

    // Bitácora de cambios relevantes
    if ($estadoObra !== $estadoObraAnterior) {
        segBitacora($obraId, 'Cambio de estado', "De \"$estadoObraAnterior\" a \"$estadoObra\".");
    }
    segBitacora($obraId, 'Plan de acción actualizado', 'Avance ' . round($avancePct) . '%.');

    registrarLog($_SESSION['user_id'], 'seguimiento_actualizado', "Obra ID: $obraId");
    flash('success', 'Plan de acción guardado correctamente.');
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'No se pudo guardar el plan de acción.');
}

header('Location: ' . $volver);
exit;
