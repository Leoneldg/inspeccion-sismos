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
$accion = $_POST['accion'] ?? 'subir';

try {
    if ($accion === 'eliminar') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        // Recuperar ruta para borrar el archivo, validando pertenencia
        $stmt = db()->prepare('SELECT ruta FROM seguimiento_fotos WHERE id = :id AND obra_id = :o');
        $stmt->execute(['id' => $fotoId, 'o' => $obraId]);
        $foto = $stmt->fetch();
        if ($foto) {
            $abs = dirname(__DIR__) . '/' . $foto['ruta'];
            if (is_file($abs)) @unlink($abs);
            db()->prepare('DELETE FROM seguimiento_fotos WHERE id = :id AND obra_id = :o')
                ->execute(['id' => $fotoId, 'o' => $obraId]);
            segBitacora($obraId, 'Foto eliminada', null);
            flash('success', 'Foto eliminada.');
        }
    } else {
        $fase  = $_POST['fase'] ?? 'Avance';
        if (!array_key_exists($fase, segFasesFoto())) $fase = 'Avance';
        $fecha = $_POST['fecha_registro'] ?? date('Y-m-d');
        if (!DateTime::createFromFormat('Y-m-d', $fecha)) $fecha = date('Y-m-d');
        $desc  = trim($_POST['descripcion'] ?? '');
        // El avance de la foto se toma del avance actual de la obra
        $avancePct = isset($obra['avance_pct']) ? (float)$obra['avance_pct'] : null;

        if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Seleccione una foto.'); header('Location: ' . $volver); exit;
        }
        $id = segGuardarFoto($obraId, $fase, $fecha, $_FILES['foto'], $desc, $avancePct);
        if ($id) {
            segBitacora($obraId, 'Foto de ' . strtolower($fase) . ' agregada', $desc ?: null);
            // Si la foto es de "Culminada", marcar la obra como culminada.
            if ($fase === 'Culminada' && $obra['estado_obra'] !== 'Culminada') {
                db()->prepare('UPDATE seguimiento_obras SET estado_obra = "Culminada", avance_pct = 100, fecha_fin_real = COALESCE(fecha_fin_real, CURDATE()) WHERE id = :o')
                    ->execute(['o' => $obraId]);
                segBitacora($obraId, 'Obra culminada', 'Registrada mediante foto de culminación.');
            }
            flash('success', 'Foto subida correctamente.');
        } else {
            flash('error', 'No se pudo subir la foto (formato/tamaño o permisos de carpeta).');
        }
    }
} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'Error al procesar la foto.');
}

header('Location: ' . $volver);
exit;
