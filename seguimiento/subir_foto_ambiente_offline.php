<?php
/**
 * SUBIR FOTO DE UN AMBIENTE CREADO SIN CONEXIÓN.
 *
 * Cuando el técnico trabaja sin señal, los ambientes existen solo en su
 * teléfono con un identificador temporal. Al recuperar la conexión, el
 * apartamento se guarda primero (creando los ambientes reales) y luego
 * llegan estas fotos.
 *
 * Este endpoint busca el ambiente real que corresponde —por apartamento,
 * tipo y número— y le asocia la foto.
 *
 * Recibe: apartamento_id, etiqueta ("Habitación 2"), parte, foto
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jr(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Convierte tamaños de php.ini ("2M") a bytes. */
function bytes_ini(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $u = strtolower($v[strlen($v) - 1]);
    $n = (int)$v;
    switch ($u) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

try {
    requierePermiso('seguimiento', 'editar');

    $aptoId   = (int)($_POST['apartamento_id'] ?? 0);
    $etiqueta = trim($_POST['etiqueta'] ?? '');
    $parte    = trim($_POST['parte'] ?? '') ?: 'antes';

    if ($aptoId <= 0)      jr(false, 'Apartamento no válido.');
    if ($etiqueta === '')  jr(false, 'Falta indicar el ambiente.');

    // La etiqueta viene como "Habitación 2": se separa tipo y número.
    if (!preg_match('/^(.+?)\s+(\d+)$/u', $etiqueta, $m)) {
        jr(false, 'No se pudo identificar el ambiente: ' . $etiqueta);
    }
    $tipo   = trim($m[1]);
    $numero = (int)$m[2];

    // Buscar el ambiente real ya creado al sincronizar el apartamento.
    $st = db()->prepare(
        'SELECT id FROM rec_ambiente
          WHERE apartamento_id = :a AND tipo = :t AND numero = :n
          LIMIT 1'
    );
    $st->execute(['a' => $aptoId, 't' => $tipo, 'n' => $numero]);
    $ambienteId = (int)($st->fetchColumn() ?: 0);

    if ($ambienteId <= 0) {
        // El apartamento todavía no se ha sincronizado. Se pide reintentar
        // más tarde en vez de descartar la foto.
        jr(false, 'El ambiente aún no existe en el sistema. Se reintentará.',
           ['reintentar' => true]);
    }

    // --- Validar el archivo ---
    if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        jr(false, 'No llegó la foto.');
    }
    $err = (int)$_FILES['foto']['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $lim = ini_get('upload_max_filesize');
        $msgs = [
            UPLOAD_ERR_INI_SIZE  => "La foto supera el límite del servidor ({$lim}).",
            UPLOAD_ERR_PARTIAL   => 'La foto llegó incompleta.',
            UPLOAD_ERR_CANT_WRITE=> 'El servidor no pudo escribir en disco.',
        ];
        jr(false, $msgs[$err] ?? 'No se pudo subir la foto (código ' . $err . ').');
    }

    $f = $_FILES['foto'];
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($f['tmp_name']) ?: '';
    if (!isset($permitidos[$mime])) jr(false, 'Formato no válido (use JPG, PNG o WEBP).');

    $tope = min(12 * 1024 * 1024, bytes_ini((string)ini_get('upload_max_filesize')) ?: 12 * 1024 * 1024);
    if ($f['size'] > $tope) {
        jr(false, 'La imagen supera el máximo permitido (' . round($tope / 1048576, 1) . ' MB).');
    }

    // --- Guardar ---
    $rel = 'uploads/levantamiento/ambiente/' . $ambienteId;
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        jr(false, 'No se pudo preparar la carpeta de destino.');
    }

    $nombre  = 'f_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $permitidos[$mime];
    $rutaRel = $rel . '/' . $nombre;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
        jr(false, 'No se pudo guardar la imagen.');
    }

    $id = recGuardarFoto('ambiente', $ambienteId, $rutaRel, $parte, null);
    recAuditar('foto_offline_sincronizada', null, null,
        $etiqueta . ' · apto #' . $aptoId . ' (' . round($f['size'] / 1024) . ' KB)');

    jr(true, 'Foto asociada correctamente.', [
        'foto' => ['id' => $id, 'ruta' => APP_URL_BASE . $rutaRel, 'parte' => $parte],
        'ambiente_id' => $ambienteId,
    ]);

} catch (Throwable $e) {
    jr(false, APP_DEBUG ? $e->getMessage() : 'Error al asociar la foto.');
}
