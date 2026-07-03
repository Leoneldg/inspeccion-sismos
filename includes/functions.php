<?php
/**
 * Funciones auxiliares de propósito general.
 */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function obtenerFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Genera el próximo código correlativo de inspección, ej: INS-2026-000123 */
function generarCodigoInspeccion(): string
{
    $anio = date('Y');
    $stmt = db()->query(
        "SELECT COUNT(*) AS total FROM inspecciones WHERE codigo LIKE 'INS-$anio-%'"
    );
    $total = (int)$stmt->fetch()['total'] + 1;
    return sprintf('INS-%s-%06d', $anio, $total);
}

/** Lista fija de las 22 parroquias del Municipio Libertador (Caracas) + otras del área metropolitana. */
function catalogoParroquias(): array
{
    return [
        'Antímano', 'Altagracia', 'Catedral', 'Caricuao', 'Coche', 'El Junquito',
        'El Paraíso', 'El Recreo', 'El Valle', 'La Candelaria', 'La Pastora',
        'La Vega', 'Macarao', 'San Agustín', 'San Bernardino', 'San José',
        'San Juan', 'San Pedro', 'Santa Rosalía', 'Santa Teresa', 'Sucre', '23 de Enero',
        'Chacao', 'Baruta', 'El Hatillo', 'Petare',
    ];
}

function catalogoUsoEdificacion(): array
{
    return ['Vivienda Unifamiliar', 'Vivienda Multifamiliar', 'Vivienda Popular', 'Comercial', 'Oficina', 'Educativo', 'Médico/Asistencial', 'Gubernamental', 'Industrial', 'Otro'];
}

function catalogoTipoEstructural(): array
{
    return ['Pórticos', 'Concreto Armado', 'Muros', 'Acero', 'Mampostería Estructural', 'Prefabricados', 'Mixto'];
}

function catalogoNivelDano(): array
{
    return [
        'I'   => 'I - Sin daño',
        'II'  => 'II - Leve',
        'III' => 'III - Moderado',
        'IV'  => 'IV - Severo',
        'V'   => 'V - Completo',
    ];
}

function catalogoDecisionFinal(): array
{
    return [
        'Edificación Inspeccionada - Acceso Permitido' => ['color' => '#22c55e', 'corto' => 'Acceso Permitido'],
        'Acceso Restringido - Precaución al Entrar'    => ['color' => '#eab308', 'corto' => 'Precaución al Entrar'],
        'Edificación Insegura - Acceso No Permitido'   => ['color' => '#ef4444', 'corto' => 'Acceso No Permitido'],
    ];
}

/** Elementos estructurales evaluados (usados también como categorías de fotos). */
function catalogoElementosEstructurales(): array
{
    return ['columna' => 'Columna', 'viga' => 'Viga', 'muro' => 'Muro', 'nodo' => 'Nodo / Conexión', 'losa' => 'Losa', 'mamposteria' => 'Mampostería'];
}

/** Elementos no estructurales evaluados (usados también como categorías de fotos). */
function catalogoElementosNoEstructurales(): array
{
    return ['paredes_tabiqueria' => 'Paredes / Tabiquería', 'escaleras' => 'Escaleras', 'tanques_balcones' => 'Tanques / Balcones', 'fachada' => 'Fachada / Cielo Raso / Antenas'];
}

/** Todas las categorías de registro fotográfico disponibles en el formulario. */
function catalogoCategoriasFoto(): array
{
    return array_merge(
        ['general' => 'Vista general de la edificación'],
        catalogoElementosEstructurales(),
        catalogoElementosNoEstructurales(),
        ['decision' => 'Etiqueta / cartel de decisión colocado']
    );
}

// ---------------------------------------------------------------------
// Registro fotográfico
// ---------------------------------------------------------------------
const FOTO_MAX_BYTES = 8 * 1024 * 1024; // 8 MB por imagen (antes de comprimir)
const FOTO_EXT_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp'];

// Compresión: bajamos resolución y calidad lo justo para que el registro
// fotográfico no infle la base de datos ni el disco del servidor, sin que
// se note a simple vista (no es para "destruir" la foto, solo aligerarla).
const FOTO_LADO_MAXIMO   = 1920; // px — nada se guarda más grande que esto por su lado mayor
const FOTO_CALIDAD_JPEG  = 78;   // 0-100 (78 es un buen punto medio calidad/peso)
const FOTO_CALIDAD_WEBP  = 78;   // 0-100

/**
 * Reorganiza el arreglo anidado de $_FILES['fotos'] (estructura PHP nativa)
 * en: [ categoria => [ ['name'=>,'type'=>,'tmp_name'=>,'error'=>,'size'=>], ... ] ]
 */
function normalizarArchivosSubidos(array $filesField): array
{
    $out = [];
    if (!isset($filesField['name']) || !is_array($filesField['name'])) {
        return $out;
    }
    foreach ($filesField['name'] as $categoria => $nombres) {
        foreach ($nombres as $i => $nombre) {
            if ($filesField['error'][$categoria][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[$categoria][] = [
                'name'     => $nombre,
                'type'     => $filesField['type'][$categoria][$i],
                'tmp_name' => $filesField['tmp_name'][$categoria][$i],
                'error'    => $filesField['error'][$categoria][$i],
                'size'     => $filesField['size'][$categoria][$i],
            ];
        }
    }
    return $out;
}

/** Verifica (con caché en memoria) si la tabla de fotos ya existe en la BD. */
function tablaFotosExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        $existe = (bool)db()->query("SHOW TABLES LIKE 'inspeccion_fotos'")->fetch();
    }
    return $existe;
}

/**
 * true si existe la tabla envios_formulario (deduplicación de envíos, usada
 * por el modo offline para no crear inspecciones duplicadas si un envío se
 * reintenta). Instalaciones sin database/actualizacion_v3.sql aplicado
 * simplemente no tienen deduplicación (se degrada de forma segura, igual
 * que tablaFotosExiste()).
 */
function tablaEnviosExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        $existe = (bool)db()->query("SHOW TABLES LIKE 'envios_formulario'")->fetch();
    }
    return $existe;
}

/**
 * Si este envío (identificado por un ID generado en el navegador) ya fue
 * procesado antes, devuelve el ID de la inspección resultante. Sirve para
 * que un reintento del modo offline (o un doble-clic, o un fetch() que
 * "pareció" fallar pero sí llegó a procesarse en el servidor) no cree una
 * inspección duplicada ni vuelva a subir las mismas fotos.
 */
function envioYaProcesado(?string $clientSubmissionId): ?int
{
    if (!$clientSubmissionId || !tablaEnviosExiste()) {
        return null;
    }
    $stmt = db()->prepare('SELECT inspeccion_id FROM envios_formulario WHERE client_submission_id = :id');
    $stmt->execute(['id' => $clientSubmissionId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['inspeccion_id'] : null;
}

/** Registra que un envío (client_submission_id) ya fue procesado, para deduplicar reintentos. */
function registrarEnvioProcesado(?string $clientSubmissionId, int $inspeccionId): void
{
    if (!$clientSubmissionId || !tablaEnviosExiste()) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO envios_formulario (client_submission_id, inspeccion_id) VALUES (:id, :insp)
             ON DUPLICATE KEY UPDATE inspeccion_id = inspeccion_id'
        )->execute(['id' => $clientSubmissionId, 'insp' => $inspeccionId]);
    } catch (Throwable $e) {
        // No interrumpir el guardado si esto falla (p. ej. condición de carrera con
        // el UNIQUE de client_submission_id): la deduplicación es un plus, no algo crítico.
    }
}

/**
 * Recomprime una imagen ya guardada en disco: la reescala si es más grande
 * de FOTO_LADO_MAXIMO por su lado mayor, corrige la rotación EXIF típica de
 * fotos de celular, y la reguarda con calidad reducida (JPEG/WEBP) o
 * compresión sin pérdida (PNG). Si algo falla o GD no está disponible,
 * deja el archivo original tal cual (nunca rompe la subida por esto).
 *
 * Devuelve el nombre de archivo final (puede cambiar de extensión si una
 * PNG sin transparencia se convierte a JPEG para pesar mucho menos).
 */
function comprimirImagenEnDisco(string $rutaAbsoluta, string $ext): string
{
    $nombreArchivo = basename($rutaAbsoluta);

    if (!function_exists('imagecreatetruecolor')) {
        return $nombreArchivo; // GD no disponible en este servidor: no tocamos el archivo
    }

    $ext = strtolower($ext);
    try {
        $info = @getimagesize($rutaAbsoluta);
        if ($info === false) {
            return $nombreArchivo;
        }
        [$anchoOriginal, $altoOriginal, $tipo] = $info;

        // Cargar la imagen según su tipo real (no confiar solo en la extensión del nombre)
        $origen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($rutaAbsoluta),
            IMAGETYPE_PNG  => @imagecreatefrompng($rutaAbsoluta),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($rutaAbsoluta) : false,
            default        => false,
        };
        if (!$origen) {
            return $nombreArchivo;
        }

        // Corrige la rotación si el celular guardó la foto "acostada" con
        // una bandera EXIF en vez de rotar los píxeles de verdad.
        if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {
            $exif = @exif_read_data($rutaAbsoluta);
            $orientacion = $exif['Orientation'] ?? 1;
            $origen = match ($orientacion) {
                3 => imagerotate($origen, 180, 0),
                6 => imagerotate($origen, -90, 0),
                8 => imagerotate($origen, 90, 0),
                default => $origen,
            };
            $anchoOriginal = imagesx($origen);
            $altoOriginal  = imagesy($origen);
        }

        // Reescalar si excede el lado máximo permitido
        $ladoMayor = max($anchoOriginal, $altoOriginal);
        if ($ladoMayor > FOTO_LADO_MAXIMO) {
            $factor = FOTO_LADO_MAXIMO / $ladoMayor;
            $anchoNuevo = max(1, (int)round($anchoOriginal * $factor));
            $altoNuevo  = max(1, (int)round($altoOriginal * $factor));

            $destino = imagecreatetruecolor($anchoNuevo, $altoNuevo);
            if ($tipo === IMAGETYPE_PNG || $tipo === IMAGETYPE_WEBP) {
                imagealphablending($destino, false);
                imagesavealpha($destino, true);
            }
            imagecopyresampled($destino, $origen, 0, 0, 0, 0, $anchoNuevo, $altoNuevo, $anchoOriginal, $altoOriginal);
            imagedestroy($origen);
            $origen = $destino;
        }

        // ¿La PNG tiene transparencia real? Si no, conviene pasarla a JPEG:
        // una "foto" guardada como PNG pesa varias veces más sin ganar nada.
        $tienenAlpha = false;
        if ($tipo === IMAGETYPE_PNG) {
            $tienenAlpha = detectarTransparenciaPng($origen);
        }

        $rutaSinExt = preg_replace('/\.[^.]+$/', '', $rutaAbsoluta);
        $nombreFinal = $nombreArchivo;
        $ok = false;

        if ($tipo === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            $ok = imagewebp($origen, $rutaAbsoluta, FOTO_CALIDAD_WEBP);
        } elseif ($tipo === IMAGETYPE_PNG && $tienenAlpha) {
            $ok = imagepng($origen, $rutaAbsoluta, 6); // 0-9, compresión sin pérdida
        } elseif ($tipo === IMAGETYPE_PNG) {
            // PNG sin transparencia: para una foto (no un screenshot/diseño),
            // JPEG es casi siempre mucho más liviano, así que convertimos
            // directo en vez de codificar en ambos formatos y comparar pesos
            // (eso duplicaba el trabajo de CPU por cada foto PNG). Aplanamos
            // sobre fondo blanco primero para evitar bordes oscuros si quedó
            // algún resto de transparencia que no se detectó como tal.
            $planaParaJpeg = imagecreatetruecolor(imagesx($origen), imagesy($origen));
            imagefill($planaParaJpeg, 0, 0, imagecolorallocate($planaParaJpeg, 255, 255, 255));
            imagecopy($planaParaJpeg, $origen, 0, 0, 0, 0, imagesx($origen), imagesy($origen));

            $rutaJpegFinal = $rutaSinExt . '.jpg';
            $ok = imagejpeg($planaParaJpeg, $rutaJpegFinal, FOTO_CALIDAD_JPEG);
            imagedestroy($planaParaJpeg);
            if ($ok) {
                @unlink($rutaAbsoluta);
                $nombreFinal = basename($rutaJpegFinal);
            }
        } else {
            // JPEG normal
            $ok = imagejpeg($origen, $rutaAbsoluta, FOTO_CALIDAD_JPEG);
        }

        imagedestroy($origen);
        return $ok ? $nombreFinal : $nombreArchivo;
    } catch (Throwable $e) {
        // Cualquier imprevisto: nos quedamos con el archivo tal cual se subió.
        return $nombreArchivo;
    }
}

/** true si la imagen GD tiene algún píxel con transparencia real (no 100% opaca). */
function detectarTransparenciaPng($imagen): bool
{
    $ancho = imagesx($imagen);
    $alto  = imagesy($imagen);
    // Muestreo por rejilla en vez de píxel a píxel: suficiente para decidir
    // y mucho más rápido en imágenes grandes.
    $pasoX = max(1, (int)($ancho / 60));
    $pasoY = max(1, (int)($alto / 60));
    for ($x = 0; $x < $ancho; $x += $pasoX) {
        for ($y = 0; $y < $alto; $y += $pasoY) {
            $rgba = imagecolorat($imagen, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F; // 0 = opaco, 127 = totalmente transparente en GD
            if ($alpha > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Valida, mueve a disco y registra en la base de datos una foto de inspección.
 * Devuelve true si se guardó correctamente.
 */
/**
 * Valida y mueve la foto a disco, y crea su registro en la base de datos
 * CON EL ARCHIVO ORIGINAL (sin comprimir todavía). Esto es intencional:
 * mover un archivo y hacer un INSERT es rápido (milisegundos); comprimir con
 * GD es lo que puede tardar segundos por foto. Separar ambos pasos permite
 * responder al usuario de inmediato y comprimir después, sin arriesgar la
 * subida (la foto ya quedó guardada y visible con su versión original).
 *
 * Devuelve el ID de la foto insertada, o null si no se guardó.
 */
function guardarFotoInspeccionRapido(int $inspeccionId, string $categoria, array $archivo): ?int
{
    $etiqueta = "[fotos] inspeccion=$inspeccionId categoria=$categoria nombre=" . ($archivo['name'] ?? '?');

    if (!tablaFotosExiste()) {
        error_log("$etiqueta -> FALLÓ: no existe la tabla inspeccion_fotos (falta database/actualizacion_v2.sql)");
        return null;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        error_log("$etiqueta -> FALLÓ: código de error de subida PHP = {$archivo['error']} (ver UPLOAD_ERR_* — 1/2 = excede upload_max_filesize/post_max_size, 6 = falta carpeta temporal, 7 = no se pudo escribir en disco temporal)");
        return null;
    }
    if ($archivo['size'] > FOTO_MAX_BYTES) {
        error_log("$etiqueta -> FALLÓ: pesa {$archivo['size']} bytes, supera el máximo permitido por la app (" . FOTO_MAX_BYTES . ' bytes)');
        return null;
    }
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FOTO_EXT_PERMITIDAS, true)) {
        error_log("$etiqueta -> FALLÓ: extensión \"$ext\" no permitida");
        return null;
    }
    // Verifica que el contenido sea realmente una imagen
    $info = @getimagesize($archivo['tmp_name']);
    if ($info === false) {
        error_log("$etiqueta -> FALLÓ: getimagesize() no pudo leer \"{$archivo['tmp_name']}\" como imagen (¿archivo temporal corrupto, incompleto, o ya no existe?)");
        return null;
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/' . $inspeccionId . '/';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log("$etiqueta -> FALLÓ: no se pudo crear el directorio \"$dir\" (revisar permisos del usuario que corre PHP-FPM sobre " . UPLOAD_DIR . ')');
            return null;
        }
    }
    if (!is_writable($dir)) {
        error_log("$etiqueta -> FALLÓ: el directorio \"$dir\" no tiene permiso de escritura para el usuario que corre PHP-FPM");
        return null;
    }

    $nombreArchivo = $categoria . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        $err = error_get_last();
        error_log("$etiqueta -> FALLÓ: move_uploaded_file() devolvió false al mover de \"{$archivo['tmp_name']}\" a \"$destino\". Último error PHP: " . ($err['message'] ?? 'ninguno'));
        return null;
    }

    $rutaRelativa = 'uploads/inspecciones/' . $inspeccionId . '/' . $nombreArchivo;

    $stmt = db()->prepare(
        'INSERT INTO inspeccion_fotos (inspeccion_id, categoria, ruta, nombre_original) VALUES (:i, :c, :r, :n)'
    );
    $stmt->execute([
        'i' => $inspeccionId,
        'c' => $categoria,
        'r' => $rutaRelativa,
        'n' => $archivo['name'],
    ]);

    return (int)db()->lastInsertId();
}

/**
 * Procesa todas las categorías de $_FILES['fotos'] para una inspección,
 * guardando cada archivo tal cual (sin comprimir). Devuelve los IDs de las
 * fotos insertadas, para poder comprimirlas después con comprimirFotoPorId().
 *
 * @return int[]
 */
function guardarFotosInspeccion(int $inspeccionId, array $filesField): array
{
    $ids = [];
    $agrupadas = normalizarArchivosSubidos($filesField);
    foreach ($agrupadas as $categoria => $archivos) {
        foreach ($archivos as $archivo) {
            $id = guardarFotoInspeccionRapido($inspeccionId, $categoria, $archivo);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
    }
    return $ids;
}

/**
 * Comprime en disco una foto ya guardada (ver guardarFotoInspeccionRapido)
 * y actualiza su ruta en la base de datos si el nombre de archivo cambió
 * (p. ej. una PNG que se convirtió a JPEG por pesar menos). Pensada para
 * llamarse DESPUÉS de responder al navegador (ver formulario/save.php),
 * para que la compresión nunca sea lo que haga lenta la respuesta.
 *
 * Si algo falla, no rompe nada: la foto original ya está guardada y visible
 * desde antes de llamar a esta función.
 */
function comprimirFotoPorId(int $fotoId): void
{
    if (!tablaFotosExiste()) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT ruta FROM inspeccion_fotos WHERE id = :id');
        $stmt->execute(['id' => $fotoId]);
        $foto = $stmt->fetch();
        if (!$foto) {
            return;
        }
        $rutaAbsoluta = __DIR__ . '/../' . $foto['ruta'];
        if (!is_file($rutaAbsoluta)) {
            return;
        }
        $extOriginal = strtolower(pathinfo($rutaAbsoluta, PATHINFO_EXTENSION));
        $nombreFinal = comprimirImagenEnDisco($rutaAbsoluta, $extOriginal);
        $nombreOriginal = basename($rutaAbsoluta);

        if ($nombreFinal !== $nombreOriginal) {
            $rutaNueva = preg_replace('/[^\/]+$/', $nombreFinal, $foto['ruta']);
            db()->prepare('UPDATE inspeccion_fotos SET ruta = :r WHERE id = :id')
                ->execute(['r' => $rutaNueva, 'id' => $fotoId]);
        }
    } catch (Throwable $e) {
        // Igual que antes: cualquier imprevisto en la compresión no debe
        // afectar la foto ya guardada.
    }
}

/** Elimina una foto (archivo + registro) si pertenece a la inspección indicada. */
function eliminarFotoInspeccion(int $fotoId, int $inspeccionId): void
{
    if (!tablaFotosExiste()) {
        return;
    }
    $stmt = db()->prepare('SELECT ruta FROM inspeccion_fotos WHERE id = :id AND inspeccion_id = :insp');
    $stmt->execute(['id' => $fotoId, 'insp' => $inspeccionId]);
    $foto = $stmt->fetch();
    if (!$foto) {
        return;
    }
    $rutaAbsoluta = __DIR__ . '/../' . $foto['ruta'];
    if (is_file($rutaAbsoluta)) {
        @unlink($rutaAbsoluta);
    }
    db()->prepare('DELETE FROM inspeccion_fotos WHERE id = :id')->execute(['id' => $fotoId]);
}

/** Devuelve las fotos de una inspección agrupadas por categoría. */
function obtenerFotosInspeccion(int $inspeccionId): array
{
    if (!tablaFotosExiste()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM inspeccion_fotos WHERE inspeccion_id = :id ORDER BY creado_en ASC');
    $stmt->execute(['id' => $inspeccionId]);
    $agrupadas = [];
    foreach ($stmt->fetchAll() as $f) {
        $agrupadas[$f['categoria']][] = $f;
    }
    return $agrupadas;
}

/** Convierte '' a null para columnas numéricas/opcionales antes de insertar. */
function nullSiVacio($v)
{
    if ($v === '' || $v === null) {
        return null;
    }
    return $v;
}

function intPost(string $key, $default = 0): int
{
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int)$_POST[$key] : $default;
}
