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
            // PNG sin transparencia: probamos JPEG (normalmente mucho más
            // liviano para fotos) y nos quedamos con lo que pese menos, para
            // no arriesgarnos a que en algún caso raro salga más pesado.
            // Aplanamos sobre fondo blanco primero: si quedó algún resto de
            // transparencia en los bordes (aunque no se haya detectado como
            // "con transparencia"), evita que salga con bordes oscuros.
            $planaParaJpeg = imagecreatetruecolor(imagesx($origen), imagesy($origen));
            imagefill($planaParaJpeg, 0, 0, imagecolorallocate($planaParaJpeg, 255, 255, 255));
            imagecopy($planaParaJpeg, $origen, 0, 0, 0, 0, imagesx($origen), imagesy($origen));

            $rutaJpegTmp = $rutaSinExt . '_tmp.jpg';
            imagejpeg($planaParaJpeg, $rutaJpegTmp, FOTO_CALIDAD_JPEG);
            imagedestroy($planaParaJpeg);
            imagepng($origen, $rutaAbsoluta, 6);
            clearstatcache();
            if (is_file($rutaJpegTmp) && filesize($rutaJpegTmp) < filesize($rutaAbsoluta)) {
                @unlink($rutaAbsoluta);
                $rutaJpegFinal = $rutaSinExt . '.jpg';
                rename($rutaJpegTmp, $rutaJpegFinal);
                $nombreFinal = basename($rutaJpegFinal);
            } else {
                @unlink($rutaJpegTmp);
            }
            $ok = true;
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
function guardarFotoInspeccion(int $inspeccionId, string $categoria, array $archivo): bool
{
    if (!tablaFotosExiste()) {
        return false; // instalación sin database/actualizacion_v2.sql aplicado
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($archivo['size'] > FOTO_MAX_BYTES) {
        return false;
    }
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FOTO_EXT_PERMITIDAS, true)) {
        return false;
    }
    // Verifica que el contenido sea realmente una imagen
    $info = @getimagesize($archivo['tmp_name']);
    if ($info === false) {
        return false;
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/' . $inspeccionId . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $nombreArchivo = $categoria . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        return false;
    }

    // Baja resolución/calidad para no inflar el servidor. Si por lo que sea
    // no se puede comprimir, se guarda igual la foto original: nunca se
    // pierde una subida por culpa de esto.
    $nombreArchivo = comprimirImagenEnDisco($destino, $ext);

    $rutaRelativa = 'uploads/inspecciones/' . $inspeccionId . '/' . $nombreArchivo;

    db()->prepare(
        'INSERT INTO inspeccion_fotos (inspeccion_id, categoria, ruta, nombre_original) VALUES (:i, :c, :r, :n)'
    )->execute([
        'i' => $inspeccionId,
        'c' => $categoria,
        'r' => $rutaRelativa,
        'n' => $archivo['name'],
    ]);

    return true;
}

/** Procesa todas las categorías de $_FILES['fotos'] para una inspección. */
function guardarFotosInspeccion(int $inspeccionId, array $filesField): void
{
    $agrupadas = normalizarArchivosSubidos($filesField);
    foreach ($agrupadas as $categoria => $archivos) {
        foreach ($archivos as $archivo) {
            guardarFotoInspeccion($inspeccionId, $categoria, $archivo);
        }
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
