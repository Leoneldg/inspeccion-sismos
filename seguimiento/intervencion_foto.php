<?php
/**
 * Reporte de intervención con foto, en UNA sola petición.
 *
 * En campo no hay señal estable: encadenar "crear reporte" y luego "subir
 * foto" significa que la cola offline pueda subir la foto de un reporte
 * que nunca se creó. Por eso este endpoint hace las dos cosas juntas:
 * abre (o reutiliza) el asiento del día para esa partida y fase, y le
 * cuelga la foto.
 *
 * POST (multipart):
 *   inspeccion   : id de la inspección
 *   nivel        : ambiente | area_comun | elemento_piso
 *   ref_id       : id del ambiente / área / elemento
 *   superficie   : pared | techo | piso | '' (clave natural de la partida)
 *   trabajo      : clave del tipo de trabajo (o '')
 *   fase         : durante | despues
 *   observaciones: (opcional) nota del día
 *   fecha        : (opcional) AAAA-MM-DD, para cargas atrasadas
 *   foto         : archivo (opcional: sin él solo se guarda la nota)
 *
 * Para borrar una foto: accion=eliminar_foto, foto_id
 * Para deshacer una fase: accion=deshacer, nivel, ref_id, superficie, trabajo, fase
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';
require_once __DIR__ . '/../includes/intervencion.php';

header('Content-Type: application/json; charset=utf-8');

function ifResp(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Convierte "8M" / "512K" de php.ini a bytes. */
function ifBytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $ultima = strtolower($val[strlen($val) - 1]);
    $n = (int)$val;
    switch ($ultima) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

try {
    requierePermiso('seguimiento', 'ver');

    // Reportar avance es exclusivo del sistematizador: es quien está en
    // la obra. La vista de Resultados no pasa por aquí.
    if (!esSistematizador()) {
        ifResp(false, 'Solo el sistematizador puede reportar la intervención.');
    }

    $accion = $_POST['accion'] ?? '';

    // ---------------- Borrar una foto suelta ----------------
    if ($accion === 'eliminar_foto') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        $st = db()->prepare("SELECT ruta, nivel FROM rec_foto WHERE id = :id");
        $st->execute(['id' => $fotoId]);
        $f = $st->fetch();
        if (!$f) ifResp(false, 'Esa foto ya no existe.');
        if ($f['nivel'] !== 'reporte_intervencion') {
            ifResp(false, 'Esa foto es del levantamiento, no de la intervención.');
        }
        $abs = dirname(__DIR__) . '/' . $f['ruta'];
        if (is_file($abs)) @unlink($abs);
        db()->prepare('DELETE FROM rec_foto WHERE id = :id')->execute(['id' => $fotoId]);
        recAuditar('intervencion_foto_borrada', null, null, 'Foto #' . $fotoId);
        ifResp(true, 'Foto eliminada.');
    }

    $nivel      = $_POST['nivel'] ?? '';
    $refId      = (int)($_POST['ref_id'] ?? 0);
    $superficie = $_POST['superficie'] ?? '';
    $trabajo    = $_POST['trabajo'] ?? '';
    $fase       = $_POST['fase'] ?? '';

    if (!in_array($nivel, intvNiveles(), true) || $refId <= 0) {
        ifResp(false, 'La partida indicada no es válida.');
    }
    if (!array_key_exists($fase, intvFases())) {
        ifResp(false, 'La fase indicada no es válida.');
    }

    // ---------------- Deshacer una fase ----------------
    if ($accion === 'deshacer') {
        $n = intvDeshacer($nivel, $refId, $superficie, $trabajo, $fase);
        ifResp(true, $n > 0 ? 'Reporte deshecho.' : 'No había nada que deshacer.');
    }

    // ---------------- Registrar ----------------
    $inspeccionId = (int)($_POST['inspeccion'] ?? 0);
    $ed = $inspeccionId > 0 ? recEdificio($inspeccionId) : [];
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) ifResp(false, 'No se encontró el levantamiento del edificio.');

    $fecha = trim($_POST['fecha'] ?? '');
    if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = '';

    $r = intvRegistrar(
        $edificioId, $nivel, $refId, $superficie, $trabajo, $fase,
        $_POST['observaciones'] ?? null, $fecha ?: null
    );
    $reporteId = (int)$r['reporte_id'];

    // Sin archivo: era solo la nota del día. Es válido.
    if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        ifResp(true, 'Reporte guardado.', ['reporte' => $r, 'foto' => null]);
    }

    // Errores de subida traducidos a algo accionable.
    $err = (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_OK);
    if ($err !== UPLOAD_ERR_OK) {
        $limite = ini_get('upload_max_filesize');
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => "La foto pesa más de lo que admite el servidor (límite: {$limite}). Tómela en calidad media.",
            UPLOAD_ERR_FORM_SIZE  => 'La foto excede el tamaño permitido.',
            UPLOAD_ERR_PARTIAL    => 'La foto se subió a medias. Revise la señal e intente de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal en el servidor. Avise al administrador.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir en disco. Avise al administrador.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida. Avise al administrador.',
        ];
        ifResp(false, $msgs[$err] ?? 'No se pudo subir la foto (código ' . $err . ').');
    }

    $f = $_FILES['foto'];
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($f['tmp_name']) ?: '';
    if (!isset($permitidos[$mime])) {
        $esHeic = stripos($mime, 'heic') !== false || stripos($mime, 'heif') !== false;
        ifResp(false, $esHeic
            ? 'La foto está en formato HEIC. En el iPhone: Ajustes → Cámara → Formatos → "Más compatible", y vuelva a tomarla.'
            : 'Formato no válido. Use JPG, PNG o WEBP.');
    }

    $tope = min(12 * 1024 * 1024,
        ini_get('upload_max_filesize') ? ifBytes(ini_get('upload_max_filesize')) : 12 * 1024 * 1024);
    if ($f['size'] > $tope) {
        ifResp(false, 'La imagen supera el máximo permitido (' . round($tope / 1048576, 1) . ' MB).');
    }

    $rel = 'uploads/intervencion/' . $reporteId;
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        ifResp(false, 'No se pudo preparar la carpeta de destino.');
    }

    $nombre  = 'i_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $permitidos[$mime];
    $rutaRel = $rel . '/' . $nombre;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
        ifResp(false, 'No se pudo guardar la imagen.');
    }

    $fotoId = recGuardarFoto('reporte_intervencion', $reporteId, $rutaRel, $fase,
        $superficie ? ($superficie . ' · ' . $trabajo) : $trabajo);

    recAuditar('intervencion_foto', null, $edificioId,
        ucfirst($fase) . ' · ' . $nivel . ' #' . $refId . ' (' . round($f['size'] / 1024) . ' KB)');

    ifResp(true, 'Reporte guardado.', [
        'reporte' => $r,
        'foto' => [
            'id'   => $fotoId,
            'ruta' => APP_URL_BASE . $rutaRel,
            'hora' => date('H:i'),
        ],
    ]);

} catch (Throwable $e) {
    ifResp(false, APP_DEBUG ? $e->getMessage() : 'No se pudo guardar el reporte.');
}
