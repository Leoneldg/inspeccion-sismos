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

/** Convierte valores tipo "2M", "8M", "512K" de php.ini a bytes. */
function return_bytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024; // no break
        case 'm': $num *= 1024; // no break
        case 'k': $num *= 1024;
    }
    return $num;
}

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

    // Traducir los errores de PHP a mensajes claros para el usuario.
    $errCode = (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_OK);
    if ($errCode !== UPLOAD_ERR_OK) {
        $limite = ini_get('upload_max_filesize');
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => "La foto es muy pesada para el servidor (límite actual: {$limite}). Tome la foto en calidad media o pida al administrador subir el límite.",
            UPLOAD_ERR_FORM_SIZE  => 'La foto excede el tamaño permitido por el formulario.',
            UPLOAD_ERR_PARTIAL    => 'La foto se subió a medias. Revise su conexión e intente de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Error del servidor: falta la carpeta temporal. Avise al administrador.',
            UPLOAD_ERR_CANT_WRITE => 'Error del servidor: no se pudo escribir en disco. Avise al administrador.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida. Avise al administrador.',
        ];
        jresp(false, $msgs[$errCode] ?? 'No se pudo subir la foto (código ' . $errCode . ').');
    }

    // Validar tipo y tamaño.
    $f = $_FILES['foto'];
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($f['tmp_name']) ?: '';
    if (!isset($permitidos[$mime])) jresp(false, 'Formato no válido (use JPG, PNG o WEBP).');
    // Tope real del servidor, para no prometer más de lo que PHP acepta.
    $topeBytes = min(12 * 1024 * 1024, (int)(ini_get('upload_max_filesize') ? return_bytes(ini_get('upload_max_filesize')) : 12 * 1024 * 1024));
    if ($f['size'] > $topeBytes) {
        jresp(false, 'La imagen supera el máximo permitido (' . round($topeBytes / 1048576, 1) . ' MB).');
    }

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
    recAuditar('foto_subida', null, null,
        'Nivel ' . $nivel . ' #' . $refId . ($parte ? ' · ' . $parte : '') . ' (' . round($f['size'] / 1024) . ' KB)');
    jresp(true, 'Foto guardada.', [
        'foto' => ['id' => $id, 'ruta' => APP_URL_BASE . $rutaRel, 'parte' => $parte, 'descripcion' => $desc],
    ]);

} catch (Throwable $e) {
    jresp(false, APP_DEBUG ? $e->getMessage() : 'Error al subir la foto.');
}
