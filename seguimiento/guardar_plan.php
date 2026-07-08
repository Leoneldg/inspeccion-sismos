<?php
// Guarda el plan de acción: tipo de construcción, metraje y la lista de
// materiales asignados. Solo quien puede CREAR en seguimiento accede.
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
if (!puede('seguimiento', 'crear')) {
    flash('error', 'Solo quien elabora el plan de acción puede modificarlo.'); header('Location: ' . $volver); exit;
}

$insp = segInspeccion($inspeccionId);
if (!$insp) { flash('error', 'Edificación no encontrada.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit; }
if (!usuarioEsMaster() && ($insp['estado'] ?? null) !== estadoDelUsuario()) {
    flash('error', 'No autorizado.'); header('Location: ' . APP_URL_BASE . 'seguimiento/index.php'); exit;
}

$obra = segObtenerOCrearObra($inspeccionId);
$obraId = (int)$obra['id'];
$pdo = db();

// --- Datos del plan ---
$tipoConstruccion = trim($_POST['tipo_construccion'] ?? '');
$metrajeTotal     = $_POST['metraje_total'] !== '' ? (float)$_POST['metraje_total'] : null;
$metrajeUnidad    = trim($_POST['metraje_unidad'] ?? 'm²');

$pdo->prepare(
    'UPDATE seguimiento_obras SET tipo_construccion=:tc, metraje_total=:mt, metraje_unidad=:mu, actualizado_por=:u WHERE id=:o'
)->execute(['tc' => $tipoConstruccion ?: null, 'mt' => $metrajeTotal, 'mu' => $metrajeUnidad, 'u' => $_SESSION['user_id'], 'o' => $obraId]);

// --- Materiales del plan ---
// Primero borramos los que ya no están en el formulario (por id) y añadimos/actualizamos.
$idsEnviados = [];
$categorias  = $_POST['mat_categoria'] ?? [];
$subtipos    = $_POST['mat_subtipo']   ?? [];
$unidades    = $_POST['mat_unidad']    ?? [];
$cantidades  = $_POST['mat_cantidad']  ?? [];
$matIds      = $_POST['mat_id']        ?? [];

foreach ($categorias as $k => $cat) {
    $cat    = trim($cat);
    $sub    = trim($subtipos[$k] ?? '');
    $uni    = trim($unidades[$k] ?? 'und');
    $cant   = (float)($cantidades[$k] ?? 0);
    $matId  = (int)($matIds[$k] ?? 0);
    if ($cat === '') continue;

    if ($matId > 0) {
        // Actualizar existente (no tocar cantidad_actual — eso lo gestionan los reportes).
        $pdo->prepare(
            'UPDATE seguimiento_materiales SET categoria=:c, subtipo=:s, unidad=:u, cantidad_asignada=:q WHERE id=:id AND obra_id=:o'
        )->execute(['c'=>$cat,'s'=>$sub?:null,'u'=>$uni,'q'=>$cant,'id'=>$matId,'o'=>$obraId]);
        $idsEnviados[] = $matId;
    } else {
        $pdo->prepare(
            'INSERT INTO seguimiento_materiales (obra_id,categoria,subtipo,unidad,cantidad_asignada,cantidad_actual,creado_por)
             VALUES (:o,:c,:s,:u,:q,:q,:cp)'
        )->execute(['o'=>$obraId,'c'=>$cat,'s'=>$sub?:null,'u'=>$uni,'q'=>$cant,'cp'=>$_SESSION['user_id']]);
        $idsEnviados[] = (int)$pdo->lastInsertId();
    }
}
// Borrar los materiales que se quitaron del plan.
if ($idsEnviados) {
    $ph = implode(',', array_fill(0, count($idsEnviados), '?'));
    $stmt = $pdo->prepare("DELETE FROM seguimiento_materiales WHERE obra_id = ? AND id NOT IN ($ph)");
    $stmt->execute(array_merge([$obraId], $idsEnviados));
} else {
    $pdo->prepare('DELETE FROM seguimiento_materiales WHERE obra_id = ?')->execute([$obraId]);
}

// Recalcular avance tras cambio en el plan.
segRecalcularAvance($obraId);
segBitacora($obraId, 'plan_accion_actualizado', "Tipo: $tipoConstruccion | Metraje: $metrajeTotal $metrajeUnidad | " . count($idsEnviados) . " materiales");

flash('success', 'Plan de acción guardado.');
header('Location: ' . $volver); exit;
