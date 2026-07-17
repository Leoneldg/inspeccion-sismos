<?php
/**
 * Sube (o elimina) una foto del levantamiento técnico del edificio.
 * Es polimórfico: la foto puede ser de un elemento de piso, un ambiente,
 * el edificio, etc. Responde en JSON. Lo consume levantamiento.php por AJAX.
 *
 * Parámetros POST (multipart):
 *   nivel     : edificio|piso|elemento_piso|apartamento|ambiente
 *   ref_id    : id del registro de ese nivel
 *   parte     : (opcional) qué parte, para reparaciones (pared, closet, techo…)
 *   descripcion (opcional)
 *   foto      : archivo
 * Para eliminar: accion=eliminar, foto_id
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jresp(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requireLogin();
    if (!puede('seguimiento', 'editar') && !puede('seguimiento', 'crear')) {
        jresp(false, 'No tiene permisos.');
    }

    $nivelesValidos = ['edificio','piso','elemento_piso','apartamento','ambiente'];

    // --- Eliminar ---
    if (($_POST['accion'] ?? '') === 'eliminar') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        $st = db()->prepare('SELECT ruta, parte FROM rec_foto WHERE id = :id');
        $st->execute(['id' => $fotoId]);
        $f = $st->fetch();
        if ($f) {
            // Las fotos del "Antes" (levantamiento) son inmutables: NO se borran.
            // Solo se pueden eliminar fotos del "durante" o "despues".
            $parte = $f['parte'] ?? '';
            if ($parte !== 'durante' && $parte !== 'despues') {
                jresp(false, 'Las fotos del levantamiento (Antes) no se pueden eliminar.');
            }
            $abs = dirname(__DIR__) . '/' . $f['ruta'];
            if (is_file($abs)) @unlink($abs);
            db()->prepare('DELETE FROM rec_foto WHERE id = :id')->execute(['id' => $fotoId]);
        }
        jresp(true, 'Foto eliminada.');
    }

    // --- Subir ---
    $nivel = $_POST['nivel'] ?? '';
    $refId = (int)($_POST['ref_id'] ?? 0);
    $parte = trim($_POST['parte'] ?? '') ?: null;
    $desc  = trim($_POST['descripcion'] ?? '') ?: null;

    if (!in_array($nivel, $nivelesValidos, true) || $refId <= 0) {
        jresp(false, 'Datos de la foto inválidos.');
    }
    if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        jresp(false, 'Seleccione una foto.');
    }

    // Validar tipo y tamaño.
    $f = $_FILES['foto'];
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($f['tmp_name']) ?: '';
    if (!isset($permitidos[$mime])) jresp(false, 'Formato no válido (use JPG, PNG o WEBP).');
    if ($f['size'] > 12 * 1024 * 1024) jresp(false, 'La imagen supera los 12 MB.');

    // Carpeta destino: uploads/levantamiento/{nivel}/{ref_id}/
    $rel = 'uploads/levantamiento/' . $nivel . '/' . $refId;
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        jresp(false, 'No se pudo preparar la carpeta de destino.');
    }

    $ext = $permitidos[$mime];
    $nombre = 'f_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $rutaRel = $rel . '/' . $nombre;
    $rutaAbs = $dir . '/' . $nombre;

    if (!move_uploaded_file($f['tmp_name'], $rutaAbs)) {
        jresp(false, 'No se pudo guardar la imagen.');
    }

    $id = recGuardarFoto($nivel, $refId, $rutaRel, $parte, $desc);
    jresp(true, 'Foto guardada.', [
        'foto' => ['id' => $id, 'ruta' => APP_URL_BASE . $rutaRel, 'parte' => $parte, 'descripcion' => $desc],
    ]);

} catch (Throwable $e) {
    jresp(false, APP_DEBUG ? $e->getMessage() : 'Error al subir la foto.');
}
