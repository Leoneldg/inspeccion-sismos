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
    // Se usa el MÁXIMO número correlativo existente + 1, NO COUNT(*), porque
    // si hay huecos en la secuencia (registros borrados o importados con
    // saltos) COUNT(*)+1 puede chocar con un código ya usado y romper el
    // guardado por "Duplicate entry". Con reintento defensivo por si dos
    // envíos casi simultáneos calcularan el mismo número.
    $stmt = db()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)), 0) AS max_num
         FROM inspecciones WHERE codigo LIKE :patron"
    );
    $stmt->execute(['patron' => "INS-$anio-%"]);
    $siguiente = (int)$stmt->fetch()['max_num'] + 1;

    // Blindaje adicional: si por lo que sea el código ya existiera, avanzar.
    for ($i = 0; $i < 50; $i++) {
        $codigo = sprintf('INS-%s-%06d', $anio, $siguiente);
        $chk = db()->prepare('SELECT 1 FROM inspecciones WHERE codigo = :c LIMIT 1');
        $chk->execute(['c' => $codigo]);
        if (!$chk->fetch()) {
            return $codigo;
        }
        $siguiente++;
    }
    // Último recurso: sufijo con marca de tiempo para no bloquear el guardado.
    return sprintf('INS-%s-%06d', $anio, $siguiente);
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
    return [
        'Casa', 'Quinta', 'Edificio Residencial', 'Edificio de Oficina', 'Edificio Gubernamental',
        'Escuela', 'Hospital', 'Clínica', 'Centro de Atención Médica (CDI)', 'Galpón',
        'Local Comercial', 'Centro Comercial',
    ];
}

/** Escala de riesgo A/B/C de la planilla FUNVISIS (Externo, Daño Moderado, Componentes). */
function catalogoNivelRiesgo(): array
{
    return [
        'A. Bajo'  => ['color' => '#2E7D32', 'corto' => 'Bajo — Acceso permitido'],
        'B. Medio' => ['color' => '#C9A227', 'corto' => 'Medio — Acceso restringido'],
        'C. Alto'  => ['color' => '#A61C1C', 'corto' => 'Alto — Acceso no permitido'],
    ];
}

/** Riesgo estructural por daño Severo/Completo (punto 3 de la planilla): solo dos estados posibles. */
function catalogoRiesgoSevero(): array
{
    return ['No hay' => 'No hay (N=0), continuar inspección', 'C. Alto' => 'C. Alto (N≥1)'];
}

/** Nivel de acceso a los miembros estructurales principales (punto 3 de la planilla). */
function catalogoAccesoMiembros(): array
{
    return ['Todos', 'Casi todos', 'Pocos', 'Ninguno'];
}

/** Tipos de elemento evaluados en el piso crítico (puntos 3 y 4 de la planilla). */
function catalogoElementosPisoCritico(): array
{
    return [
        'columna_union'         => 'Columna / Unión',
        'muro_concreto'         => 'Muro de concreto',
        'muro_mamposteria'      => 'Muro de mampostería',
        'viga_arriostramiento'  => 'Viga o elemento de arriostramiento',
    ];
}

/** Tipos de inspección detallada recomendada (punto 7 de la planilla). */
function catalogoInspeccionDetallada(): array
{
    return ['estructura' => 'Estructura', 'geologia' => 'Geología o Geotecnia', 'instalaciones' => 'Instalaciones'];
}

/** Medidas de prevención recomendadas (punto 7 de la planilla). */
function catalogoMedidasPrevencion(): array
{
    return [
        'acordonar'                 => 'Acordonar',
        'cerrar_calles'             => 'Cerrar calles',
        'apuntalar'                 => 'Apuntalar',
        'desconectar_gas'           => 'Desconectar gas',
        'desconectar_electricidad'  => 'Desconectar electricidad',
    ];
}

function catalogoTipoEstructural(): array
{
    return ['Pórticos', 'Muros', 'Dual (Pórticos y Muros)', 'Prefabricado', 'Mixto'];
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
        'Edificación Inspeccionada - Acceso Permitido' => ['color' => '#2E7D32', 'corto' => 'Acceso Permitido'],
        'Acceso Restringido - Precaución al Entrar'    => ['color' => '#C9A227', 'corto' => 'Precaución al Entrar'],
        'Edificación Insegura - Acceso No Permitido'   => ['color' => '#A61C1C', 'corto' => 'Acceso No Permitido'],
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
        ['foto_inspector' => 'Foto del inspector (tipo carnet)'],
        ['general' => 'Vista general de la edificación'],
        catalogoElementosEstructurales(),
        catalogoElementosNoEstructurales(),
        ['decision' => 'Etiqueta / cartel de decisión colocado']
    );
}

/**
 * Categorías de foto que son "una sola, no una galería": al subir una
 * nueva, se reemplaza automáticamente cualquier foto anterior de esa misma
 * categoría en la inspección, en vez de acumularse. Pensada para la foto
 * tipo carnet del inspector (siempre debe haber como máximo una vigente).
 */
const CATEGORIAS_FOTO_UNICA = ['foto_inspector'];

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

    // Categorías "de una sola foto" (p. ej. la del inspector): la nueva
    // reemplaza a cualquier anterior, en vez de acumularse en galería.
    // Se hace recién aquí, con el archivo nuevo ya guardado con éxito en
    // disco, para no arriesgarse a borrar la foto vigente si algo de lo de
    // arriba hubiera fallado.
    if (in_array($categoria, CATEGORIAS_FOTO_UNICA, true)) {
        $anteriores = db()->prepare('SELECT id FROM inspeccion_fotos WHERE inspeccion_id = :i AND categoria = :c');
        $anteriores->execute(['i' => $inspeccionId, 'c' => $categoria]);
        foreach ($anteriores->fetchAll() as $anterior) {
            eliminarFotoInspeccion((int)$anterior['id'], $inspeccionId);
        }
    }

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
 * Sistema de progreso "en vivo" para el guardado del formulario.
 *
 * Por qué archivos y no la sesión de PHP: mientras save.php está corriendo,
 * PHP mantiene la sesión BLOQUEADA para ese usuario (así evita corrupción si
 * dos pestañas escriben a la vez). Si el navegador intentara consultar el
 * progreso vía la sesión mientras save.php sigue trabajando, esa consulta
 * quedaría congelada esperando a que save.php termine — literalmente lo
 * opuesto de lo que queremos. Por eso el progreso se guarda en un archivo
 * aparte, identificado por el mismo client_submission_id que ya viaja en el
 * formulario, sin relación con la sesión.
 */
define('PROGRESO_DIR', __DIR__ . '/../storage/progreso/');

function progresoRuta(?string $token): ?string
{
    if (!$token) {
        return null;
    }
    $token = preg_replace('/[^a-zA-Z0-9\-]/', '', $token);
    if ($token === '') {
        return null;
    }
    if (!is_dir(PROGRESO_DIR)) {
        @mkdir(PROGRESO_DIR, 0775, true);
    }
    return PROGRESO_DIR . $token . '.json';
}

/** Crea el progreso inicial de un envío, con la lista de pasos en estado "pendiente". */
function progresoIniciar(?string $token, array $pasos): void
{
    $ruta = progresoRuta($token);
    if (!$ruta) {
        return;
    }
    @file_put_contents($ruta, json_encode(['pasos' => $pasos, 'actualizado' => time()]), LOCK_EX);

    // Limpieza oportunista de progresos viejos (huérfanos por envíos que
    // nunca terminaron, pestañas cerradas, etc.), sin necesidad de un cron.
    if (mt_rand(1, 20) === 1) {
        foreach (glob(PROGRESO_DIR . '*.json') ?: [] as $f) {
            if (@filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}

/** Marca un paso puntual como 'en_progreso' o 'listo', opcionalmente cambiando su texto. */
function progresoActualizar(?string $token, string $clave, string $estado, ?string $texto = null): void
{
    $ruta = progresoRuta($token);
    if (!$ruta || !is_file($ruta)) {
        return;
    }
    $data = json_decode((string)@file_get_contents($ruta), true);
    if (!$data || empty($data['pasos'])) {
        return;
    }
    foreach ($data['pasos'] as &$p) {
        if ($p['clave'] === $clave) {
            $p['estado'] = $estado;
            if ($texto !== null) {
                $p['texto'] = $texto;
            }
        }
    }
    unset($p);
    $data['actualizado'] = time();
    @file_put_contents($ruta, json_encode($data), LOCK_EX);
}

/** Suma 1 al contador de fotos ya guardadas del paso 'fotos' y actualiza su texto. */
function progresoIncrementarFotos(?string $token): void
{
    $ruta = progresoRuta($token);
    if (!$ruta || !is_file($ruta)) {
        return;
    }
    $data = json_decode((string)@file_get_contents($ruta), true);
    if (!$data || empty($data['pasos'])) {
        return;
    }
    foreach ($data['pasos'] as &$p) {
        if ($p['clave'] === 'fotos') {
            $p['hechas'] = ($p['hechas'] ?? 0) + 1;
            $total = $p['total'] ?? $p['hechas'];
            $p['texto'] = "Guardando fotos ({$p['hechas']} de $total)";
            $p['estado'] = $p['hechas'] >= $total ? 'listo' : 'en_progreso';
        }
    }
    unset($p);
    $data['actualizado'] = time();
    @file_put_contents($ruta, json_encode($data), LOCK_EX);
}

/** Lee el progreso actual de un envío. Devuelve null si no existe (aún no arrancó o ya se limpió). */
function progresoLeer(?string $token): ?array
{
    $ruta = progresoRuta($token);
    if (!$ruta || !is_file($ruta)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($ruta), true);
    return $data ?: null;
}

/** Cuenta cuántos archivos hay en total dentro de $_FILES['fotos'][categoria][], sumando todas las categorías. */
function contarArchivosSubidos(array $filesField): int
{
    $total = 0;
    foreach (normalizarArchivosSubidos($filesField) as $archivos) {
        foreach ($archivos as $archivo) {
            if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $total++;
            }
        }
    }
    return $total;
}

/**
 * Procesa todas las categorías de $_FILES['fotos'] para una inspección,
 * guardando cada archivo tal cual (sin comprimir). Devuelve los IDs de las
 * fotos insertadas, para poder comprimirlas después con comprimirFotoPorId().
 *
 * @return int[]
 */
function guardarFotosInspeccion(int $inspeccionId, array $filesField, ?string $progresoToken = null): array
{
    $ids = [];
    $agrupadas = normalizarArchivosSubidos($filesField);
    foreach ($agrupadas as $categoria => $archivos) {
        foreach ($archivos as $archivo) {
            $id = guardarFotoInspeccionRapido($inspeccionId, $categoria, $archivo);
            if ($id !== null) {
                $ids[] = $id;
            }
            progresoIncrementarFotos($progresoToken);
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

    // Las categorías se reordenan según la secuencia lógica del formulario
    // (catalogoCategoriasFoto), no según el orden en que se subieron las
    // fotos -- si no, el orden de las secciones en la ficha técnica queda
    // dependiendo de cuál se subió primero, en vez de ser siempre igual.
    $ordenCanonico = array_keys(catalogoCategoriasFoto());
    uksort($agrupadas, function ($a, $b) use ($ordenCanonico) {
        $posA = array_search($a, $ordenCanonico);
        $posB = array_search($b, $ordenCanonico);
        $posA = $posA === false ? PHP_INT_MAX : $posA;
        $posB = $posB === false ? PHP_INT_MAX : $posB;
        return $posA <=> $posB;
    });

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

/**
 * URL absoluta (con esquema y host) para un path relativo a APP_URL_BASE.
 * Se usa para el contenido de los códigos QR: un QR con una ruta relativa
 * no serviría de nada al escanearlo desde el celular de un inspector.
 */
function urlAbsoluta(string $path): string
{
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $esquema . '://' . $host . APP_URL_BASE . ltrim($path, '/');
}

/**
 * Token corto y determinístico para acceder al PDF de una inspección sin
 * sesión iniciada (usado en el enlace codificado en el QR). Se deriva por
 * HMAC del id + APP_QR_SECRET: nadie puede calcular el token de otro id sin
 * conocer la clave del servidor, pero tampoco hace falta guardar nada en la
 * base de datos.
 */
function tokenPdfPublico(int $id): string
{
    return substr(hash_hmac('sha256', (string)$id, APP_QR_SECRET), 0, 24);
}

/**
 * Valida y guarda la foto de un ingeniero/inspector (directorio de
 * profesionales). Reutiliza las mismas validaciones que las fotos de
 * inspección (extensión, tamaño, que sea una imagen real), pero se
 * guarda en su propia carpeta (uploads/ingenieros/) y no crea ninguna
 * fila en inspeccion_fotos -- la ruta se guarda directo en
 * ingenieros.foto. Devuelve la ruta relativa guardada, o null si no
 * había archivo o algo falló.
 */
function guardarFotoIngeniero(int $ingenieroId, array $archivo): ?string
{
    if (empty($archivo['name']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: código de error de subida PHP = {$archivo['error']}");
        return null;
    }
    if ($archivo['size'] > FOTO_MAX_BYTES) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: pesa {$archivo['size']} bytes, supera el máximo permitido");
        return null;
    }
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FOTO_EXT_PERMITIDAS, true)) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: extensión \"$ext\" no permitida");
        return null;
    }
    if (@getimagesize($archivo['tmp_name']) === false) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: el archivo no es una imagen válida");
        return null;
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/../ingenieros/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: no se pudo crear el directorio \"$dir\"");
        return null;
    }

    $nombreArchivo = 'ing_' . $ingenieroId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . $nombreArchivo;
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        error_log("[foto-ingeniero] id=$ingenieroId -> FALLÓ: move_uploaded_file() devolvió false");
        return null;
    }

    return 'uploads/ingenieros/' . $nombreArchivo;
}

/** ¿Existe ya la tabla ingenieros? (por si no se ha corrido la migración v6). */
function tablaIngenierosExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            db()->query('SELECT 1 FROM ingenieros LIMIT 1');
            $existe = true;
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** Ingenieros/inspectores activos, para el selector del formulario. */
function obtenerIngenierosActivos(): array
{
    try {
        $cond = ['activo = 1'];
        $params = [];
        // Aislamiento por ente: cada ente usa sus propios profesionales.
        $tieneEnte = false;
        try { db()->query('SELECT ente_id FROM ingenieros LIMIT 1'); $tieneEnte = true; } catch (Throwable $e) {}
        if ($tieneEnte && function_exists('aplicarScopeEnte')) {
            aplicarScopeEnte($cond, $params, 'ente_id', 'estado');
        }
        $where = 'WHERE ' . implode(' AND ', $cond);
        $st = db()->prepare("SELECT id, nombre_completo, cedula, telefono, profesion, colegio_inscripcion FROM ingenieros $where ORDER BY nombre_completo ASC");
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return []; // tabla aún no existe (falta correr la actualización)
    }
}

function intPost(string $key, $default = 0): int
{
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int)$_POST[$key] : $default;
}

// ---------------------------------------------------------------------
// Configuración del panel (Superadministrador): secciones del formulario
// y widgets del dashboard. Se guarda en la tabla panel_config (clave/valor
// JSON genérico) para no tener que tocar el esquema cada vez que se agregue
// una opción de personalización nueva.
// ---------------------------------------------------------------------

/** ¿Existe ya la tabla panel_config? (por si no se ha corrido la migración v5). */
function tablaPanelConfigExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            db()->query('SELECT 1 FROM panel_config LIMIT 1');
            $existe = true;
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** ¿Existe una columna dada en la tabla inspecciones? (para migraciones opcionales). */
function columnaInspeccionExiste(string $columna): bool
{
    static $cache = [];
    if (!array_key_exists($columna, $cache)) {
        try {
            db()->query('SELECT `' . str_replace('`', '', $columna) . '` FROM inspecciones LIMIT 1');
            $cache[$columna] = true;
        } catch (Throwable $e) {
            $cache[$columna] = false;
        }
    }
    return $cache[$columna];
}

/** ¿Existe la tabla de entes? */
function tablaEntesExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            db()->query('SELECT 1 FROM entes LIMIT 1');
            $existe = true;
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** ¿Existe la tabla de catálogo de profesiones? */
function tablaProfesionesExiste(): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            db()->query('SELECT 1 FROM profesiones LIMIT 1');
            $existe = true;
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** Lista de profesiones del catálogo (ordenadas). */
function catalogoProfesiones(): array
{
    if (!tablaProfesionesExiste()) return [];
    try {
        return db()->query('SELECT nombre FROM profesiones ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Registra una profesión en el catálogo si no existe. Devuelve el nombre normalizado. */
function registrarProfesion(?string $nombre): ?string
{
    $nombre = trim((string)$nombre);
    if ($nombre === '' || !tablaProfesionesExiste()) return $nombre ?: null;
    try {
        $st = db()->prepare('INSERT IGNORE INTO profesiones (nombre) VALUES (:n)');
        $st->execute(['n' => $nombre]);
    } catch (Throwable $e) { /* ignorar duplicados */ }
    return $nombre;
}

/** Lee un valor de panel_config ya decodificado, o null si no existe. */
function obtenerConfigValor(string $clave)
{
    if (!tablaPanelConfigExiste()) {
        return null;
    }
    $stmt = db()->prepare('SELECT valor FROM panel_config WHERE clave = :clave');
    $stmt->execute(['clave' => $clave]);
    $row = $stmt->fetch();
    return $row ? json_decode($row['valor'], true) : null;
}

/** Guarda (crea o actualiza) un valor de panel_config. */
function guardarConfigValor(string $clave, $valor, ?int $usuarioId): void
{
    $json = json_encode($valor, JSON_UNESCAPED_UNICODE);
    db()->prepare(
        'INSERT INTO panel_config (clave, valor, actualizado_por) VALUES (:clave, :valor, :uid)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
    )->execute(['clave' => $clave, 'valor' => $json, 'uid' => $usuarioId]);
}

/** Secciones opcionales del formulario que el Superadministrador puede activar/desactivar. */
function catalogoSeccionesFormulario(): array
{
    return [
        'anio_personas'              => 'Año de construcción y N° de personas (general)',
        'materiales_extendidos'      => 'Materiales extendidos (Concreto, Mampostería formal/informal)',
        'riesgo_externo'             => 'Riesgo Externo calculado (A/B/C)',
        'piso_critico'               => 'Piso crítico y elementos con daño Severo/Completo',
        'dano_moderado_piso_critico' => 'Tabla de elementos con daño Moderado en el piso crítico',
        'riesgo_componentes'         => 'Riesgo de Componentes no estructurales',
        'acciones_recomendadas'      => 'Acciones recomendadas (Inspección Detallada y Medidas de Prevención)',
    ];
}

/** Estado activo/inactivo de cada sección opcional del formulario (con defaults = true). */
function obtenerConfigFormulario(): array
{
    $defaults = array_fill_keys(array_keys(catalogoSeccionesFormulario()), true);
    $guardado = obtenerConfigValor('formulario_secciones');
    return is_array($guardado) ? array_merge($defaults, $guardado) : $defaults;
}

/**
 * Lista blanca de columnas de "inspecciones" habilitadas para construir KPIs
 * personalizados en el dashboard. Es una lista blanca a propósito: estos
 * nombres se interpolan directamente en SQL (no se pueden parametrizar
 * nombres de columna con PDO), así que solo deben poder usarse los campos
 * de aquí, nunca lo que el usuario escriba libremente.
 * tipo 'numero' => admite Suma/Promedio. tipo 'texto' => admite Conteo de
 * coincidencias contra 'opciones' (catálogo de valores válidos).
 */
function catalogoCamposKpi(): array
{
    // 'opciones' siempre se normaliza a un mapa valor_guardado => etiqueta,
    // nunca a una lista simple, para que la validación del valor elegido
    // (más abajo, en admin/guardar_configuracion.php) sea inequívoca:
    // la CLAVE es siempre el valor real que hay que comparar en SQL.
    $mapa = fn(array $lista) => array_combine($lista, $lista);
    $si_no = ['1' => 'Sí', '0' => 'No'];

    return [
        // ---- Numéricos (Suma / Promedio) ----
        'familias'               => ['label' => 'Familias', 'tipo' => 'numero'],
        'hombres'                => ['label' => 'Hombres', 'tipo' => 'numero'],
        'mujeres'                => ['label' => 'Mujeres', 'tipo' => 'numero'],
        'ninos'                  => ['label' => 'Niños', 'tipo' => 'numero'],
        'adultos_tercera_edad'   => ['label' => 'Adultos de 3ra edad', 'tipo' => 'numero'],
        'gestantes'              => ['label' => 'Gestantes', 'tipo' => 'numero'],
        'movilidad_reducida'     => ['label' => 'Movilidad reducida', 'tipo' => 'numero'],
        'mascotas'               => ['label' => 'Mascotas', 'tipo' => 'numero'],
        'cantidad_apartamentos'  => ['label' => 'Cantidad de apartamentos', 'tipo' => 'numero'],
        'num_pisos'              => ['label' => 'N° de pisos', 'tipo' => 'numero'],
        'num_semisotanos'        => ['label' => 'N° de semisótanos', 'tipo' => 'numero'],
        'num_sotanos'            => ['label' => 'N° de sótanos', 'tipo' => 'numero'],
        'numero_personas'        => ['label' => 'N° de personas (general)', 'tipo' => 'numero'],
        'anio_construccion'      => ['label' => 'Año de construcción', 'tipo' => 'numero'],
        'm2_losas'               => ['label' => 'm² de losas afectadas', 'tipo' => 'numero'],
        'muros_reconstruir'      => ['label' => 'Muros a reconstruir', 'tipo' => 'numero'],
        'pct_dano_iii'           => ['label' => '% Daño III (Moderado)', 'tipo' => 'numero'],
        'pct_dano_iv'            => ['label' => '% Daño IV (Severo)', 'tipo' => 'numero'],
        'pct_dano_v'             => ['label' => '% Daño V (Completo)', 'tipo' => 'numero'],

        // ---- De categoría (Conteo de coincidencias contra un valor) ----
        'decision_final'                => ['label' => 'Decisión final', 'tipo' => 'texto', 'opciones' => $mapa(array_keys(catalogoDecisionFinal()))],
        'riesgo_externo'                 => ['label' => 'Riesgo Externo', 'tipo' => 'texto', 'opciones' => $mapa(array_keys(catalogoNivelRiesgo()))],
        'riesgo_estructural_severo'      => ['label' => 'Riesgo Estructural (Severo/Completo)', 'tipo' => 'texto', 'opciones' => $mapa(array_keys(catalogoRiesgoSevero()))],
        'riesgo_estructural_moderado'    => ['label' => 'Riesgo Estructural (Moderado)', 'tipo' => 'texto', 'opciones' => $mapa(array_keys(catalogoNivelRiesgo()))],
        'riesgo_componentes'            => ['label' => 'Riesgo de Componentes', 'tipo' => 'texto', 'opciones' => $mapa(array_keys(catalogoNivelRiesgo()))],
        'colapso_estructura'            => ['label' => 'Colapso de la estructura', 'tipo' => 'texto', 'opciones' => $mapa(['No', 'Parcial', 'Total'])],
        'requiere_inspeccion_interna'   => ['label' => '¿Requiere inspección interna?', 'tipo' => 'texto', 'opciones' => $mapa(['Si', 'No'])],
        'requiere_intervencion'          => ['label' => '¿Requiere intervención?', 'tipo' => 'texto', 'opciones' => $mapa(['Si', 'No'])],
        'acceso_miembros_estructurales' => ['label' => 'Acceso a miembros estructurales', 'tipo' => 'texto', 'opciones' => $mapa(catalogoAccesoMiembros())],
        'uso_edificacion'                => ['label' => 'Uso de la edificación', 'tipo' => 'texto', 'opciones' => $mapa(catalogoUsoEdificacion())],
        'tipo_estructural'               => ['label' => 'Tipo estructural', 'tipo' => 'texto', 'opciones' => $mapa(catalogoTipoEstructural())],
        'parroquia'                      => ['label' => 'Parroquia', 'tipo' => 'texto', 'opciones' => $mapa(catalogoParroquias())],
        'material_concreto'             => ['label' => 'Material: Concreto', 'tipo' => 'texto', 'opciones' => $si_no],
        'material_acero'                 => ['label' => 'Material: Acero', 'tipo' => 'texto', 'opciones' => $si_no],
        'material_mamposteria'          => ['label' => 'Material: Mampostería (general)', 'tipo' => 'texto', 'opciones' => $si_no],
        'mamposteria_formal'             => ['label' => 'Material: Mampostería formal', 'tipo' => 'texto', 'opciones' => $si_no],
        'mamposteria_informal'          => ['label' => 'Material: Mampostería informal', 'tipo' => 'texto', 'opciones' => $si_no],
        'material_otros'                 => ['label' => 'Material: Otros', 'tipo' => 'texto', 'opciones' => $si_no],
    ];
}

/** KPIs personalizados guardados (ya fusionados con defaults y ordenados). */
function obtenerConfigKpisCustom(): array
{
    $guardado = obtenerConfigValor('dashboard_kpis_custom');
    $lista = is_array($guardado) ? $guardado : [];
    usort($lista, fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
    return $lista;
}

/** Widgets configurables del dashboard: id => etiqueta descriptiva. */
function catalogoWidgetsDashboard(): array
{
    return [
        'kpi_inspecciones' => 'Tarjeta grande — Inspecciones realizadas',
        'kpi_personas'     => 'Tarjeta grande — Personas afectadas',
        'kpi_grid'         => 'Cuadrícula de mini-tarjetas (familias, hombres, mujeres, niños, etc.)',
        'kpis_custom'      => 'Cuadrícula de KPIs personalizados (definidos abajo)',
        'chart_decision'   => 'Gráfico — Estado de acceso a la edificación',
        'mapa'             => 'Mapa geográfico por parroquia',
        'chart_parroquia'  => 'Gráfico — Inspecciones por parroquia',
    ];
}



/**
 * Devuelve la lista de widgets del dashboard ya fusionada con los valores
 * guardados, ordenada por "orden". Cada elemento:
 * ['id','label','visible','orden','color','color2','gradiente']
 */
function obtenerConfigDashboard(): array
{
    $labels = catalogoWidgetsDashboard();
    $defaults = [];
    $i = 1;
    foreach ($labels as $id => $label) {
        $defaults[$id] = ['id' => $id, 'visible' => true, 'orden' => $i++, 'color' => null, 'color2' => null, 'gradiente' => false];
    }

    $guardado = obtenerConfigValor('dashboard_widgets');
    if (is_array($guardado)) {
        foreach ($guardado as $w) {
            if (!empty($w['id']) && isset($defaults[$w['id']])) {
                $defaults[$w['id']] = array_merge($defaults[$w['id']], $w);
            }
        }
    }

    foreach ($defaults as $id => &$w) {
        $w['label'] = $labels[$id];
    }
    unset($w);

    $lista = array_values($defaults);
    usort($lista, fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
    return $lista;
}

/**
 * Estilo CSS inline (background + color de texto) para un widget del
 * dashboard, a partir de su configuración de color/degradado. Devuelve
 * cadena vacía si no hay personalización (se usa el color por defecto del CSS).
 */
function estiloWidgetDashboard(array $widget): string
{
    if (empty($widget['color'])) {
        return '';
    }
    if (!empty($widget['gradiente']) && !empty($widget['color2'])) {
        $bg = 'linear-gradient(135deg, ' . $widget['color'] . ', ' . $widget['color2'] . ')';
    } else {
        $bg = $widget['color'];
    }
    return 'background:' . $bg . ';color:#fff;';
}
